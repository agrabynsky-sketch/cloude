import base64
import json
import time

import pytest

from uploader.config import Client
from uploader.tokens import TokenError, create_token, verify_token

SECRET = "s" * 40
CLIENTS = {"app": Client(secret=SECRET, origins=("https://app.unit.travel",))}


def verify(token, **kw):
    return verify_token(token, CLIENTS, max_ttl=3600, max_files_cap=50, **kw)


def code_of(token, **kw):
    with pytest.raises(TokenError) as info:
        verify(token, **kw)
    return info.value.code


def test_roundtrip():
    token = create_token("app", SECRET, "https://app.unit.travel", subject="u1", max_files=5, types=["image"])
    claims = verify(token)
    assert claims.origin == "https://app.unit.travel"
    assert claims.subject == "u1"
    assert claims.max_files == 5
    assert claims.types == ("image",)


def test_max_files_capped_by_server():
    token = create_token("app", SECRET, "https://app.unit.travel", max_files=10_000)
    assert verify(token).max_files == 50


def test_wrong_secret_rejected():
    assert code_of(create_token("app", "x" * 40, "https://app.unit.travel")) == "invalid_token"


def test_unknown_client_rejected():
    assert code_of(create_token("other", SECRET, "https://app.unit.travel")) == "invalid_token"


def test_tampered_payload_rejected():
    token = create_token("app", SECRET, "https://app.unit.travel", max_files=1)
    body, sig = token.split(".")
    payload = json.loads(base64.urlsafe_b64decode(body + "=" * (-len(body) % 4)))
    payload["max_files"] = 50
    forged = base64.urlsafe_b64encode(json.dumps(payload).encode()).rstrip(b"=").decode()
    assert code_of(f"{forged}.{sig}") == "invalid_token"


def test_expired_rejected():
    token = create_token("app", SECRET, "https://app.unit.travel", ttl=60)
    assert code_of(token, now=int(time.time()) + 61) == "token_expired"


def test_overlong_lifetime_rejected():
    token = create_token("app", SECRET, "https://app.unit.travel", ttl=86400)
    assert code_of(token) == "invalid_token"


def test_origin_must_be_registered_for_client():
    assert code_of(create_token("app", SECRET, "https://evil.example")) == "origin_not_allowed"


def test_app_clock_may_run_a_little_ahead():
    token = create_token("app", SECRET, "https://app.unit.travel", ttl=3600)
    assert verify(token, now=int(time.time()) - 30).origin == "https://app.unit.travel"
    assert code_of(token, now=int(time.time()) - 120) == "invalid_token"


def detail_of(token):
    with pytest.raises(TokenError) as info:
        verify(token)
    return info.value.message, info.value.detail


@pytest.mark.parametrize("token, message, detail", [
    (create_token("nope", SECRET, "https://app.unit.travel"), "Invalid token signature", "unknown client 'nope'"),
    (create_token("app", "x" * 40, "https://app.unit.travel"), "Invalid token signature",
     "signature does not match the secret of client 'app'"),
    (create_token("app", SECRET, "https://app.unit.travel", ttl=10800), "Token lifetime is too long",
     "at most 3600s allowed"),
    (create_token("app", SECRET, "http://agent.utravel.local"), "Token origin is not allowed for this client",
     "origin 'http://agent.utravel.local' is not registered for client 'app'"),
])
def test_rejections_explain_why_for_the_log(token, message, detail):
    # The browser gets the message (same for unknown client and wrong secret); the log gets the detail.
    got_message, got_detail = detail_of(token)
    assert got_message == message and detail in got_detail


@pytest.mark.parametrize("token", ["", "abc", "a.b.c", "!!!.???", "x" * 5000])
def test_garbage_rejected(token):
    assert code_of(token) == "invalid_token"


# ---- read tokens, signed links, receipts -------------------------------------

from uploader.tokens import (create_read_token, receipt_for, sign_link, verify_link,  # noqa: E402
                             verify_receipt)

KEY_S = "s/app/ab/cd/abcd" + "0" * 60 + ".pdf"
KEY_P = "p/app/ab/cd/abcd" + "1" * 60 + ".jpg"


def test_read_token_roundtrip_and_scope_separation():
    read = create_read_token("app", SECRET, "https://app.unit.travel", [KEY_P])
    claims = verify(read, scope="read")
    assert claims.files == (KEY_P,) and claims.scope == "read"
    # A read token cannot upload, an upload token cannot read.
    assert code_of(read) == "invalid_token"
    upload = create_token("app", SECRET, "https://app.unit.travel")
    assert code_of(upload, scope="read") == "invalid_token"


@pytest.mark.parametrize("files", [[], ["f/ab/cd/" + "0" * 64 + ".png"], ["p/other/ab/cd/" + "0" * 64 + ".png"],
                                   [KEY_P] * 101, ["../../etc/passwd"]])
def test_read_token_file_list_validated(files):
    token = create_read_token("app", SECRET, "https://app.unit.travel", files)
    assert code_of(token, scope="read") == "invalid_token"


def test_upload_access_claim():
    assert verify(create_token("app", SECRET, "https://app.unit.travel", access="private")).access == "private"
    assert verify(create_token("app", SECRET, "https://app.unit.travel")).access == "public"
    assert code_of(create_token("app", SECRET, "https://app.unit.travel", access="secret")) == "invalid_token"


def _params(link):
    from urllib.parse import parse_qsl, urlsplit
    return dict(parse_qsl(urlsplit(link).query))


def _link_code(key, params, ip="1.1.1.1", **kw):
    with pytest.raises(TokenError) as info:
        verify_link(key, CLIENTS["app"], params, ip, max_ttl=3600, **kw)
    return info.value.code


def test_signed_link_roundtrip_with_name():
    link = sign_link(KEY_S, "app", SECRET, "https://cdn.unit.travel", ttl=60, name="Паспорт.pdf")
    assert link.startswith("https://cdn.unit.travel/" + KEY_S + "?")
    assert verify_link(KEY_S, CLIENTS["app"], _params(link), "1.1.1.1", max_ttl=3600) == "Паспорт.pdf"


def test_signed_link_tampering_rejected():
    params = _params(sign_link(KEY_S, "app", SECRET, "https://cdn", ttl=60))
    assert _link_code(KEY_S.replace("abcd0", "abcd1"), params) == "invalid_link"      # other file
    assert _link_code(KEY_S, {**params, "exp": str(int(params["exp"]) + 3600)}) == "invalid_link"
    assert _link_code(KEY_S, {**params, "name": "x.exe"}) == "invalid_link"
    assert _link_code(KEY_S, {**params, "sig": "A" * 43}) == "invalid_link"
    assert _link_code(KEY_S, {"exp": params["exp"]}) == "invalid_link"


def test_signed_link_expiry_and_max_ttl():
    params = _params(sign_link(KEY_S, "app", SECRET, "https://cdn", ttl=60))
    assert _link_code(KEY_S, params, now=int(time.time()) + 61) == "link_expired"
    long = _params(sign_link(KEY_S, "app", SECRET, "https://cdn", ttl=7200))
    assert _link_code(KEY_S, long) == "invalid_link"


def test_ip_bound_link():
    params = _params(sign_link(KEY_S, "app", SECRET, "https://cdn", ttl=60, ip="1.1.1.1"))
    assert params["ip"] == "1" and "1.1.1.1" not in str(params)
    assert verify_link(KEY_S, CLIENTS["app"], params, "1.1.1.1", max_ttl=3600) == ""
    assert _link_code(KEY_S, params, ip="2.2.2.2") == "invalid_link"


def test_links_only_for_own_signed_files():
    with pytest.raises(ValueError):
        sign_link(KEY_P, "app", SECRET, "https://cdn")          # private: token only
    with pytest.raises(ValueError):
        sign_link(KEY_S.replace("s/app", "s/other"), "app", SECRET, "https://cdn")


def test_receipt():
    r = receipt_for(KEY_P, "user-1", SECRET)
    assert verify_receipt(KEY_P, "user-1", r, SECRET)
    assert not verify_receipt(KEY_P, "user-2", r, SECRET)
    assert not verify_receipt(KEY_S, "user-1", r, SECRET)
