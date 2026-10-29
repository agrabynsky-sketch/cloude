"""White-label sites on other domains: origin patterns, hot reload, per-client domains and CORP."""

import json
import os
import time
from urllib.parse import quote, urlsplit

import pytest
from starlette.testclient import TestClient

from uploader.app import create_app
from uploader.config import Client, ConfigError, load_settings
from uploader.tokens import create_read_token, create_token

from . import samples

SECRET = "wl-secret-" + "x" * 32
PLATFORM = {"secret": "p" * 40, "origins": ["https://app.unit.travel"]}


@pytest.mark.parametrize("origin, ok", [
    ("https://brand-a.com", True),
    ("https://shop.brand-b.travel", True),
    ("https://deep.shop.brand-b.travel", True),
    ("https://brand-b.travel", False),                  # pattern covers subdomains only
    ("https://evilbrand-b.travel", False),
    ("https://brand-b.travel.evil.com", False),
    ("http://shop.brand-b.travel", False),              # scheme must match
    ("https://shop.brand-b.travel:8443", False),        # port must match
    ("https://BRAND-A.com", True),
    ("null", False), ("", False), (None, False),
])
def test_origin_patterns(origin, ok):
    client = Client(secret="s" * 32, origins=("https://brand-a.com", "https://*.brand-b.travel"))
    assert client.allows(origin) is ok


@pytest.mark.parametrize("bad", ["https://*.com", "https://*brand.com", "*", "https://brand.com/path"])
def test_bad_origin_patterns_rejected(bad, tmp_path):
    env = {"UPLOADER_HASH_KEY": "k" * 40, "UPLOADER_VIDEO_MODE": "off",
           "UPLOADER_CLIENTS": json.dumps({"wl": {"secret": SECRET, "origins": [bad]}})}
    with pytest.raises(ConfigError):
        load_settings(env)


def make_client(tmp_path, clients, **extra):
    path = tmp_path / "clients.json"
    path.write_text(json.dumps(clients))
    env = {
        "UPLOADER_HASH_KEY": "k" * 40,
        "UPLOADER_CLIENTS_FILE": str(path),
        "UPLOADER_CLIENTS_RELOAD_SECONDS": "0",
        "UPLOADER_STORAGE_DIR": str(tmp_path / "f"),
        "UPLOADER_CDN_BASE_URL": "https://cdn.unit.travel/f",
        "UPLOADER_VIDEO_MODE": "off",
        "UPLOADER_SERVE_FILES": "1",
        **extra,
    }
    return TestClient(create_app(load_settings(env))), path


def upload(client, origin, **claims):
    token = create_token("wl", SECRET, origin, subject="u1", **claims)
    return client.post("/api/v1/files", content=samples.png(),
                       headers={"Authorization": "Bearer " + token, "X-File-Name": "a.png"})


def frame_ancestors(client, origin):
    csp = client.get("/uploader/", params={"origin": origin}).headers["content-security-policy"]
    return csp.rsplit("frame-ancestors ", 1)[1]


def test_white_label_domains_with_patterns(tmp_path):
    client, _ = make_client(tmp_path, {
        "platform": PLATFORM,
        "wl": {"secret": SECRET, "origins": ["https://brand-a.com", "https://*.brand-b.travel"]},
    })
    assert upload(client, "https://shop.brand-b.travel").status_code == 201
    assert upload(client, "https://brand-c.com").status_code == 403
    # The page lets only the opening site frame it, and never lists other partners.
    assert frame_ancestors(client, "https://shop.brand-b.travel") == "https://shop.brand-b.travel"
    assert "brand-a" not in frame_ancestors(client, "https://app.unit.travel")


def test_new_domains_without_restart(tmp_path):
    client, path = make_client(tmp_path, {"wl": {"secret": SECRET, "origins": ["https://brand-a.com"]}})
    assert upload(client, "https://brand-new.com").status_code == 403
    path.write_text(json.dumps({"wl": {"secret": SECRET, "origins": ["https://brand-a.com", "https://brand-new.com"]}}))
    os.utime(path, ns=(time.time_ns(), time.time_ns() + 10**9))
    assert upload(client, "https://brand-new.com").status_code == 201
    assert frame_ancestors(client, "https://brand-new.com") == "https://brand-new.com"


def test_broken_clients_file_keeps_previous_clients(tmp_path):
    client, path = make_client(tmp_path, {"wl": {"secret": SECRET, "origins": ["https://brand-a.com"]}})
    path.write_text("{ not json")
    os.utime(path, ns=(time.time_ns(), time.time_ns() + 10**9))
    assert upload(client, "https://brand-a.com").status_code == 201


def test_client_own_domains_and_corp(tmp_path):
    client, _ = make_client(tmp_path, {"wl": {
        "secret": SECRET, "origins": ["https://brand-a.com"], "corp": "cross-origin",
        "cdn_base_url": "https://files.brand-a.com/f", "protected_base_url": "https://files.brand-a.com",
    }})
    public = upload(client, "https://brand-a.com").json()
    assert public["url"].startswith("https://files.brand-a.com/f/")
    signed = upload(client, "https://brand-a.com", access="signed").json()
    assert signed["url"].startswith("https://files.brand-a.com/s/wl/")
    parts = urlsplit(signed["url"])
    res = client.get(parts.path + "?" + parts.query)
    # A signed image must be embeddable on the partner's (cross-site) pages.
    assert res.status_code == 200 and res.headers["cross-origin-resource-policy"] == "cross-origin"


def test_private_files_cors_for_pattern_origin(tmp_path):
    client, _ = make_client(tmp_path, {"wl": {"secret": SECRET, "origins": ["https://*.brand-b.travel"]}})
    key = upload(client, "https://shop.brand-b.travel", access="private").json()["key"]
    token = create_read_token("wl", SECRET, "https://shop.brand-b.travel", [key])
    res = client.get("/" + key, headers={"Authorization": "Bearer " + token, "Origin": "https://shop.brand-b.travel"})
    assert res.status_code == 200 and res.headers["access-control-allow-origin"] == "https://shop.brand-b.travel"
    pre = client.options("/" + key, headers={"Origin": "https://shop.brand-b.travel", "Access-Control-Request-Method": "GET"})
    assert pre.status_code == 204
    # Another subdomain cannot use that token.
    res = client.get("/" + key, headers={"Authorization": "Bearer " + token, "Origin": "https://other.brand-b.travel"})
    assert res.status_code == 403
