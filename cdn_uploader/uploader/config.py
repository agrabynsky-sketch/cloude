"""Settings, read from environment variables (see .env.example)."""

import json
import logging
import os
import re
import shutil
import threading
import time
from dataclasses import dataclass
from functools import cached_property
from pathlib import Path
from typing import Optional
from urllib.parse import urlsplit

from .tokens import ACCESS_LEVELS, CLIENT_ID_RE, ORIGIN_RE, SITE_ID_RE
from .validators import Limits, VideoSettings
from .validators.common import MB


log = logging.getLogger("uploader")

# https://*.partner.travel matches any subdomain (not partner.travel itself).
ORIGIN_PATTERN_RE = re.compile(r"^(https?)://\*\.([a-z0-9-]+(?:\.[a-z0-9-]+)+)(:\d{1,5})?$")
CORP_VALUES = ("same-site", "same-origin", "cross-origin")


class ConfigError(Exception):
    pass


# Settings a site may override; "" = take the client's value.
SITE_OPTIONS = ("default_access", "corp", "cdn_base_url", "protected_base_url")


@dataclass(frozen=True)
class Site:
    """One white-label site of a shared client: its domains, and optionally its
    own file domain. Protected files uploaded on it open only on it."""
    id: str
    origins: tuple
    default_access: str = ""
    corp: str = ""
    cdn_base_url: str = ""
    protected_base_url: str = ""


@dataclass(frozen=True)
class Client:
    secret: str
    origins: tuple                   # exact origins and https://*.example.com patterns
    default_access: str = "public"   # used when an upload token has no "access"
    corp: str = ""                   # Cross-Origin-Resource-Policy of protected files ("" = default)
    cdn_base_url: str = ""           # own domain for public files, e.g. https://files.partner.com/f
    protected_base_url: str = ""     # own domain for signed/private files
    sites: tuple = ()                # Site, for one client shared by many white-label sites

    @cached_property
    def _index(self):
        exact, patterns = {}, []
        for site in (None,) + self.sites:
            for origin in (self.origins if site is None else site.origins):
                m = ORIGIN_PATTERN_RE.match(origin)
                if m:
                    patterns.append((m.group(1), m.group(2), m.group(3) or "", site))
                else:
                    exact[origin] = site
        patterns.sort(key=lambda p: -len(p[1]))  # the most specific pattern wins
        return exact, patterns

    def _owner(self, origin):
        """(allowed, site) for `origin`; site None = the client's own origins."""
        if not isinstance(origin, str) or not ORIGIN_RE.match(origin):
            return False, None
        origin = origin.lower()
        exact, patterns = self._index
        if origin in exact:
            return True, exact[origin]
        scheme, _, rest = origin.partition("://")
        host, _, port = rest.partition(":")
        for p_scheme, domain, p_port, site in patterns:
            if p_scheme == scheme and p_port == (":" + port if port else "") and host.endswith("." + domain):
                return True, site
        return False, None

    def allows(self, origin):
        """Is `origin` one of this client's sites (exactly or through a *. pattern)?"""
        return self._owner(origin)[0]

    def site_of(self, origin):
        """The Site `origin` belongs to, or None (the client's own origins)."""
        return self._owner(origin)[1]

    def option(self, site_id, name):
        """A setting for files of `site_id`: the site's own value, else the client's."""
        for site in self.sites:
            if site.id == site_id and getattr(site, name):
                return getattr(site, name)
        return getattr(self, name)


class ClientRegistry:
    """The configured clients. With UPLOADER_CLIENTS_FILE the file is re-read
    when it changes, so white-label domains can be added without a restart;
    a broken file is logged and the previous clients stay in use."""

    def __init__(self, env, check_every=5.0):
        self._env = env
        self._path = env.get("UPLOADER_CLIENTS_FILE") or None
        self._check_every = check_every
        self._lock = threading.Lock()
        self._mtime = self._stat()
        self._clients = _load_clients(env)
        self._checked = time.monotonic()

    def _stat(self):
        try:
            return os.stat(self._path).st_mtime_ns if self._path else None
        except OSError:
            return None

    def _current(self):
        if self._path and time.monotonic() - self._checked >= self._check_every:
            with self._lock:
                self._checked = time.monotonic()
                mtime = self._stat()
                if mtime != self._mtime:
                    try:
                        self._clients = _load_clients(self._env)
                        log.info("reloaded %d clients from %s", len(self._clients), self._path)
                    except (ConfigError, OSError, ValueError) as exc:
                        log.error("keeping previous clients, %s is invalid: %s", self._path, exc)
                    self._mtime = mtime
        return self._clients

    def get(self, client_id, default=None):
        return self._current().get(client_id, default)

    def __getitem__(self, client_id):
        return self._current()[client_id]

    def values(self):
        return self._current().values()

    def allows_any(self, origin):
        return any(c.allows(origin) for c in self._current().values())


@dataclass(frozen=True)
class Settings:
    clients: ClientRegistry
    hash_key: bytes
    storage_dir: Path
    cdn_base_url: str
    limits: Limits
    tmp_dir: Path
    protected_dir: Path = Path("protected")      # files with access "signed"/"private"
    protected_base_url: str = ""                 # https://cdn.unit.travel (keys are appended)
    link_max_ttl: int = 86400                    # longest lifetime a signed link may have
    preview_link_ttl: int = 600                  # signed link returned right after upload
    accel_redirect: str = ""                     # e.g. /_protected/ : nginx sends the bytes
    protected_corp: str = "same-site"            # Cross-Origin-Resource-Policy for them
    video: Optional[VideoSettings] = None   # None = videos disabled
    video_workers: int = 1
    video_queue: int = 20
    token_max_ttl: int = 3600
    max_files_per_token: int = 200
    rate_limit_per_minute: int = 300
    max_concurrent_jobs: int = 4
    clamd_address: str = ""
    clamd_required: bool = True
    serve_files: bool = False


def _int(env, name, default):
    raw = env.get(name)
    if raw in (None, ""):
        return default
    try:
        return int(raw)
    except ValueError:
        raise ConfigError(f"{name} must be an integer") from None


def _bool(env, name, default):
    raw = env.get(name)
    if raw in (None, ""):
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


def _origins(spec, where):
    origins = spec.get("origins", [])
    if not isinstance(origins, list) or any(
            not isinstance(o, str) or not (ORIGIN_RE.match(o) or ORIGIN_PATTERN_RE.match(o.lower()))
            for o in origins):
        raise ConfigError(f"{where}: origins must be like https://app.example.com or https://*.example.com")
    return tuple(o.lower() for o in origins)


def _options(spec, where):
    """default_access, corp and the base URLs, validated; missing ones are ""."""
    out = {name: spec.get(name, "") for name in SITE_OPTIONS}
    if not all(isinstance(v, str) for v in out.values()):
        raise ConfigError(f"{where}: {', '.join(SITE_OPTIONS)} must be strings")
    if out["default_access"] and out["default_access"] not in ACCESS_LEVELS:
        raise ConfigError(f"{where}: default_access must be one of {ACCESS_LEVELS}")
    if out["corp"] and out["corp"] not in CORP_VALUES:
        raise ConfigError(f"{where}: corp must be one of {CORP_VALUES}")
    for name in ("cdn_base_url", "protected_base_url"):
        out[name] = out[name].rstrip("/")
        if out[name] and not re.match(r"^https?://[a-z0-9.-]+(:\d{1,5})?(/[a-z0-9_./-]*)?$", out[name]):
            raise ConfigError(f"{where}: {name} must be like https://files.example.com/f")
    return out


def _load_client(cid, spec):
    if not CLIENT_ID_RE.match(cid):
        raise ConfigError(f"Client id {cid!r} must match {CLIENT_ID_RE.pattern}")
    if not isinstance(spec, dict):
        raise ConfigError(f"Client {cid!r} must be a JSON object")
    secret = spec.get("secret", "")
    if not isinstance(secret, str) or len(secret) < 32:
        raise ConfigError(f"Client {cid!r}: secret must be at least 32 characters")
    where = f"Client {cid!r}"
    origins, options = _origins(spec, where), _options(spec, where)
    sites_spec = spec.get("sites", {})
    if not isinstance(sites_spec, dict):
        raise ConfigError(f"{where}: sites must be a JSON object")
    sites = []
    for sid, site_spec in sites_spec.items():
        site_where = f"{where}, site {sid!r}"
        if not isinstance(sid, str) or not SITE_ID_RE.match(sid):
            raise ConfigError(f"{site_where}: site id must match {SITE_ID_RE.pattern}")
        if not isinstance(site_spec, dict):
            raise ConfigError(f"{site_where} must be a JSON object")
        site_origins = _origins(site_spec, site_where)
        if not site_origins:
            raise ConfigError(f"{site_where}: origins are required")
        sites.append(Site(id=sid, origins=site_origins, **_options(site_spec, site_where)))
    every = list(origins) + [o for site in sites for o in site.origins]
    if not every:
        raise ConfigError(f"{where}: origins (or sites) are required")
    duplicates = sorted({o for o in every if every.count(o) > 1})
    if duplicates:
        # Each origin must belong to exactly one site, or files could open on the wrong one.
        raise ConfigError(f"{where}: {', '.join(duplicates)} listed more than once")
    options["default_access"] = options["default_access"] or "public"
    return Client(secret=secret, origins=origins, sites=tuple(sites), **options)


def _load_clients(env):
    raw = env.get("UPLOADER_CLIENTS")
    path = env.get("UPLOADER_CLIENTS_FILE")
    if path:
        raw = Path(path).read_text(encoding="utf-8")
    if not raw:
        raise ConfigError("Set UPLOADER_CLIENTS (JSON) or UPLOADER_CLIENTS_FILE")
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ConfigError(f"UPLOADER_CLIENTS is not valid JSON: {exc}") from None
    if not isinstance(data, dict):
        raise ConfigError("UPLOADER_CLIENTS must be a JSON object")
    return {cid: _load_client(cid, spec) for cid, spec in data.items()}


def _load_video(env):
    mode = env.get("UPLOADER_VIDEO_MODE", "transcode").strip().lower()
    if mode == "off":
        return None
    if mode not in ("transcode", "remux"):
        raise ConfigError("UPLOADER_VIDEO_MODE must be transcode, remux or off")
    video = VideoSettings(
        mode=mode,
        max_size=_int(env, "UPLOADER_MAX_VIDEO_MB", 500) * MB,
        max_seconds=_int(env, "UPLOADER_VIDEO_MAX_SECONDS", 600),
        output_max_side=_int(env, "UPLOADER_VIDEO_MAX_SIDE", 1920),
        threads=_int(env, "UPLOADER_VIDEO_THREADS", 4),
        timeout=_int(env, "UPLOADER_VIDEO_TIMEOUT", 900),
        memory=_int(env, "UPLOADER_VIDEO_MEMORY_MB", 4096) * MB,
        ffmpeg=env.get("UPLOADER_FFMPEG", "ffmpeg"),
        ffprobe=env.get("UPLOADER_FFPROBE", "ffprobe"),
    )
    for tool in (video.ffmpeg, video.ffprobe):
        if not shutil.which(tool):
            raise ConfigError(f"{tool} not found: install ffmpeg or set UPLOADER_VIDEO_MODE=off")
    return video


def load_settings(env=None):
    env = os.environ if env is None else env
    hash_key = env.get("UPLOADER_HASH_KEY", "")
    if len(hash_key) < 32:
        raise ConfigError("UPLOADER_HASH_KEY must be set to a random string of 32+ characters")
    max_file_mb = _int(env, "UPLOADER_MAX_FILE_MB", 50)
    limits = Limits(
        max_file_size=max_file_mb * MB,
        max_uncompressed=_int(env, "UPLOADER_MAX_UNCOMPRESSED_MB", 200) * MB,
        archive_max_entries=_int(env, "UPLOADER_ARCHIVE_MAX_ENTRIES", 1000),
        archive_max_nesting=_int(env, "UPLOADER_ARCHIVE_MAX_NESTING", 1),
        image_max_pixels=_int(env, "UPLOADER_IMAGE_MAX_PIXELS", 40_000_000),
    )
    storage_dir = Path(env.get("UPLOADER_STORAGE_DIR", "./data/f")).resolve()
    cdn_base_url = env.get("UPLOADER_CDN_BASE_URL", "https://cdn.unit.travel/f").rstrip("/")
    origin = urlsplit(cdn_base_url)
    corp = env.get("UPLOADER_PROTECTED_CORP", "same-site")
    if corp not in CORP_VALUES:
        raise ConfigError("UPLOADER_PROTECTED_CORP must be same-site, same-origin or cross-origin")
    link_max_ttl = _int(env, "UPLOADER_LINK_MAX_TTL", 86400)
    preview_link_ttl = _int(env, "UPLOADER_PREVIEW_LINK_TTL", 600)
    if not 0 < preview_link_ttl <= link_max_ttl:
        raise ConfigError("UPLOADER_PREVIEW_LINK_TTL must be between 1 and UPLOADER_LINK_MAX_TTL")
    accel = env.get("UPLOADER_ACCEL_REDIRECT", "")
    if accel and not (accel.startswith("/") and accel.endswith("/")):
        raise ConfigError("UPLOADER_ACCEL_REDIRECT must look like /_protected/")
    return Settings(
        clients=ClientRegistry(env, check_every=float(env.get("UPLOADER_CLIENTS_RELOAD_SECONDS", "5"))),
        hash_key=hash_key.encode("utf-8"),
        storage_dir=storage_dir,
        cdn_base_url=cdn_base_url,
        limits=limits,
        protected_dir=Path(env.get("UPLOADER_PROTECTED_DIR", storage_dir.parent / "protected")).resolve(),
        protected_base_url=env.get("UPLOADER_PROTECTED_BASE_URL",
                                   f"{origin.scheme}://{origin.netloc}").rstrip("/"),
        link_max_ttl=link_max_ttl,
        preview_link_ttl=preview_link_ttl,
        accel_redirect=accel,
        protected_corp=corp,
        # Next to the storage dir by default, so finished files are moved, not copied.
        tmp_dir=Path(env.get("UPLOADER_TMP_DIR", storage_dir.parent / "tmp")).resolve(),
        video=_load_video(env),
        video_workers=_int(env, "UPLOADER_VIDEO_WORKERS", 1),
        video_queue=_int(env, "UPLOADER_VIDEO_QUEUE", 20),
        token_max_ttl=_int(env, "UPLOADER_TOKEN_MAX_TTL", 3600),
        max_files_per_token=_int(env, "UPLOADER_MAX_FILES_PER_TOKEN", 200),
        rate_limit_per_minute=_int(env, "UPLOADER_RATE_LIMIT_PER_MINUTE", 300),
        max_concurrent_jobs=_int(env, "UPLOADER_MAX_CONCURRENT_JOBS", 4),
        clamd_address=env.get("UPLOADER_CLAMD", ""),
        clamd_required=_bool(env, "UPLOADER_CLAMD_REQUIRED", True),
        serve_files=_bool(env, "UPLOADER_SERVE_FILES", False),
    )
