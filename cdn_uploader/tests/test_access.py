"""Access levels: public, signed (temporary links) and private (read tokens)."""

import json
from urllib.parse import parse_qsl, quote, urlsplit

import pytest
from starlette.testclient import TestClient

from uploader.app import create_app
from uploader.config import load_settings
from uploader.tokens import create_read_token, create_token, sign_link, verify_receipt

from . import samples

SECRET = "test-secret-" + "x" * 32
OTHER_SECRET = "other-secret-" + "y" * 32
ORIGIN = "http://localhost:8001"
CDN = "http://localhost:8000"


def make_client(tmp_path, **extra):
    env = {
        "UPLOADER_HASH_KEY": "k" * 40,
        "UPLOADER_CLIENTS": json.dumps({
            "demo": {"secret": SECRET, "origins": [ORIGIN]},
            "vault": {"secret": OTHER_SECRET, "origins": ["https://vault.example"], "default_access": "private"},
        }),
        "UPLOADER_STORAGE_DIR": str(tmp_path / "f"),
        "UPLOADER_CDN_BASE_URL": CDN + "/f",
        "UPLOADER_VIDEO_MODE": "off",
        "UPLOADER_SERVE_FILES": "1",
    }
    env.update(extra)
    return TestClient(create_app(load_settings(env)))


@pytest.fixture
def client(tmp_path):
    return make_client(tmp_path)


def upload(client, data=None, name="passport.jpg", cid="demo", secret=SECRET, origin=ORIGIN, **claims):
    token = create_token(cid, secret, origin, subject="user-1", **claims)
    res = client.post("/api/v1/files", content=data or samples.jpeg(),
                      headers={"Authorization": "Bearer " + token, "X-File-Name": quote(name)})
    assert res.status_code == 201, res.text
    return res.json()


def path_of(url):
    parts = urlsplit(url)
    return parts.path + ("?" + parts.query if parts.query else "")


def read(client, key, origin=ORIGIN, files=None, cid="demo", secret=SECRET, headers=None):
    token = create_read_token(cid, secret, origin, files or [key])
    return client.get("/" + key, headers={"Authorization": "Bearer " + token, "Origin": origin, **(headers or {})})


# ---- public --------------------------------------------------------------------

def test_public_upload_has_permanent_url_and_key(client):
    body = upload(client)
    assert body["access"] == "public" and body["key"].startswith("f/")
    assert body["url"] == f"{CDN}/f/{body['key'][2:]}"
    assert client.get(path_of(body["url"])).status_code == 200


# ---- signed --------------------------------------------------------------------

def test_signed_upload_returns_temporary_link(client, tmp_path):
    body = upload(client, access="signed")
    assert body["access"] == "signed" and body["key"].startswith("s/demo/")
    params = dict(parse_qsl(urlsplit(body["url"]).query))
    assert set(params) == {"exp", "sig"} and body["expires_at"] == int(params["exp"])
    res = client.get(path_of(body["url"]))
    assert res.status_code == 200 and res.content[:3] == b"\xff\xd8\xff"
    assert res.headers["cache-control"] == "private, no-store"
    assert res.headers["cross-origin-resource-policy"] == "same-site"
    assert "sandbox" in res.headers["content-security-policy"]
    assert res.headers["content-disposition"].startswith("inline")
    # Not stored with the public files, not reachable through /f/.
    assert not list((tmp_path / "f").rglob("*.jpg"))
    assert client.get("/f/" + body["key"].split("/", 2)[2]).status_code == 404


def test_signed_file_needs_valid_link(client):
    body = upload(client, access="signed")
    key = body["key"]
    assert client.get("/" + key).status_code == 401
    params = dict(parse_qsl(urlsplit(body["url"]).query))
    assert client.get(f"/{key}?exp={params['exp']}&sig={'A' * 43}").json()["error"] == "invalid_link"
    expired = sign_link(key, "demo", SECRET, CDN, ttl=-1)
    assert client.get(path_of(expired)).json()["error"] == "link_expired"
    # A valid signature for one file does not open another.
    other = upload(client, data=samples.png(), name="b.png", access="signed")
    assert client.get(f"/{other['key']}?exp={params['exp']}&sig={params['sig']}").status_code == 403


def test_signed_link_bound_to_ip_and_download_name(client):
    key = upload(client, data=samples.pdf(), name="ticket.pdf", access="signed")["key"]
    ok = sign_link(key, "demo", SECRET, CDN, ttl=60, ip="testclient", name="Билет PS101.pdf")
    res = client.get(path_of(ok))
    assert res.status_code == 200
    disposition = res.headers["content-disposition"]
    assert disposition.startswith("attachment") and "filename*=UTF-8''" + quote("Билет PS101.pdf") in disposition
    other_ip = sign_link(key, "demo", SECRET, CDN, ttl=60, ip="10.0.0.1")
    assert client.get(path_of(other_ip)).status_code == 403


def test_signed_link_of_other_client_rejected(client):
    key = upload(client, access="signed")["key"]
    forged = sign_link(key.replace("s/demo", "s/vault"), "vault", OTHER_SECRET, CDN, ttl=60)
    assert client.get(path_of(forged)).status_code in (403, 404)


# ---- private -------------------------------------------------------------------

def test_private_file_only_with_read_token(client):
    body = upload(client, access="private")
    key = body["key"]
    assert key.startswith("p/demo/") and body["url"] == f"{CDN}/{key}" and "expires_at" not in body
    assert client.get("/" + key).status_code == 401
    res = read(client, key)
    assert res.status_code == 200 and res.content[:3] == b"\xff\xd8\xff"
    assert res.headers["access-control-allow-origin"] == ORIGIN
    assert "Content-Disposition" in res.headers["access-control-expose-headers"]


def test_private_file_rejects_wrong_credentials(client):
    key = upload(client, access="private")["key"]
    other = upload(client, data=samples.png(), name="x.png", access="private")["key"]
    assert read(client, key, files=[other]).status_code == 403                    # not listed
    upload_token = create_token("demo", SECRET, ORIGIN)
    assert client.get("/" + key, headers={"Authorization": "Bearer " + upload_token}).status_code == 401
    res = read(client, key, headers={"Origin": "https://evil.example"})
    assert res.status_code == 403 and "access-control-allow-origin" not in res.headers
    # A signed link can never open a private file.
    sig = sign_link(key.replace("p/", "s/", 1), "demo", SECRET, CDN, ttl=60).split("?", 1)[1]
    assert client.get(f"/{key}?{sig}").status_code == 401


def test_read_token_of_other_client_rejected(client):
    key = upload(client, access="private")["key"]
    token = create_read_token("vault", OTHER_SECRET, "https://vault.example",
                              [key.replace("p/demo", "p/vault")])
    assert client.get("/" + key, headers={"Authorization": "Bearer " + token}).status_code == 403


def test_preflight_only_for_client_origins(client):
    key = upload(client, access="private")["key"]
    ok = client.options("/" + key, headers={"Origin": ORIGIN, "Access-Control-Request-Method": "GET",
                                             "Access-Control-Request-Headers": "authorization"})
    assert ok.status_code == 204 and ok.headers["access-control-allow-origin"] == ORIGIN
    assert "Authorization" in ok.headers["access-control-allow-headers"]
    bad = client.options("/" + key, headers={"Origin": "https://evil.example", "Access-Control-Request-Method": "GET"})
    assert bad.status_code == 403 and "access-control-allow-origin" not in bad.headers


def test_range_requests_work(client):
    key = upload(client, access="private")["key"]
    res = read(client, key, headers={"Range": "bytes=0-9"})
    assert res.status_code == 206 and len(res.content) == 10


def test_unknown_or_malformed_keys_are_404(client):
    for path in ["/p/demo/../../etc/passwd", "/p/nobody/ab/cd/" + "a" * 64 + ".png", "/s/demo/x"]:
        assert client.get(path).status_code == 404


def test_missing_file_after_authorization_is_404(client):
    key = "p/demo/ab/cd/ab" + "c" * 62 + ".png"
    assert read(client, key).status_code == 404


# ---- defaults, receipts, nginx mode ---------------------------------------------

def test_client_default_access(client):
    body = upload(client, cid="vault", secret=OTHER_SECRET, origin="https://vault.example")
    assert body["access"] == "private" and body["key"].startswith("p/vault/")
    # The token may still choose explicitly.
    body = upload(client, cid="vault", secret=OTHER_SECRET, origin="https://vault.example", access="public")
    assert body["access"] == "public"


def test_receipt_proves_uploader(client):
    body = upload(client, access="private")
    assert verify_receipt(body["key"], "user-1", body["receipt"], SECRET)
    assert not verify_receipt(body["key"], "user-2", body["receipt"], SECRET)


def test_session_reports_access(client):
    token = create_token("demo", SECRET, ORIGIN, access="signed")
    assert client.get("/api/v1/session", headers={"Authorization": "Bearer " + token}).json()["access"] == "signed"


def test_accel_redirect_mode(tmp_path):
    client = make_client(tmp_path, UPLOADER_ACCEL_REDIRECT="/_protected/")
    key = upload(client, access="private")["key"]
    res = read(client, key)
    assert res.status_code == 200 and res.content == b""
    assert res.headers["x-accel-redirect"] == "/_protected/" + key
    assert res.headers["content-type"] == "image/jpeg"


@pytest.mark.skipif(not __import__("shutil").which("ffmpeg"), reason="ffmpeg not installed")
def test_private_video_job(tmp_path):
    import time
    client = make_client(tmp_path, UPLOADER_VIDEO_MODE="transcode", UPLOADER_VIDEO_MAX_SIDE="160",
                         UPLOADER_VIDEO_THREADS="2")
    clip = samples.video(tmp_path / "trip.mp4")
    token = create_token("demo", SECRET, ORIGIN, subject="user-1", access="private")
    headers = {"Authorization": "Bearer " + token}
    job = client.post("/api/v1/files", content=clip.read_bytes(),
                      headers={**headers, "X-File-Name": "trip.mp4"}).json()["job"]
    for _ in range(300):
        body = client.get(f"/api/v1/jobs/{job}", headers=headers).json()
        if body["status"] != "processing" and body["status"] != "queued":
            break
        time.sleep(0.1)
    file = body["file"]
    assert file["access"] == "private" and file["key"].startswith("p/demo/") and file["ext"] == "mp4"
    assert verify_receipt(file["key"], "user-1", file["receipt"], SECRET)
    assert client.get("/" + file["key"]).status_code == 401
    res = read(client, file["key"])
    assert res.status_code == 200 and res.headers["content-type"] == "video/mp4"
