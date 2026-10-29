"""Settings, read from environment variables (see .env.example)."""

import json
import os
from dataclasses import dataclass
from pathlib import Path

from .tokens import ORIGIN_RE
from .validators import Limits
from .validators.common import MB


class ConfigError(Exception):
    pass


@dataclass(frozen=True)
class Client:
    secret: str
    origins: tuple


@dataclass(frozen=True)
class Settings:
    clients: dict
    hash_key: bytes
    storage_dir: Path
    cdn_base_url: str
    limits: Limits
    token_max_ttl: int = 3600
    max_files_per_token: int = 50
    rate_limit_per_minute: int = 120
    max_concurrent_jobs: int = 4
    clamd_address: str = ""
    clamd_required: bool = True
    serve_files: bool = False

    @property
    def parent_origins(self):
        return sorted({o for c in self.clients.values() for o in c.origins})


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
    clients = {}
    for cid, spec in data.items():
        secret = spec.get("secret", "")
        origins = tuple(spec.get("origins", ()))
        if len(secret) < 32:
            raise ConfigError(f"Client {cid!r}: secret must be at least 32 characters")
        if not origins or any(not ORIGIN_RE.match(o) for o in origins):
            raise ConfigError(f"Client {cid!r}: origins must be like https://app.example.com")
        clients[cid] = Client(secret=secret, origins=origins)
    return clients


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
    return Settings(
        clients=_load_clients(env),
        hash_key=hash_key.encode("utf-8"),
        storage_dir=Path(env.get("UPLOADER_STORAGE_DIR", "./data/f")).resolve(),
        cdn_base_url=env.get("UPLOADER_CDN_BASE_URL", "https://cdn.unit.travel/f").rstrip("/"),
        limits=limits,
        token_max_ttl=_int(env, "UPLOADER_TOKEN_MAX_TTL", 3600),
        max_files_per_token=_int(env, "UPLOADER_MAX_FILES_PER_TOKEN", 50),
        rate_limit_per_minute=_int(env, "UPLOADER_RATE_LIMIT_PER_MINUTE", 120),
        max_concurrent_jobs=_int(env, "UPLOADER_MAX_CONCURRENT_JOBS", 4),
        clamd_address=env.get("UPLOADER_CLAMD", ""),
        clamd_required=_bool(env, "UPLOADER_CLAMD_REQUIRED", True),
        serve_files=_bool(env, "UPLOADER_SERVE_FILES", False),
    )
