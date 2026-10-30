"""HTTP API and uploader UI.

Run:  uvicorn --factory uploader.app:create_app --host 127.0.0.1 --port 8000

Routes
    GET  /uploader/            modal UI, loaded in an iframe by the SDK
    GET  /sdk/unit-uploader.js SDK for the parent application
    GET  /api/v1/session       token check + limits for the UI     (Bearer token)
    POST /api/v1/files         upload one file, raw body           (Bearer token)
                               201 + link, or 202 + job id for videos
    GET  /api/v1/jobs/<id>     progress / result of a video job    (Bearer token)
    GET  /s/<client>/<...>     "signed" files: ?exp=&sig= link, or a read token
    GET  /p/<client>/<...>     "private" files: read token only (CORS for the client's origins)
         (<client>.<site>/ for files of a white-label site: they open only on that site)
                               after the check nginx sends the bytes (X-Accel-Redirect)
    GET  /f/<hash path>        public files, only if UPLOADER_SERVE_FILES=1 (dev);
                               in production nginx serves them, see deploy/
    GET  /healthz
"""

import asyncio
import logging
import os
import re
import tempfile
import time
from pathlib import Path
from urllib.parse import quote, unquote

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
from .tokens import (PROTECTED_KEY_RE, TokenError, receipt_for, sign_link, verify_link,
                     verify_token)
from .validators import (ALIASES, FILE_TYPES, ValidationError, extension_of, new_context,
                         process_video, sanitize)
from .validators.images import make_variants
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
PROTECTED_HEADERS = {
    "Content-Security-Policy": "default-src 'none'; sandbox",
    "Cache-Control": "private, no-store",
    "X-Robots-Tag": "noindex, nofollow",
    "Vary": "Origin",
}
EXPOSED_HEADERS = "Content-Disposition, Content-Length, Content-Range, Content-Type"


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
    storage = Storage(settings.storage_dir, settings.protected_dir, settings.hash_key)
    scanner = ClamdScanner(settings.clamd_address) if settings.clamd_address else None
    limiter = RateLimiter(settings.rate_limit_per_minute)
    poll_limiter = RateLimiter(settings.rate_limit_per_minute * 4)
    read_limiter = RateLimiter(settings.rate_limit_per_minute * 4)
    usage = TokenUsage()
    cpu_slots = asyncio.Semaphore(settings.max_concurrent_jobs)
    video_jobs = JobQueue(settings.video_workers, settings.video_queue)
    settings.tmp_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    _clean_tmp_dir(settings.tmp_dir)

    ui_csp = (
        "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob:; "
        "media-src blob:; connect-src 'self'; base-uri 'none'; form-action 'none'; "
        "frame-ancestors {}"
    )
    ui_assets = {
        "index.html": ("text/html; charset=utf-8", STATIC_DIR / "uploader" / "index.html"),
        "i18n.js": ("text/javascript; charset=utf-8", STATIC_DIR / "uploader" / "i18n.js"),
        "uploader.js": ("text/javascript; charset=utf-8", STATIC_DIR / "uploader" / "uploader.js"),
        "uploader.css": ("text/css; charset=utf-8", STATIC_DIR / "uploader" / "uploader.css"),
    }
    sdk_path = STATIC_DIR / "sdk" / "unit-uploader.js"

    def authenticate(request, scope="upload"):
        header = request.headers.get("authorization", "")
        scheme, _, token = header.partition(" ")
        try:
            if scheme.lower() != "bearer" or not token:
                raise TokenError("invalid_token", "Missing bearer token")
            return verify_token(
                token.strip(), settings.clients, scope=scope,
                max_ttl=settings.token_max_ttl, max_files_cap=settings.max_files_per_token,
            )
        except TokenError as exc:
            # The browser only gets a short code; the reason is here, for whoever sets things up.
            log.info("token rejected ip=%s path=%s code=%s reason=%s", client_ip(request),
                     request.url.path, exc.code, exc.detail or exc.message)
            raise

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

    def layout_of(claims, category):
        """Folder + sizes, which take part in an image's name ("" = no size folders).
        Only images get size folders (orig, small, ...): documents, archives and
        videos go straight into the folder."""
        if category != "image" or not (claims.folder or claims.sizes):
            return ""
        return claims.folder + "|" + ";".join(f"{n}-{w}-{h}-{m}" for n, w, h, m in claims.sizes)

    def store(claims, digest, ext, items, sized):
        """Write (size name, bytes or temp file path, dimensions) items; returns stored entries.
        With `sized` each item goes into its size folder (<folder>/<size>/...)."""
        stored = []
        for size_name, payload, dims in items:
            key = storage.key_for(digest, ext, claims.access, claims.client_id, claims.folder,
                                  size_name if sized else "", claims.path, claims.site)
            if isinstance(payload, bytes):
                storage.put(key, payload)
            else:
                storage.put_file(key, payload)
            stored.append((size_name, key, dims))
        return stored

    def file_ref(key, claims):
        """Key, URL and receipt for one stored file (or one size of an image)."""
        client = settings.clients[claims.client_id]
        # The receipt lets the app's backend check the key really was uploaded by this user.
        ref = {"key": key, "receipt": receipt_for(key, claims.subject, client.secret)}
        # A white-label client or site may serve files from its own domain.
        public_base = client.option(claims.site, "cdn_base_url") or settings.cdn_base_url
        protected_base = client.option(claims.site, "protected_base_url") or settings.protected_base_url
        if claims.access == "public":
            ref["url"] = f"{public_base}/{key[2:]}"
        elif claims.access == "signed":
            ref["url"] = sign_link(key, claims.client_id, client.secret, protected_base,
                                   ttl=settings.preview_link_ttl)
            ref["expires_at"] = int(time.time()) + settings.preview_link_ttl
        else:
            ref["url"] = f"{protected_base}/{key}"  # opens only with a read token
        return ref

    def describe_file(stored, digest, claims, name, ext, mime, category, size, original_size):
        """What the uploader hands to the application for one upload."""
        _, key, dims = stored[0]
        body = {
            **file_ref(key, claims), "access": claims.access, "hash": digest, "name": name,
            "ext": ext, "mime": mime, "category": category, "size": size,
            "original_size": original_size,
        }
        if claims.folder:
            body["folder"] = claims.folder
        if dims:
            body["width"], body["height"] = dims
        if category == "image" and claims.sizes:
            body["variants"] = {
                size_name: {**file_ref(k, claims), "width": d[0], "height": d[1]}
                for size_name, k, d in stored
            }
        return body

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
            "access": claims.access,
        }, headers=API_HEADERS)

    def process(name, data, claims):
        """Blocking part of a regular upload: validate, scan, store. Runs in a thread."""
        result = sanitize(name, data, new_context(settings.limits), categories=claims.types)
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
        items = [("orig", result.data, None)]
        if result.category == "image":
            # Sizes are made from the sanitized image, never from the upload itself.
            dims, variants = make_variants(result.data, result.ext, claims.sizes, settings.limits)
            items = [("orig", result.data, dims)] + [(n, d, (w, h)) for n, d, w, h in variants]
        layout = layout_of(claims, result.category)
        digest = storage.digest(result.data, claims.access, claims.client_id, layout, claims.site)
        return result, digest, store(claims, digest, result.ext, items, sized=bool(layout))

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
            layout = layout_of(claims, "video")
            digest = storage.digest_file(out, claims.access, claims.client_id, layout, claims.site)
            stored = store(claims, digest, out_ext, [("orig", out, None)], sized=bool(layout))
        except BaseException as exc:
            usage.release(claims.token_id)
            code = getattr(exc, "code", type(exc).__name__)
            log.info("rejected client=%s sub=%s ip=%s name=%r code=%s", claims.client_id,
                     claims.subject, ip, name, code)
            raise
        finally:
            _unlink(src)
            _unlink(out)
        log.info("stored client=%s sub=%s ip=%s name=%r key=%s size=%d->%d",
                 claims.client_id, claims.subject, ip, name, stored[0][1], size, stored_size)
        return describe_file(stored, digest, claims, name, out_ext, mime, "video", stored_size, size)

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
                result, digest, stored = await run_in_threadpool(process, name, data, claims)
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

        log.info("stored client=%s sub=%s ip=%s name=%r key=%s sizes=%d size=%d->%d",
                 claims.client_id, claims.subject, ip, name, stored[0][1], len(stored),
                 len(data), len(result.data))
        return JSONResponse(
            describe_file(stored, digest, claims, name, result.ext, result.mime, result.category,
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
        # Only the site that opens the dialog may frame it (the SDK passes its
        # origin). Listing every registered site here would publish the list
        # of white-label partners and grow with each of them.
        parent = request.query_params.get("origin", "")
        ancestors = parent.lower() if settings.clients.allows_any(parent) else "'none'"
        if ancestors == "'none'" and path.name == "index.html":
            log.warning("uploader page asked for by %.200r, which no client lists in origins: "
                        "the browser will refuse to show it (frame-ancestors 'none')",
                        parent or "a page without ?origin= (SDK older than 1.3.0?)")
        headers = {"Content-Security-Policy": ui_csp.format(ancestors), "Cache-Control": "no-cache"}
        return FileResponse(path, media_type=mime, headers=headers)

    async def ui_redirect(request):
        return RedirectResponse("/uploader/", status_code=308)

    async def sdk(request):
        return FileResponse(sdk_path, media_type="text/javascript; charset=utf-8", headers={
            "Cross-Origin-Resource-Policy": "cross-origin",
            "Access-Control-Allow-Origin": "*",
            "Cache-Control": "public, max-age=300",
        })

    def may_read(client, origin, key_site):
        """Pages of one white-label site never get another site's files; the
        client's own origins (outside "sites") get all of the client's files."""
        if not client.allows(origin):
            return False
        site = client.site_of(origin)
        return site is None or site.id == key_site

    def cors(request, client, key_site, only=None):
        origin = request.headers.get("origin")
        if origin and may_read(client, origin, key_site) and (only is None or origin == only):
            return {"Access-Control-Allow-Origin": origin, "Access-Control-Expose-Headers": EXPOSED_HEADERS,
                    "Vary": "Origin"}
        return {"Vary": "Origin"}

    def disposition(category, filename):
        kind = "inline" if category in INLINE_CATEGORIES else "attachment"
        fallback = re.sub(r"[^A-Za-z0-9._-]", "_", filename)
        return f"{kind}; filename=\"{fallback}\"; filename*=UTF-8''{quote(filename, safe='')}"

    async def protected_file(request):
        """Serve a "signed" or "private" file after checking the link or read token."""
        ip = client_ip(request)
        key = request.url.path.lstrip("/")
        match = PROTECTED_KEY_RE.match(key)
        client = settings.clients.get(match.group(2)) if match else None
        if client is None:
            return error("not_found", "Not found", 404)
        key_site = match.group(3) or ""
        if request.method == "OPTIONS":
            origin = request.headers.get("origin")
            if not may_read(client, origin, key_site):
                return Response(status_code=403, headers={"Vary": "Origin"})
            return Response(status_code=204, headers={
                "Access-Control-Allow-Origin": origin, "Access-Control-Allow-Methods": "GET, HEAD",
                "Access-Control-Allow-Headers": "Authorization, Range", "Access-Control-Max-Age": "600",
                "Vary": "Origin"})
        if not read_limiter.allow(f"ip:{ip}"):
            return rate_limited(read_limiter, f"ip:{ip}")

        # Authorize first, look at the disk second: no probing which files exist.
        name, via = "", "token"
        if match.group(1) == "s" and "sig" in request.query_params:
            via = "link"
            try:
                name = verify_link(key, client, request.query_params, ip, max_ttl=settings.link_max_ttl)
            except TokenError as exc:
                return error(exc.code, exc.message, 403)
            headers = cors(request, client, key_site)
        else:
            headers = cors(request, client, key_site)
            try:
                claims = authenticate(request, scope="read")
            except TokenError as exc:
                response = token_error(exc)
                response.headers.update(headers)
                return response
            origin = request.headers.get("origin")
            # Even a token that lists the key opens it only on the site it was uploaded on.
            if (claims.client_id != match.group(2) or key not in claims.files
                    or (origin and origin != claims.origin)
                    or not may_read(client, claims.origin, key_site)):
                response = error("not_allowed", "This token does not grant access to the file", 403)
                response.headers.update(headers)
                return response
            headers = cors(request, client, key_site, only=claims.origin)

        path = storage.resolve(key)
        if path is None:
            response = error("not_found", "Not found", 404)
            response.headers.update(headers)
            return response
        ftype = FILE_TYPES[extension_of(path.name)]
        headers.update(PROTECTED_HEADERS)
        headers["Cross-Origin-Resource-Policy"] = client.option(key_site, "corp") or settings.protected_corp
        headers["Content-Disposition"] = disposition(
            ftype.category, clean_display_name(name) if name else path.name)
        log.info("read key=%s ip=%s via=%s", key, ip, via)
        if settings.accel_redirect:
            # nginx streams the bytes (ranges, sendfile) from an internal location.
            headers["X-Accel-Redirect"] = settings.accel_redirect + key
            return Response(status_code=200, media_type=ftype.mime, headers=headers)
        return FileResponse(path, media_type=ftype.mime, headers=headers)

    async def stored_file(request):
        path = storage.resolve("f/" + request.path_params["path"])
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
        Route("/s/{rest:path}", protected_file, methods=["GET", "HEAD", "OPTIONS"]),
        Route("/p/{rest:path}", protected_file, methods=["GET", "HEAD", "OPTIONS"]),
    ]
    if settings.serve_files:
        routes.append(Route("/f/{path:path}", stored_file, methods=["GET"]))

    return Starlette(routes=routes, middleware=[Middleware(SecurityHeadersMiddleware)])
