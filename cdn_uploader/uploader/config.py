"""Settings, read from environment variables (see .env.example)."""

import json
import os
import shutil
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from .tokens import ORIGIN_RE
from .validators import Limits, VideoSettings
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
    tmp_dir: Path
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
    return Settings(
        clients=_load_clients(env),
        hash_key=hash_key.encode("utf-8"),
        storage_dir=storage_dir,
        cdn_base_url=env.get("UPLOADER_CDN_BASE_URL", "https://cdn.unit.travel/f").rstrip("/"),
        limits=limits,
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
