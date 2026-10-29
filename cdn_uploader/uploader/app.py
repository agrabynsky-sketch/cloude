"""HTTP API and uploader UI.

Run:  uvicorn --factory uploader.app:create_app --host 127.0.0.1 --port 8000

Routes
    GET  /uploader/            modal UI, loaded in an iframe by the SDK
    GET  /sdk/unit-uploader.js SDK for the parent application
    GET  /api/v1/session       token check + limits for the UI     (Bearer token)
    POST /api/v1/files         upload one file, raw body           (Bearer token)
    GET  /f/<hash path>        stored files, only if UPLOADER_SERVE_FILES=1 (dev);
                               in production nginx serves them, see deploy/
    GET  /healthz
"""

import asyncio
import logging
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
from .ratelimit import RateLimiter, TokenUsage
from .storage import Storage
from .tokens import TokenError, verify_token
from .validators import ALIASES, FILE_TYPES, ValidationError, extension_of, new_context, sanitize
from .validators.common import clean_display_name

log = logging.getLogger("uploader")
STATIC_DIR = Path(__file__).resolve().parent.parent / "static"

ERROR_STATUS = {"type_not_allowed": 415, "file_too_large": 413}
INLINE_CATEGORIES = {"image"}

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


def error(code, message, status):
    return JSONResponse({"error": code, "message": message}, status_code=status, headers=API_HEADERS)


class BodyTooLarge(Exception):
    pass


async def read_body(request, limit):
    declared = request.headers.get("content-length")
    if declared is not None and (not declared.isdigit() or int(declared) > limit):
        raise BodyTooLarge
    body = bytearray()
    async for chunk in request.stream():
        body += chunk
        if len(body) > limit:
            raise BodyTooLarge
    return bytes(body)


def create_app(settings=None):
    settings = settings or load_settings()
    setup_logging()
    storage = Storage(settings.storage_dir, settings.hash_key, settings.cdn_base_url)
    scanner = ClamdScanner(settings.clamd_address) if settings.clamd_address else None
    limiter = RateLimiter(settings.rate_limit_per_minute)
    usage = TokenUsage()
    jobs = asyncio.Semaphore(settings.max_concurrent_jobs)

    frame_ancestors = " ".join(settings.parent_origins)
    ui_csp = (
        "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob:; "
        f"connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors {frame_ancestors}"
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

    def allowed_extensions(types):
        exts = {ext: t.category for ext, t in FILE_TYPES.items() if t.category in types}
        exts.update({alias: exts[target] for alias, target in ALIASES.items() if target in exts})
        return exts

    async def session(request):
        if not limiter.allow(f"ip:{client_ip(request)}"):
            return error("rate_limited", "Too many requests", 429)
        try:
            claims = authenticate(request)
        except TokenError as exc:
            return token_error(exc)
        return JSONResponse({
            "origin": claims.origin,
            "expires_at": claims.expires_at,
            "max_files": claims.max_files,
            "remaining": max(claims.max_files - usage.used(claims.token_id), 0),
            "max_file_size": settings.limits.max_file_size,
            "types": list(claims.types),
            "extensions": allowed_extensions(claims.types),
        }, headers=API_HEADERS)

    def process(name, data, types):
        """Blocking part of an upload: validate, scan, store. Runs in a thread."""
        result = sanitize(name, data, new_context(settings.limits), categories=types)
        if scanner is not None:
            try:
                signature = scanner.scan(data)
            except AntivirusError:
                if settings.clamd_required:
                    raise
                log.warning("antivirus unavailable, accepting %s without scan", name)
                signature = None
            if signature:
                raise ValidationError("malware_detected", f"File is infected ({signature})")
        digest, rel = storage.save(result.data, result.ext)
        return result, digest, rel

    async def upload(request):
        ip = client_ip(request)
        if not limiter.allow(f"ip:{ip}"):
            return error("rate_limited", "Too many requests", 429)
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
        if ftype is None or ftype.category not in claims.types:
            return error("type_not_allowed", "File type is not allowed", 415)
        if not usage.try_reserve(claims.token_id, claims.max_files, claims.expires_at):
            return error("file_limit_reached", "This upload session has no files left", 429)

        try:
            data = await read_body(request, settings.limits.max_file_size)
            async with jobs:
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
            return error("antivirus_unavailable", "File could not be scanned, try again later", 503)
        except Exception:
            usage.release(claims.token_id)
            log.exception("upload failed name=%r", name)
            return error("internal_error", "Upload failed", 500)

        log.info("stored client=%s sub=%s ip=%s name=%r hash=%s ext=%s size=%d->%d",
                 claims.client_id, claims.subject, ip, name, digest, result.ext,
                 len(data), len(result.data))
        return JSONResponse({
            "url": storage.url(rel),
            "hash": digest,
            "name": name,
            "ext": result.ext,
            "mime": result.mime,
            "category": result.category,
            "size": len(result.data),
            "original_size": len(data),
        }, status_code=201, headers=API_HEADERS)

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
        Route("/uploader", ui_redirect, methods=["GET"]),
        Route("/uploader/", ui_asset, methods=["GET"]),
        Route("/uploader/{asset:str}", ui_asset, methods=["GET"]),
        Route("/sdk/unit-uploader.js", sdk, methods=["GET"]),
        Route("/healthz", healthz, methods=["GET"]),
    ]
    if settings.serve_files:
        routes.append(Route("/f/{path:path}", stored_file, methods=["GET"]))

    return Starlette(routes=routes, middleware=[Middleware(SecurityHeadersMiddleware)])
