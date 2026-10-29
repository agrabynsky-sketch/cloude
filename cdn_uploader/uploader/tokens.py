"""Short-lived upload tokens, signed by the calling application's backend.

Format:  base64url(json payload) "." base64url(HMAC-SHA256(secret, first part))

Payload fields:
    cid        client id (selects the shared secret on the CDN side)   required
    origin     exact origin of the page that opens the uploader        required
    exp        unix time the token stops working                      required
    jti        random id, used to count files uploaded with the token  required
    sub        opaque user id, only logged                             optional
    max_files  files allowed per token (capped by the server)          optional
    types      subset of ["image", "document", "archive"]              optional

The token is minted by the parent app's *server* (the secret never reaches
a browser). It is easy to produce in any language; see README.md.

CLI for testing:
    python -m uploader.tokens --client demo --secret ... --origin http://localhost:8001
"""

import argparse
import base64
import binascii
import hashlib
import hmac
import json
import re
import secrets
import time
from dataclasses import dataclass

MAX_TOKEN_LENGTH = 2048
B64URL_RE = re.compile(r"^[A-Za-z0-9_-]+$")
ORIGIN_RE = re.compile(r"^https?://[A-Za-z0-9.-]+(:\d{1,5})?$")
CATEGORIES = ("image", "document", "archive")


class TokenError(Exception):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code
        self.message = message


@dataclass(frozen=True)
class Claims:
    client_id: str
    origin: str
    expires_at: int
    token_id: str
    subject: str
    max_files: int
    types: tuple


def _b64encode(raw):
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def _b64decode(text):
    if not B64URL_RE.match(text):
        raise TokenError("invalid_token", "Malformed token")
    try:
        return base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))
    except (binascii.Error, ValueError):
        raise TokenError("invalid_token", "Malformed token") from None


def _sign(secret, message):
    return hmac.new(secret.encode("utf-8"), message.encode("ascii"), hashlib.sha256).digest()


def create_token(client_id, secret, origin, *, ttl=900, subject="", max_files=20, types=None):
    payload = {
        "cid": client_id,
        "origin": origin,
        "exp": int(time.time()) + int(ttl),
        "jti": secrets.token_urlsafe(16),
        "max_files": int(max_files),
    }
    if subject:
        payload["sub"] = str(subject)
    if types:
        payload["types"] = list(types)
    body = _b64encode(json.dumps(payload, separators=(",", ":")).encode("utf-8"))
    return f"{body}.{_b64encode(_sign(secret, body))}"


def verify_token(token, clients, *, max_ttl, max_files_cap, now=None):
    """Return Claims for a valid token or raise TokenError.

    `clients` maps client id -> object with `.secret` and `.origins`.
    """
    now = int(time.time()) if now is None else now
    if not token or len(token) > MAX_TOKEN_LENGTH or token.count(".") != 1:
        raise TokenError("invalid_token", "Malformed token")
    body, signature = token.split(".")
    try:
        payload = json.loads(_b64decode(body))
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise TokenError("invalid_token", "Malformed token") from None
    if not isinstance(payload, dict):
        raise TokenError("invalid_token", "Malformed token")

    client = clients.get(payload.get("cid")) if isinstance(payload.get("cid"), str) else None
    # Same error for "unknown client" and "bad signature": nothing to probe.
    if client is None or not hmac.compare_digest(_sign(client.secret, body), _b64decode(signature)):
        raise TokenError("invalid_token", "Invalid token signature")

    exp, origin, jti = payload.get("exp"), payload.get("origin"), payload.get("jti")
    if not isinstance(exp, int) or isinstance(exp, bool):
        raise TokenError("invalid_token", "Token has no expiry")
    if exp <= now:
        raise TokenError("token_expired", "Token has expired")
    if exp - now > max_ttl:
        raise TokenError("invalid_token", "Token lifetime is too long")
    if not isinstance(origin, str) or not ORIGIN_RE.match(origin) or origin not in client.origins:
        raise TokenError("origin_not_allowed", "Token origin is not allowed for this client")
    if not isinstance(jti, str) or not 8 <= len(jti) <= 128:
        raise TokenError("invalid_token", "Token has no id")

    max_files = payload.get("max_files", max_files_cap)
    if not isinstance(max_files, int) or isinstance(max_files, bool) or max_files < 1:
        raise TokenError("invalid_token", "Invalid max_files")
    types = payload.get("types", list(CATEGORIES))
    if not isinstance(types, list) or not types or any(t not in CATEGORIES for t in types):
        raise TokenError("invalid_token", "Invalid types")
    subject = payload.get("sub", "")
    if not isinstance(subject, str) or len(subject) > 200:
        raise TokenError("invalid_token", "Invalid sub")

    return Claims(
        client_id=payload["cid"],
        origin=origin,
        expires_at=exp,
        token_id=jti,
        subject=subject,
        max_files=min(max_files, max_files_cap),
        types=tuple(t for t in CATEGORIES if t in types),
    )


def main():
    parser = argparse.ArgumentParser(description="Mint an upload token (for testing)")
    parser.add_argument("--client", required=True)
    parser.add_argument("--secret", required=True)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--ttl", type=int, default=900)
    parser.add_argument("--sub", default="")
    parser.add_argument("--max-files", type=int, default=20)
    parser.add_argument("--types", nargs="*", choices=CATEGORIES)
    args = parser.parse_args()
    print(create_token(args.client, args.secret, args.origin, ttl=args.ttl,
                       subject=args.sub, max_files=args.max_files, types=args.types))


if __name__ == "__main__":
    main()
