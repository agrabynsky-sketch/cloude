"""HTTP API and uploader UI.

Run:  uvicorn --factory uploader.app:create_app --host 127.0.0.1 --port 8000

Routes
    GET  /uploader/            modal UI, loaded in an iframe by the SDK
    GET  /sdk/unit-uploader.js SDK for the parent application
    GET  /api/v1/session       token check + limits for the UI     (Bearer token)
    POST /api/v1/files         upload one file, raw body           (Bearer token)
                               201 + link, or 202 + job id for videos
    GET  /api/v1/jobs/<id>     progress / result of a video job    (Bearer token)
    GET  /f/<hash path>        stored files, only if UPLOADER_SERVE_FILES=1 (dev);
                               in production nginx serves them, see deploy/
    GET  /healthz
"""

import asyncio
import logging
import os
import tempfile
import time
from pathlib import Path
from urllib.parse import unquote

from starlette.applications import Starlette
from starlette.concurrency import run_in_threadpool
from starlette.middleware import Middleware
from starlette.requests import ClientDisconnect
from starlette.responses import FileResponse, JSONResponse, RedirectResponse, Response
from starlette.routing import Route

from .antivirus import AntivirusError, ClamdScanner
from .config import load_settings
from .jobs import JobQueue
from .ratelimit import RateLimiter, TokenUsage
from .storage import Storage
from .tokens import TokenError, verify_token
from .validators import (ALIASES, FILE_TYPES, ValidationError, extension_of, new_context,
                         process_video, sanitize)
from .validators.common import clean_display_name

log = logging.getLogger("uploader")
STATIC_DIR = Path(__file__).resolve().parent.parent / "static"

ERROR_STATUS = {"type_not_allowed": 415, "file_too_large": 413}
INLINE_CATEGORIES = {"image", "video"}

BASE_HEADERS = {
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
    "Permissions-Policy": "camera=(), microphone=(), geolocation=(), payment=(), usb=()",
}
API_HEADERS = {
    "Cache-Control": "no-store",
    "Content-Security-Policy": "default-src 'none'; frame-ancestors 'none'",
    "X-Frame-Options": "DENY",
    "Cross-Origin-Resource-Policy": "same-origin",
}
FILE_HEADERS = {
    "Content-Security-Policy": "default-src 'none'; sandbox",
    "Cross-Origin-Resource-Policy": "cross-origin",
    "Cache-Control": "public, max-age=31536000, immutable",
}


class SecurityHeadersMiddleware:
    """Adds headers every response must carry; route-specific ones are set per response."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            return await self.app(scope, receive, send)

        async def send_with_headers(message):
            if message["type"] == "http.response.start":
                existing = {k.lower() for k, _ in message.get("headers", [])}
                extra = [(k.lower().encode(), v.encode()) for k, v in BASE_HEADERS.items()
                         if k.lower().encode() not in existing]
                message["headers"] = list(message.get("headers", [])) + extra
            await send(message)

        await self.app(scope, receive, send_with_headers)


def client_ip(request):
    # uvicorn --proxy-headers rewrites this from X-Forwarded-For set by nginx.
    return request.client.host if request.client else "unknown"


def setup_logging():
    if log.handlers:
        return
    handler = logging.StreamHandler()
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(name)s %(message)s"))
    log.addHandler(handler)
    log.setLevel(logging.INFO)
    log.propagate = False


def error(code, message, status, retry_after=None):
    headers = dict(API_HEADERS)
    if retry_after:
        headers["Retry-After"] = str(retry_after)
    return JSONResponse({"error": code, "message": message}, status_code=status, headers=headers)


class BodyTooLarge(Exception):
    pass


def _check_declared_length(request, limit):
    declared = request.headers.get("content-length")
    if declared is not None and (not declared.isdigit() or int(declared) > limit):
        raise BodyTooLarge


async def read_body(request, limit):
    _check_declared_length(request, limit)
    body = bytearray()
    async for chunk in request.stream():
        body += chunk
        if len(body) > limit:
            raise BodyTooLarge
    return bytes(body)


async def read_body_to_file(request, limit, directory):
    """Stream the body to a private temp file (videos are too big for memory)."""
    _check_declared_length(request, limit)
    fd, path = tempfile.mkstemp(dir=directory, prefix="up-")
    size = 0
    try:
        with os.fdopen(fd, "wb") as fh:
            async for chunk in request.stream():
                size += len(chunk)
                if size > limit:
                    raise BodyTooLarge
                fh.write(chunk)
    except BaseException:
        _unlink(path)
        raise
    return path, size


def _unlink(path):
    if path:
        try:
            os.unlink(path)
        except FileNotFoundError:
            pass


def _clean_tmp_dir(directory, max_age=86400):
    """Remove leftovers of uploads interrupted by a restart."""
    now = time.time()
    for entry in os.scandir(directory):
        try:
            if entry.is_file(follow_symlinks=False) and now - entry.stat().st_mtime > max_age:
                os.unlink(entry.path)
        except OSError:
            pass


def create_app(settings=None):
    settings = settings or load_settings()
    setup_logging()
    storage = Storage(settings.storage_dir, settings.hash_key, settings.cdn_base_url)
    scanner = ClamdScanner(settings.clamd_address) if settings.clamd_address else None
    limiter = RateLimiter(settings.rate_limit_per_minute)
    poll_limiter = RateLimiter(settings.rate_limit_per_minute * 4)
    usage = TokenUsage()
    cpu_slots = asyncio.Semaphore(settings.max_concurrent_jobs)
    video_jobs = JobQueue(settings.video_workers, settings.video_queue)
    settings.tmp_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    _clean_tmp_dir(settings.tmp_dir)

    frame_ancestors = " ".join(settings.parent_origins)
    ui_csp = (
        "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob:; "
        "media-src blob:; connect-src 'self'; base-uri 'none'; form-action 'none'; "
        f"frame-ancestors {frame_ancestors}"
    )
    ui_assets = {
        "index.html": ("text/html; charset=utf-8", STATIC_DIR / "uploader" / "index.html"),
        "uploader.js": ("text/javascript; charset=utf-8", STATIC_DIR / "uploader" / "uploader.js"),
        "uploader.css": ("text/css; charset=utf-8", STATIC_DIR / "uploader" / "uploader.css"),
    }
    sdk_path = STATIC_DIR / "sdk" / "unit-uploader.js"

    def authenticate(request):
        header = request.headers.get("authorization", "")
        scheme, _, token = header.partition(" ")
        if scheme.lower() != "bearer" or not token:
            raise TokenError("invalid_token", "Missing bearer token")
        return verify_token(
            token.strip(), settings.clients,
            max_ttl=settings.token_max_ttl, max_files_cap=settings.max_files_per_token,
        )

    def token_error(exc):
        return error(exc.code, exc.message, 403 if exc.code == "origin_not_allowed" else 401)

    def enabled_types(claims):
        return [t for t in claims.types if t != "video" or settings.video is not None]

    def max_size(category):
        return settings.video.max_size if category == "video" else settings.limits.max_file_size

    def allowed_extensions(types):
        exts = {ext: t.category for ext, t in FILE_TYPES.items() if t.category in types}
        exts.update({alias: exts[target] for alias, target in ALIASES.items() if target in exts})
        return exts

    def owner_of(claims):
        # Jobs survive a token refresh: they belong to the client + user, not the token.
        return (claims.client_id, claims.subject, claims.origin)

    def public_file(rel, digest, name, ext, mime, category, size, original_size):
        return {
            "url": storage.url(rel), "hash": digest, "name": name, "ext": ext, "mime": mime,
            "category": category, "size": size, "original_size": original_size,
        }

    def rate_limited(rl, key):
        return error("rate_limited", "Too many requests", 429, retry_after=rl.retry_after(key))

    async def session(request):
        key = f"ip:{client_ip(request)}"
        if not limiter.allow(key):
            return rate_limited(limiter, key)
        try:
            claims = authenticate(request)
        except TokenError as exc:
            return token_error(exc)
        types = enabled_types(claims)
        return JSONResponse({
            "origin": claims.origin,
            "expires_at": claims.expires_at,
            "max_files": claims.max_files,
            "remaining": max(claims.max_files - usage.used(claims.token_id), 0),
            "max_file_size": settings.limits.max_file_size,
            "max_sizes": {t: max_size(t) for t in types},
            "types": types,
            "extensions": allowed_extensions(types),
        }, headers=API_HEADERS)

    def process(name, data, types):
        """Blocking part of a regular upload: validate, scan, store. Runs in a thread."""
        result = sanitize(name, data, new_context(settings.limits), categories=types)
        if scanner is not None:
            try:
                # The upload (intent) and what will actually be stored.
                signature = scanner.scan(data)
                if not signature and result.data != data:
                    signature = scanner.scan(result.data)
            except AntivirusError:
                if settings.clamd_required:
                    raise
                log.warning("antivirus unavailable, accepting %s without scan", name)
                signature = None
            if signature:
                raise ValidationError("malware_detected", f"File is infected ({signature})")
        digest, rel = storage.save(result.data, result.ext)
        return result, digest, rel

    def run_video(job, src, size, name, ext, claims, ip):
        """Background part of a video upload."""
        out = None
        try:
            out, out_ext, mime = process_video(
                src, ext, str(settings.tmp_dir), settings.video, progress=job.set_progress)
            # A transcoded file is produced by our encoder from decoded frames,
            # so scanning it adds nothing; a remuxed one keeps the original data.
            if scanner is not None and settings.video.mode == "remux":
                try:
                    signature = scanner.scan_file(out)
                except AntivirusError as exc:
                    if settings.clamd_required:
                        log.error("antivirus failure: %s", exc)
                        raise ValidationError("antivirus_unavailable", "File could not be scanned") from None
                    signature = None
                if signature:
                    raise ValidationError("malware_detected", f"File is infected ({signature})")
            stored_size = os.path.getsize(out)
            digest, rel = storage.save_file(out, out_ext)
        except BaseException as exc:
            usage.release(claims.token_id)
            code = getattr(exc, "code", type(exc).__name__)
            log.info("rejected client=%s sub=%s ip=%s name=%r code=%s", claims.client_id,
                     claims.subject, ip, name, code)
            raise
        finally:
            _unlink(src)
            _unlink(out)
        log.info("stored client=%s sub=%s ip=%s name=%r hash=%s ext=%s size=%d->%d",
                 claims.client_id, claims.subject, ip, name, digest, out_ext, size, stored_size)
        return public_file(rel, digest, name, out_ext, mime, "video", stored_size, size)

    async def upload(request):
        ip = client_ip(request)
        if not limiter.allow(f"ip:{ip}"):
            return rate_limited(limiter, f"ip:{ip}")
        try:
            claims = authenticate(request)
        except TokenError as exc:
            return token_error(exc)

        raw_name = request.headers.get("x-file-name", "")
        try:
            name = clean_display_name(unquote(raw_name[:1024], errors="strict"))
        except UnicodeDecodeError:
            return error("invalid_name", "X-File-Name must be UTF-8, percent-encoded", 400)
        ftype = FILE_TYPES.get(extension_of(name))
        if ftype is None or ftype.category not in enabled_types(claims):
            return error("type_not_allowed", "File type is not allowed", 415)
        if not usage.try_reserve(claims.token_id, claims.max_files, claims.expires_at):
            return error("file_limit_reached", "This upload session has no files left", 429)

        if ftype.category == "video":
            return await upload_video(request, claims, ip, name, ftype)

        try:
            data = await read_body(request, settings.limits.max_file_size)
            async with cpu_slots:
                result, digest, rel = await run_in_threadpool(process, name, data, claims.types)
        except BodyTooLarge:
            usage.release(claims.token_id)
            return error("file_too_large", "File is too large", 413)
        except ClientDisconnect:
            usage.release(claims.token_id)
            return error("client_disconnected", "Upload was interrupted", 400)
        except ValidationError as exc:
            usage.release(claims.token_id)
            log.info("rejected client=%s sub=%s ip=%s name=%r code=%s reason=%s",
                     claims.client_id, claims.subject, ip, name, exc.code, exc.message)
            return error(exc.code, exc.message, ERROR_STATUS.get(exc.code, 422))
        except AntivirusError as exc:
            usage.release(claims.token_id)
            log.error("antivirus failure: %s", exc)
            return error("antivirus_unavailable", "File could not be scanned, try again later", 503,
                         retry_after=30)
        except Exception:
            usage.release(claims.token_id)
            log.exception("upload failed name=%r", name)
            return error("internal_error", "Upload failed", 500)

        log.info("stored client=%s sub=%s ip=%s name=%r hash=%s ext=%s size=%d->%d",
                 claims.client_id, claims.subject, ip, name, digest, result.ext,
                 len(data), len(result.data))
        return JSONResponse(
            public_file(rel, digest, name, result.ext, result.mime, result.category,
                        len(result.data), len(data)),
            status_code=201, headers=API_HEADERS)

    async def upload_video(request, claims, ip, name, ftype):
        try:
            src, size = await read_body_to_file(request, settings.video.max_size, settings.tmp_dir)
        except BodyTooLarge:
            usage.release(claims.token_id)
            return error("file_too_large", "Video is too large", 413)
        except ClientDisconnect:
            usage.release(claims.token_id)
            return error("client_disconnected", "Upload was interrupted", 400)
        if size == 0:
            usage.release(claims.token_id)
            _unlink(src)
            return error("empty_file", "File is empty", 422)
        job = video_jobs.submit(
            owner_of(claims), lambda job: run_video(job, src, size, name, ftype.ext, claims, ip))
        if job is None:
            usage.release(claims.token_id)
            _unlink(src)
            return error("busy", "Too many videos are being processed, try again later", 503,
                         retry_after=30)
        return JSONResponse({**job.public(), "name": name}, status_code=202, headers=API_HEADERS)

    async def job_status(request):
        key = f"ip:{client_ip(request)}"
        if not poll_limiter.allow(key):
            return rate_limited(poll_limiter, key)
        try:
            claims = authenticate(request)
        except TokenError as exc:
            return token_error(exc)
        job = video_jobs.get(request.path_params["job_id"], owner_of(claims))
        if job is None:
            return error("not_found", "No such job", 404)
        return JSONResponse(job.public(), headers=API_HEADERS)

    async def ui_asset(request):
        asset = ui_assets.get(request.path_params.get("asset", "index.html"))
        if asset is None:
            return Response("Not found", status_code=404, media_type="text/plain")
        mime, path = asset
        headers = {"Content-Security-Policy": ui_csp, "Cache-Control": "no-cache"}
        return FileResponse(path, media_type=mime, headers=headers)

    async def ui_redirect(request):
        return RedirectResponse("/uploader/", status_code=308)

    async def sdk(request):
        return FileResponse(sdk_path, media_type="text/javascript; charset=utf-8", headers={
            "Cross-Origin-Resource-Policy": "cross-origin",
            "Access-Control-Allow-Origin": "*",
            "Cache-Control": "public, max-age=300",
        })

    async def stored_file(request):
        path = storage.resolve(request.path_params["path"])
        if path is None:
            return Response("Not found", status_code=404, media_type="text/plain")
        ext = extension_of(path.name)
        ftype = FILE_TYPES[ext]
        headers = dict(FILE_HEADERS)
        if ftype.category not in INLINE_CATEGORIES:
            headers["Content-Disposition"] = f'attachment; filename="{path.name}"'
        return FileResponse(path, media_type=ftype.mime, headers=headers)

    async def healthz(request):
        return JSONResponse({"ok": True}, headers=API_HEADERS)

    routes = [
        Route("/api/v1/session", session, methods=["GET"]),
        Route("/api/v1/files", upload, methods=["POST"]),
        Route("/api/v1/jobs/{job_id:str}", job_status, methods=["GET"]),
        Route("/uploader", ui_redirect, methods=["GET"]),
        Route("/uploader/", ui_asset, methods=["GET"]),
        Route("/uploader/{asset:str}", ui_asset, methods=["GET"]),
        Route("/sdk/unit-uploader.js", sdk, methods=["GET"]),
        Route("/healthz", healthz, methods=["GET"]),
    ]
    if settings.serve_files:
        routes.append(Route("/f/{path:path}", stored_file, methods=["GET"]))

    return Starlette(routes=routes, middleware=[Middleware(SecurityHeadersMiddleware)])
