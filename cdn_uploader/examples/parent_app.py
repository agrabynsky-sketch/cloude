"""Demo "parent application" that opens the uploader in a modal.

It plays the role of your real app: serves a page and, from its backend,
mints upload tokens with the client secret. Run it next to the uploader:

    DEMO_CLIENT_SECRET=... uvicorn examples.parent_app:app --port 8001

then open http://localhost:8001.
"""

import html
import os
from pathlib import Path

from starlette.applications import Starlette
from starlette.responses import HTMLResponse, JSONResponse
from starlette.routing import Route

from uploader.tokens import create_token

CLIENT_ID = os.environ.get("DEMO_CLIENT_ID", "demo")
CLIENT_SECRET = os.environ.get("DEMO_CLIENT_SECRET", "")
ORIGIN = os.environ.get("DEMO_ORIGIN", "http://localhost:8001")
UPLOADER_URL = os.environ.get("DEMO_UPLOADER_URL", "http://localhost:8000").rstrip("/")
TOKEN_TTL = int(os.environ.get("DEMO_TOKEN_TTL", "900"))
PAGE = Path(__file__).with_name("parent.html").read_text(encoding="utf-8")


async def index(request):
    return HTMLResponse(PAGE.replace("{{UPLOADER_URL}}", html.escape(UPLOADER_URL)))


async def upload_token(request):
    # In a real app: check the user's session/CSRF here and decide what this
    # user may upload (types, max_files) before minting the token.
    token = create_token(CLIENT_ID, CLIENT_SECRET, ORIGIN, ttl=TOKEN_TTL, subject="demo-user", max_files=200)
    return JSONResponse({"token": token}, headers={"Cache-Control": "no-store"})


app = Starlette(routes=[
    Route("/", index),
    Route("/api/cdn-upload-token", upload_token, methods=["POST"]),
])
