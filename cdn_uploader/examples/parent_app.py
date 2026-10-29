"""Demo "parent application" that opens the uploader in a modal.

It plays the role of your real app's backend: mints upload tokens, keeps
the keys of uploaded files (after checking their receipts), and later
hands out temporary links / read tokens only for files the user owns.

    DEMO_CLIENT_SECRET=... uvicorn examples.parent_app:app --port 8001

then open http://localhost:8001.
"""

import html
import os
from pathlib import Path

from starlette.applications import Starlette
from starlette.responses import HTMLResponse, JSONResponse
from starlette.routing import Route

from uploader.tokens import create_read_token, create_token, sign_link, verify_receipt

CLIENT_ID = os.environ.get("DEMO_CLIENT_ID", "demo")
CLIENT_SECRET = os.environ.get("DEMO_CLIENT_SECRET", "")
ORIGIN = os.environ.get("DEMO_ORIGIN", "http://localhost:8001")
UPLOADER_URL = os.environ.get("DEMO_UPLOADER_URL", "http://localhost:8000").rstrip("/")
TOKEN_TTL = int(os.environ.get("DEMO_TOKEN_TTL", "900"))
PAGE = Path(__file__).with_name("parent.html").read_text(encoding="utf-8")

# A real app has users and a database; the demo has one user and a set.
USER_ID = "demo-user"
OWNED_KEYS = set()

# Where uploads go and which image sizes are made is decided here, on the
# server (it ends up in the signed token), never by the browser.
PRESETS = {
    "rooms": {"folder": "photos/rooms",
              "sizes": ["preview_panel-100-100", "small-70-0", "medium-350-0", "big-1200-800"]},
}


async def index(request):
    return HTMLResponse(PAGE.replace("{{UPLOADER_URL}}", html.escape(UPLOADER_URL)))


async def upload_token(request):
    # In a real app: check the user's session/CSRF here and decide what this
    # user may upload and how private it must be (passports -> "private").
    access = request.query_params.get("access", "public")
    preset = dict(PRESETS.get(request.query_params.get("preset", ""), {}))
    if access not in ("public", "signed", "private"):
        return JSONResponse({"error": "bad access"}, status_code=400)
    entity = request.query_params.get("id", "")
    if preset and entity:
        # Also check here that the user may edit this entity (room 10991).
        if not entity.isdigit() or len(entity) > 12:
            return JSONResponse({"error": "bad id"}, status_code=400)
        preset["path"] = f"{int(entity) // 1000}/{int(entity)}"   # 10991 -> 10/10991
    token = create_token(CLIENT_ID, CLIENT_SECRET, ORIGIN, ttl=TOKEN_TTL, subject=USER_ID,
                         max_files=200, access=access, **preset)
    return JSONResponse({"token": token}, headers={"Cache-Control": "no-store"})


async def save_files(request):
    """The page reports uploaded files; keep only those the receipt proves are ours."""
    saved = []
    for item in (await request.json()).get("files", [])[:200]:
        # The file itself and each of its sizes come with their own receipt.
        refs = [item] + list((item.get("variants") or {}).values())
        for ref in refs[:20]:
            key, receipt = str(ref.get("key", "")), str(ref.get("receipt", ""))
            if verify_receipt(key, USER_ID, receipt, CLIENT_SECRET):
                OWNED_KEYS.add(key)
                saved.append(key)
    return JSONResponse({"saved": saved})


async def file_link(request):
    """Temporary link to a "signed" file the user owns."""
    key = (await request.json()).get("key", "")
    if key not in OWNED_KEYS:
        return JSONResponse({"error": "not your file"}, status_code=403)
    link = sign_link(key, CLIENT_ID, CLIENT_SECRET, UPLOADER_URL, ttl=120)
    return JSONResponse({"url": link}, headers={"Cache-Control": "no-store"})


async def read_token(request):
    """Read token for "private" files the user owns."""
    keys = [k for k in (await request.json()).get("keys", []) if k in OWNED_KEYS]
    if not keys:
        return JSONResponse({"error": "not your file"}, status_code=403)
    token = create_read_token(CLIENT_ID, CLIENT_SECRET, ORIGIN, keys[:100], ttl=120, subject=USER_ID)
    return JSONResponse({"token": token}, headers={"Cache-Control": "no-store"})


app = Starlette(routes=[
    Route("/", index),
    Route("/api/cdn-upload-token", upload_token, methods=["POST"]),
    Route("/api/files", save_files, methods=["POST"]),
    Route("/api/cdn-link", file_link, methods=["POST"]),
    Route("/api/cdn-read-token", read_token, methods=["POST"]),
])
