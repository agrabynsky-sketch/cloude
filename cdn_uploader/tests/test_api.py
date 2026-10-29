import json
import shutil
import time
from urllib.parse import quote

import pytest
from starlette.testclient import TestClient

from uploader.app import create_app
from uploader.config import load_settings
from uploader.tokens import create_token

from . import samples

SECRET = "test-secret-" + "x" * 32
ORIGIN = "http://localhost:8001"


HAS_FFMPEG = bool(shutil.which("ffmpeg"))
needs_ffmpeg = pytest.mark.skipif(not HAS_FFMPEG, reason="ffmpeg not installed")


def make_settings(tmp_path, **extra):
    env = {
        "UPLOADER_HASH_KEY": "k" * 40,
        "UPLOADER_CLIENTS": json.dumps({"demo": {"secret": SECRET, "origins": [ORIGIN]}}),
        "UPLOADER_STORAGE_DIR": str(tmp_path / "f"),
        "UPLOADER_CDN_BASE_URL": "https://cdn.unit.travel/f",
        "UPLOADER_MAX_FILE_MB": "2",
        "UPLOADER_MAX_VIDEO_MB": "5",
        "UPLOADER_VIDEO_MODE": "transcode" if HAS_FFMPEG else "off",
        "UPLOADER_VIDEO_MAX_SIDE": "160",
        "UPLOADER_VIDEO_THREADS": "2",
        "UPLOADER_SERVE_FILES": "1",
    }
    env.update(extra)
    return load_settings(env)


@pytest.fixture
def settings(tmp_path):
    return make_settings(tmp_path)


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


def wait_for_job(client, job_id, headers, timeout=60):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        body = client.get(f"/api/v1/jobs/{job_id}", headers=headers).json()
        if body["status"] in ("done", "error"):
            return body
        time.sleep(0.1)
    raise AssertionError("job did not finish")


@needs_ffmpeg
def test_video_upload_runs_as_job_and_returns_link(client, tmp_path):
    clip = samples.video(tmp_path / "trip.mov", "mov", extra=["-metadata", "title=secret"])
    headers = auth(subject="u1")
    res = put(client, "Поездка.mov", clip.read_bytes(), headers)
    assert res.status_code == 202, res.text
    body = wait_for_job(client, res.json()["job"], headers)
    assert body["status"] == "done", body
    file = body["file"]
    assert file["ext"] == "mp4" and file["mime"] == "video/mp4" and file["category"] == "video"
    assert file["url"].endswith(f"/{file['hash']}.mp4")
    served = client.get("/f/" + file["url"].split("/f/", 1)[1])
    assert served.headers["content-type"] == "video/mp4"
    assert "content-disposition" not in served.headers
    assert b"secret" not in served.content


@needs_ffmpeg
def test_job_visible_only_to_its_owner(client, tmp_path):
    clip = samples.video(tmp_path / "a.mp4")
    job_id = put(client, "a.mp4", clip.read_bytes(), auth(subject="u1")).json()["job"]
    assert client.get(f"/api/v1/jobs/{job_id}", headers=auth(subject="u2")).status_code == 404
    # A refreshed token for the same user still sees it.
    assert wait_for_job(client, job_id, auth(subject="u1"))["status"] == "done"


@needs_ffmpeg
def test_invalid_video_fails_job_and_frees_the_slot(client):
    headers = auth(max_files=1, subject="u1")
    res = put(client, "fake.mp4", samples.png(), headers)
    assert res.status_code == 202
    body = wait_for_job(client, res.json()["job"], headers)
    assert (body["status"], body["error"]) == ("error", "content_mismatch")
    assert put(client, "a.txt", b"ok", headers).status_code == 201


@needs_ffmpeg
def test_video_size_limit_is_separate(client):
    assert put(client, "big.mp4", b"\0" * (5 * 1024 * 1024 + 1), auth()).status_code == 413
    assert put(client, "big.txt", b"a" * (3 * 1024 * 1024), auth()).status_code == 413


@needs_ffmpeg
def test_session_reports_video_limits(client):
    body = client.get("/api/v1/session", headers=auth()).json()
    assert "video" in body["types"] and body["extensions"]["mov"] == "video"
    assert body["max_sizes"]["video"] == 5 * 1024 * 1024
    assert body["max_sizes"]["image"] == 2 * 1024 * 1024


def test_video_can_be_disabled(tmp_path):
    client = TestClient(create_app(make_settings(tmp_path, UPLOADER_VIDEO_MODE="off")))
    body = client.get("/api/v1/session", headers=auth()).json()
    assert "video" not in body["types"] and "mp4" not in body["extensions"]
    assert put(client, "a.mp4", b"\0" * 100, auth()).status_code == 415


def test_rate_limit_tells_client_when_to_retry(tmp_path):
    client = TestClient(create_app(make_settings(tmp_path, UPLOADER_RATE_LIMIT_PER_MINUTE="2")))
    headers = auth()
    put(client, "a.txt", b"1", headers)
    put(client, "b.txt", b"2", headers)
    res = put(client, "c.txt", b"3", headers)
    assert res.status_code == 429 and res.json()["error"] == "rate_limited"
    assert int(res.headers["retry-after"]) > 0
