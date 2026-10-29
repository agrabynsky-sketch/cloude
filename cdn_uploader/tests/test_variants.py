"""Image sizes (variants) and folder layouts."""

import base64
import hashlib
import hmac
import io
import json
from urllib.parse import quote, urlsplit

import pytest
from PIL import Image
from starlette.testclient import TestClient

from uploader.app import create_app
from uploader.config import load_settings
from uploader.tokens import TokenError, create_read_token, create_token, parse_sizes, verify_receipt
from uploader.validators.images import target_box

from . import samples

SECRET = "test-secret-" + "x" * 32
ORIGIN = "http://localhost:8001"
CDN = "http://localhost:8000"
ROOM_SIZES = ["preview_panel-100-100", "small-70-0", "medium-350-0", "big-1200-800"]


# ---- size specs ----------------------------------------------------------------

def test_parse_compact_and_object_sizes():
    assert parse_sizes(ROOM_SIZES + ["thumb-100-100-crop", {"name": "tall", "width": 0, "height": 300}]) == (
        ("preview_panel", 100, 100, "fit"), ("small", 70, 0, "fit"), ("medium", 350, 0, "fit"),
        ("big", 1200, 800, "fit"), ("thumb", 100, 100, "crop"), ("tall", 0, 300, "fit"))


@pytest.mark.parametrize("bad", [
    ["orig-100-100"], ["small-0-0"], ["small-70"], ["small-70-0-zoom"], ["a/b-10-10"], ["Small-10-10"],
    ["small-10-10", "small-20-20"], ["huge-99999-0"], ["sq-100-0-crop"], [f"s{i}-10-10" for i in range(11)],
    [{"name": "x", "width": "10", "height": 0}], "small-70-0",
])
def test_invalid_sizes_rejected(bad):
    with pytest.raises(ValueError):
        parse_sizes(bad)


def test_token_with_invalid_layout_rejected():
    from uploader.config import Client
    from uploader.tokens import verify_token
    clients = {"demo": Client(secret=SECRET, origins=(ORIGIN,))}
    # Mint by hand to get past create_token's own checks.
    for claims in ({"folder": "../etc"}, {"path": "a/../../b"}, {"sizes": ["orig-1-1"]}, {"folder": "a/b/c/d"}):
        payload = {"cid": "demo", "origin": ORIGIN, "exp": 4102444800 - 1, "jti": "x" * 16, **claims}
        body = base64.urlsafe_b64encode(json.dumps(payload).encode()).rstrip(b"=").decode()
        sig = base64.urlsafe_b64encode(hmac.new(SECRET.encode(), body.encode(), hashlib.sha256).digest()).rstrip(b"=").decode()
        with pytest.raises(TokenError):
            verify_token(f"{body}.{sig}", clients, max_ttl=10 ** 10, max_files_cap=10)


@pytest.mark.parametrize("size, mode, expected", [
    ((70, 0), "fit", (70, 53)),         # width given, height proportional
    ((0, 100), "fit", (133, 100)),
    ((1200, 800), "fit", (1067, 800)),  # fits inside the box
    ((100, 100), "fit", (100, 75)),
    ((100, 100), "crop", (100, 100)),   # fills the box exactly
    ((3000, 0), "fit", (1600, 1200)),   # never upscaled
    ((3000, 3000), "crop", (1200, 1200)),
])
def test_target_box(size, mode, expected):
    assert target_box(1600, 1200, size[0], size[1], mode)[1] == expected


# ---- API -----------------------------------------------------------------------------

@pytest.fixture
def client(tmp_path):
    env = {
        "UPLOADER_HASH_KEY": "k" * 40,
        "UPLOADER_CLIENTS": json.dumps({"demo": {"secret": SECRET, "origins": [ORIGIN]}}),
        "UPLOADER_STORAGE_DIR": str(tmp_path / "f"),
        "UPLOADER_CDN_BASE_URL": CDN + "/f",
        "UPLOADER_VIDEO_MODE": "off",
        "UPLOADER_SERVE_FILES": "1",
    }
    return TestClient(create_app(load_settings(env)))


def upload(client, data, name="room.jpg", **claims):
    token = create_token("demo", SECRET, ORIGIN, subject="user-1", **claims)
    res = client.post("/api/v1/files", content=data,
                      headers={"Authorization": "Bearer " + token, "X-File-Name": quote(name)})
    assert res.status_code == 201, res.text
    return res.json()


def photo(size=(1600, 1200), **save):
    out = io.BytesIO()
    Image.effect_noise(size, 50).convert("RGB").save(out, "JPEG", **save)
    return out.getvalue()


def fetch(client, url):
    parts = urlsplit(url)
    res = client.get(parts.path + ("?" + parts.query if parts.query else ""))
    assert res.status_code == 200, (url, res.status_code)
    return Image.open(io.BytesIO(res.content))


def test_room_photo_sizes_in_folders(client, tmp_path):
    body = upload(client, photo(), folder="rooms", sizes=ROOM_SIZES)
    h = body["hash"]
    assert body["key"] == f"f/rooms/orig/{h[:2]}/{h[2:4]}/{h}.jpg"
    assert body["folder"] == "rooms" and (body["width"], body["height"]) == (1600, 1200)
    variants = body["variants"]
    assert list(variants) == ["orig", "preview_panel", "small", "medium", "big"]
    expected = {"orig": (1600, 1200), "preview_panel": (100, 75), "small": (70, 53),
                "medium": (350, 263), "big": (1067, 800)}
    for name, v in variants.items():
        assert v["key"] == f"f/rooms/{name}/{h[:2]}/{h[2:4]}/{h}.jpg"      # same name in every folder
        assert v["url"] == f"{CDN}/{v['key']}"
        assert (v["width"], v["height"]) == expected[name]
        assert fetch(client, v["url"]).size == expected[name]
        assert verify_receipt(v["key"], "user-1", v["receipt"], SECRET)
        assert (tmp_path / v["key"]).is_file()


def test_custom_path_like_existing_layout(client):
    body = upload(client, photo(), folder="photos/rooms", path="10/10991", sizes=["small-70-0"])
    h = body["hash"]
    assert body["variants"]["orig"]["key"] == f"f/photos/rooms/orig/10/10991/{h}.jpg"
    assert body["variants"]["small"]["key"] == f"f/photos/rooms/small/10/10991/{h}.jpg"
    assert fetch(client, body["variants"]["small"]["url"]).size == (70, 53)


def test_crop_size(client):
    body = upload(client, photo(), folder="rooms", sizes=["preview_panel-100-100-crop"])
    assert fetch(client, body["variants"]["preview_panel"]["url"]).size == (100, 100)


def test_folder_without_sizes_keeps_orig_level(client):
    body = upload(client, photo(), folder="rooms")
    assert body["key"].startswith("f/rooms/orig/") and "variants" not in body


def test_documents_get_only_orig(client):
    body = upload(client, samples.pdf(), name="ticket.pdf", folder="rooms", sizes=ROOM_SIZES)
    assert body["key"].startswith("f/rooms/orig/") and "variants" not in body and "width" not in body


def test_no_folder_no_sizes_keeps_old_layout(client):
    body = upload(client, photo())
    h = body["hash"]
    assert body["key"] == f"f/{h[:2]}/{h[2:4]}/{h}.jpg" and "variants" not in body
    assert (body["width"], body["height"]) == (1600, 1200)


def test_size_settings_are_part_of_the_name(client):
    data = photo()
    a = upload(client, data, folder="rooms", sizes=["small-70-0"])
    b = upload(client, data, folder="rooms", sizes=["small-70-0"])
    c = upload(client, data, folder="rooms", sizes=["small-100-0"])
    assert a["hash"] == b["hash"] != c["hash"]          # never two different "small" under one name
    assert fetch(client, c["variants"]["small"]["url"]).size == (100, 75)


def test_private_sizes_need_read_token(client):
    body = upload(client, photo(), folder="passports", sizes=["small-70-0"], access="private")
    small = body["variants"]["small"]
    assert small["key"].startswith("p/demo/passports/small/")
    assert client.get("/" + small["key"]).status_code == 401
    token = create_read_token("demo", SECRET, ORIGIN, [small["key"]])
    res = client.get("/" + small["key"], headers={"Authorization": "Bearer " + token})
    assert res.status_code == 200 and Image.open(io.BytesIO(res.content)).size == (70, 53)


def test_signed_sizes_get_temporary_links(client):
    body = upload(client, photo(), folder="visas", sizes=["small-70-0"], access="signed")
    for v in body["variants"].values():
        assert "sig=" in v["url"] and v["expires_at"] > 0
        assert fetch(client, v["url"]).size == (v["width"], v["height"])


def test_exif_rotation_applied_before_sizes(client):
    exif = Image.Exif()
    exif[0x0112] = 6  # rotate 90 degrees
    body = upload(client, photo(exif=exif.tobytes()), folder="rooms", sizes=["small-70-0"])
    assert (body["width"], body["height"]) == (1200, 1600)
    assert body["variants"]["small"]["height"] == 93


def test_animated_gif_sizes_stay_animated(client):
    body = upload(client, samples.gif_animated(), name="a.gif", folder="rooms", sizes=["small-8-0"])
    img = fetch(client, body["variants"]["small"]["url"])
    assert img.size == (8, 8) and img.n_frames == 3


def test_transparent_png_sizes_keep_alpha(client):
    out = io.BytesIO()
    Image.new("RGBA", (400, 200), (255, 0, 0, 0)).save(out, "PNG")
    body = upload(client, out.getvalue(), name="logo.png", folder="hotels", sizes=["small-100-0"])
    img = fetch(client, body["variants"]["small"]["url"])
    assert img.mode == "RGBA" and img.getpixel((0, 0))[3] == 0


def test_invalid_sizes_in_token_are_refused(client):
    with pytest.raises(ValueError):
        create_token("demo", SECRET, ORIGIN, sizes=["orig-10-10"])


@pytest.mark.skipif(not __import__("shutil").which("ffmpeg"), reason="ffmpeg not installed")
def test_video_goes_to_folder_orig(tmp_path):
    import time
    env = {
        "UPLOADER_HASH_KEY": "k" * 40,
        "UPLOADER_CLIENTS": json.dumps({"demo": {"secret": SECRET, "origins": [ORIGIN]}}),
        "UPLOADER_STORAGE_DIR": str(tmp_path / "f"),
        "UPLOADER_CDN_BASE_URL": CDN + "/f",
        "UPLOADER_VIDEO_MAX_SIDE": "160",
        "UPLOADER_VIDEO_THREADS": "2",
    }
    client = TestClient(create_app(load_settings(env)))
    token = create_token("demo", SECRET, ORIGIN, subject="user-1", folder="rooms", sizes=ROOM_SIZES)
    headers = {"Authorization": "Bearer " + token}
    clip = samples.video(tmp_path / "tour.mp4")
    job = client.post("/api/v1/files", content=clip.read_bytes(), headers={**headers, "X-File-Name": "tour.mp4"}).json()["job"]
    for _ in range(300):
        body = client.get(f"/api/v1/jobs/{job}", headers=headers).json()
        if body["status"] in ("done", "error"):
            break
        time.sleep(0.1)
    assert body["status"] == "done", body
    assert body["file"]["key"].startswith("f/rooms/orig/") and "variants" not in body["file"]
