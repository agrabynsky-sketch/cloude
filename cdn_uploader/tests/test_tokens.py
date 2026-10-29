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


@pytest.mark.parametrize("token", ["", "abc", "a.b.c", "!!!.???", "x" * 5000])
def test_garbage_rejected(token):
    assert code_of(token) == "invalid_token"
