import json
from urllib.parse import quote

import pytest
from starlette.testclient import TestClient

from uploader.app import create_app
from uploader.config import load_settings
from uploader.tokens import create_token

from . import samples

SECRET = "test-secret-" + "x" * 32
ORIGIN = "http://localhost:8001"


@pytest.fixture
def settings(tmp_path):
    return load_settings({
        "UPLOADER_HASH_KEY": "k" * 40,
        "UPLOADER_CLIENTS": json.dumps({"demo": {"secret": SECRET, "origins": [ORIGIN]}}),
        "UPLOADER_STORAGE_DIR": str(tmp_path / "f"),
        "UPLOADER_CDN_BASE_URL": "https://cdn.unit.travel/f",
        "UPLOADER_MAX_FILE_MB": "2",
        "UPLOADER_SERVE_FILES": "1",
    })


@pytest.fixture
def client(settings):
    return TestClient(create_app(settings))


def auth(**kw):
    return {"Authorization": "Bearer " + create_token("demo", SECRET, ORIGIN, **kw)}


def put(client, name, data, headers):
    return client.post("/api/v1/files", content=data,
                       headers={**headers, "X-File-Name": quote(name)})


def test_session_reports_limits(client):
    res = client.get("/api/v1/session", headers=auth(max_files=3, types=["image"]))
    assert res.status_code == 200
    body = res.json()
    assert body["origin"] == ORIGIN
    assert body["remaining"] == 3
    assert body["types"] == ["image"]
    assert set(body["extensions"]) == {"jpg", "jpeg", "png", "gif", "webp"}
    assert res.headers["cache-control"] == "no-store"


def test_session_requires_token(client):
    assert client.get("/api/v1/session").status_code == 401
    assert client.get("/api/v1/session", headers={"Authorization": "Bearer nope"}).status_code == 401


def test_upload_returns_hashed_cdn_url(client, settings):
    res = put(client, "Паспорт.png", samples.png() + b"<?php ?>", auth())
    assert res.status_code == 201, res.text
    body = res.json()
    h = body["hash"]
    assert len(h) == 64
    assert body["url"] == f"https://cdn.unit.travel/f/{h[:2]}/{h[2:4]}/{h}.png"
    assert body["name"] == "Паспорт.png"
    stored = settings.storage_dir / h[:2] / h[2:4] / f"{h}.png"
    assert stored.is_file() and b"php" not in stored.read_bytes()
    # Same content -> same name (dedup); original name never reaches the disk.
    again = put(client, "other.png", samples.png() + b"<?php ?>", auth()).json()
    assert again["hash"] == h
    assert not any("Паспорт" in p.name for p in settings.storage_dir.rglob("*"))


def test_upload_rejects_disallowed_type(client):
    res = put(client, "x.html", b"<script>alert(1)</script>", auth())
    assert res.status_code == 415
    assert res.json()["error"] == "type_not_allowed"


def test_upload_rejects_type_outside_token_scope(client):
    assert put(client, "a.pdf", samples.pdf(), auth(types=["image"])).status_code == 415


def test_upload_rejects_malicious_content(client):
    res = put(client, "a.pdf", samples.pdf(b"/OpenAction << /S /JavaScript /JS (x) >>"), auth())
    assert res.status_code == 422
    assert res.json()["error"] == "active_content"


def test_upload_too_large(client):
    res = put(client, "a.txt", b"a" * (2 * 1024 * 1024 + 1), auth())
    assert res.status_code == 413


def test_max_files_per_token_enforced_and_failures_not_counted(client):
    headers = auth(max_files=2)
    assert put(client, "bad.png", b"not an image", headers).status_code == 422
    assert put(client, "a.txt", b"one", headers).status_code == 201
    assert put(client, "b.txt", b"two", headers).status_code == 201
    res = put(client, "c.txt", b"three", headers)
    assert res.status_code == 429 and res.json()["error"] == "file_limit_reached"


def test_upload_requires_token(client):
    assert put(client, "a.txt", b"hi", {}).status_code == 401


def test_stored_file_served_with_safe_headers(client):
    url = put(client, "a.pdf", samples.pdf(), auth()).json()["url"]
    path = url.split("/f/", 1)[1]
    res = client.get(f"/f/{path}")
    assert res.status_code == 200
    assert res.headers["content-type"] == "application/pdf"
    assert res.headers["x-content-type-options"] == "nosniff"
    assert "sandbox" in res.headers["content-security-policy"]
    assert res.headers["content-disposition"].startswith("attachment")


def test_stored_image_served_inline(client):
    url = put(client, "a.png", samples.png(), auth()).json()["url"]
    res = client.get("/f/" + url.split("/f/", 1)[1])
    assert res.headers["content-type"] == "image/png"
    assert "content-disposition" not in res.headers


@pytest.mark.parametrize("path", ["../../etc/passwd", "ab/cd/x.png", "%2e%2e/%2e%2e/etc/passwd", "00/00/" + "0" * 64 + ".png"])
def test_file_route_rejects_anything_but_hash_paths(client, path):
    assert client.get(f"/f/{path}").status_code == 404


def test_uploader_page_csp(client):
    res = client.get("/uploader/")
    assert res.status_code == 200
    csp = res.headers["content-security-policy"]
    assert f"frame-ancestors {ORIGIN}" in csp
    assert "script-src 'self'" in csp and "unsafe-inline" not in csp
    assert client.get("/uploader/../app.py").status_code == 404
    assert client.get("/uploader/secret.txt").status_code == 404


def test_api_cannot_be_framed(client):
    res = client.get("/api/v1/session")
    assert res.headers["x-frame-options"] == "DENY"
    assert "frame-ancestors 'none'" in res.headers["content-security-policy"]


def test_sdk_served(client):
    res = client.get("/sdk/unit-uploader.js")
    assert res.status_code == 200
    assert "UnitUploader" in res.text
