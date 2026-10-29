"""Credentials minted by the calling application's backend with its client secret.

Everything here is produced by the parent app's *server* (the secret never
reaches a browser) and is easy to reproduce in any language; see README.md.

Tokens:  base64url(json payload) "." base64url(HMAC-SHA256(secret, first part))

Payload fields:
    cid        client id (selects the shared secret on the CDN side)   required
    origin     exact origin of the page that uses the token            required
    exp        unix time the token stops working                      required
    jti        random id, used to count files uploaded with the token  required
    sub        opaque user id (logged, bound into receipts)            optional
    scope      "upload" (default) or "read"
  upload tokens:
    max_files  files allowed per token (capped by the server)          optional
    types      subset of ["image", "document", "archive", "video"]     optional
    access     "public" | "signed" | "private" for the stored files    optional
    folder     folder for the files, e.g. "rooms" or "photos/rooms"     optional
    sizes      image sizes to generate, e.g. ["small-70-0", "big-1200-800",
               "preview_panel-100-100-crop"] (see parse_sizes)          optional
    path       sub-folders instead of the hash shard, e.g. "10/10991"   optional
  read tokens (open "private" files):
    files      list of file keys the token may read                    required

Signed links (open "signed" files without any token):
    https://cdn/<key>?exp=<unix time>&sig=<b64url HMAC>[&ip=1][&name=<file name>]
    sig = HMAC-SHA256(link key, "<key>\n<exp>\n<client IP if ip=1>\n<name>")
    link key = HMAC-SHA256(client secret, "unit-uploader:link:v1")

Receipts prove to the app backend that a file key was uploaded by a user:
    receipt = b64url(HMAC-SHA256(receipt key, "<key>\n<sub>"))
    receipt key = HMAC-SHA256(client secret, "unit-uploader:receipt:v1")

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
from urllib.parse import quote, urlencode

MAX_TOKEN_LENGTH = 8192
MAX_READ_FILES = 100
B64URL_RE = re.compile(r"^[A-Za-z0-9_-]+$")
ORIGIN_RE = re.compile(r"^https?://[A-Za-z0-9.-]+(:\d{1,5})?$")
CLIENT_ID_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,31}$")
CATEGORIES = ("image", "document", "archive", "video")
ACCESS_LEVELS = ("public", "signed", "private")
SEGMENT_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,63}$")
SIZE_NAME_RE = re.compile(r"^[a-z0-9][a-z0-9_]{0,31}$")
MAX_SIZES = 10
# <s|p>/<client>/[<folder>/<size>/]<path or hash shard>/<hash>.<ext>
PROTECTED_KEY_RE = re.compile(
    r"^(s|p)/([a-z0-9][a-z0-9_-]{0,31})/(?:[a-z0-9][a-z0-9_-]{0,63}/){1,10}[0-9a-f]{64}\.[a-z0-9.]{2,6}$")
LINK_KEY_LABEL = b"unit-uploader:link:v1"
RECEIPT_KEY_LABEL = b"unit-uploader:receipt:v1"


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
    access: str = "public"
    scope: str = "upload"
    files: tuple = ()
    folder: str = ""
    path: str = ""
    sizes: tuple = ()          # ((name, width, height, mode), ...)


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


def parse_segments(value, what="folder", max_segments=3):
    """"photos/rooms" -> "photos/rooms"; lowercase letters, digits, "_" and "-" only."""
    if value in (None, ""):
        return ""
    if not isinstance(value, str) or len(value) > 200:
        raise ValueError(f"Invalid {what}")
    parts = value.strip("/").split("/")
    if not 1 <= len(parts) <= max_segments or not all(SEGMENT_RE.match(p) for p in parts):
        raise ValueError(f"Invalid {what} {value!r}")
    return "/".join(parts)


def parse_sizes(raw, max_side=4096):
    """Image sizes to generate, as ((name, width, height, mode), ...).

    Each entry is "name-width-height[-fit|-crop]" or
    {"name": ..., "width": ..., "height": ..., "mode": ...}.
    0 for width or height keeps the proportions. "fit" (default) fits the
    image inside the box; "crop" fills the box exactly, cutting the overflow
    from the centre. Images are never upscaled. "orig" is always stored.
    """
    if raw in (None, []):
        return ()
    if not isinstance(raw, list) or len(raw) > MAX_SIZES:
        raise ValueError(f"Sizes must be a list of at most {MAX_SIZES}")
    out, names = [], set()
    for item in raw:
        if isinstance(item, str):
            parts = item.split("-")
            if len(parts) not in (3, 4) or not all(p.isdigit() and len(p) <= 5 for p in parts[1:3]):
                raise ValueError(f"Invalid size {item!r}, expected name-width-height[-crop]")
            name, width, height = parts[0], int(parts[1]), int(parts[2])
            mode = parts[3] if len(parts) == 4 else "fit"
        elif isinstance(item, dict):
            name, width, height = item.get("name"), item.get("width", 0), item.get("height", 0)
            mode = item.get("mode", "fit")
            if any(not isinstance(v, int) or isinstance(v, bool) for v in (width, height)):
                raise ValueError(f"Invalid size {item!r}")
        else:
            raise ValueError(f"Invalid size {item!r}")
        if not isinstance(name, str) or not SIZE_NAME_RE.match(name) or name == "orig" or name in names:
            raise ValueError(f"Invalid or duplicate size name {name!r}")
        if mode not in ("fit", "crop"):
            raise ValueError(f"Size {name!r}: mode must be fit or crop")
        if not (0 <= width <= max_side and 0 <= height <= max_side) or width == height == 0:
            raise ValueError(f"Size {name!r}: width/height must be 0..{max_side}, not both 0")
        if mode == "crop" and 0 in (width, height):
            raise ValueError(f"Size {name!r}: crop needs both width and height")
        names.add(name)
        out.append((name, width, height, mode))
    return tuple(out)


def _derived_key(secret, label):
    return hmac.new(secret.encode("utf-8"), label, hashlib.sha256).digest()


def _encode(payload, secret):
    body = _b64encode(json.dumps(payload, separators=(",", ":")).encode("utf-8"))
    return f"{body}.{_b64encode(_sign(secret, body))}"


def create_token(client_id, secret, origin, *, ttl=900, subject="", max_files=20, types=None,
                 access=None, folder=None, sizes=None, path=None):
    """Upload token. `access` decides who can open the stored files; `folder`,
    `sizes` and `path` decide where they go and which image sizes are made."""
    parse_segments(folder, "folder", 3)
    parse_segments(path, "path", 4)
    parse_sizes(sizes)
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
    if access:
        payload["access"] = access
    if folder:
        payload["folder"] = folder
    if sizes:
        payload["sizes"] = list(sizes)
    if path:
        payload["path"] = path
    return _encode(payload, secret)


def create_read_token(client_id, secret, origin, files, *, ttl=300, subject=""):
    """Token that lets the page at `origin` fetch the listed private files."""
    payload = {
        "cid": client_id,
        "origin": origin,
        "exp": int(time.time()) + int(ttl),
        "jti": secrets.token_urlsafe(16),
        "scope": "read",
        "files": list(files),
    }
    if subject:
        payload["sub"] = str(subject)
    return _encode(payload, secret)


def _link_message(key, exp, ip, name):
    return f"{key}\n{exp}\n{ip}\n{name}".encode("utf-8")


def sign_link(key, client_id, secret, base_url, *, ttl=300, ip=None, name=None, now=None):
    """Temporary link to a "signed" file. With `ip`, it only works from that address;
    `name` sets the file name a download is saved under."""
    match = PROTECTED_KEY_RE.match(key)
    if not match or match.group(1) != "s" or match.group(2) != client_id:
        raise ValueError("not a signed-access file key of this client")
    exp = (int(time.time()) if now is None else now) + int(ttl)
    mac = hmac.new(_derived_key(secret, LINK_KEY_LABEL), _link_message(key, exp, ip or "", name or ""),
                   hashlib.sha256)
    params = {"exp": exp, "sig": _b64encode(mac.digest())}
    if ip:
        params["ip"] = "1"
    if name:
        params["name"] = name
    return f"{base_url.rstrip('/')}/{key}?{urlencode(params, quote_via=quote)}"


def verify_link(key, client, params, client_ip, *, max_ttl, now=None):
    """Check the query of a signed link; raise TokenError when it is not valid."""
    now = int(time.time()) if now is None else now
    exp, sig = params.get("exp", ""), params.get("sig", "")
    if not exp.isdigit() or len(exp) > 12 or not sig or len(sig) > 100:
        raise TokenError("invalid_link", "Link is not signed")
    name = params.get("name", "")
    ip = client_ip if params.get("ip") == "1" else ""
    expected = hmac.new(_derived_key(client.secret, LINK_KEY_LABEL),
                        _link_message(key, int(exp), ip, name), hashlib.sha256).digest()
    try:
        valid = hmac.compare_digest(expected, _b64decode(sig))
    except TokenError:
        valid = False
    if not valid:
        raise TokenError("invalid_link", "Link signature is not valid")
    if int(exp) <= now:
        raise TokenError("link_expired", "Link has expired")
    if int(exp) - now > max_ttl:
        raise TokenError("invalid_link", "Link lifetime is too long")
    return name


def receipt_for(key, subject, secret):
    mac = hmac.new(_derived_key(secret, RECEIPT_KEY_LABEL), f"{key}\n{subject}".encode("utf-8"),
                   hashlib.sha256)
    return _b64encode(mac.digest())


def verify_receipt(key, subject, receipt, secret):
    """True when `key` was uploaded through an upload token whose sub was `subject`."""
    return hmac.compare_digest(receipt_for(key, subject, secret), str(receipt))


def verify_token(token, clients, *, max_ttl, max_files_cap, scope="upload", max_variant_side=4096,
                 now=None):
    """Return Claims for a valid token of the given scope or raise TokenError.

    `clients` maps client id -> object with `.secret`, `.origins` and `.default_access`.
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
    allows = getattr(client, "allows", None)
    if (not isinstance(origin, str) or not ORIGIN_RE.match(origin)
            or not (allows(origin) if allows else origin in client.origins)):
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
    # An upload token can never be used to read files and vice versa.
    if payload.get("scope", "upload") != scope:
        raise TokenError("invalid_token", f"Not a {scope} token")
    access = payload.get("access", getattr(client, "default_access", "public"))
    if access not in ACCESS_LEVELS:
        raise TokenError("invalid_token", "Invalid access")
    try:
        folder = parse_segments(payload.get("folder"), "folder", 3)
        path = parse_segments(payload.get("path"), "path", 4)
        sizes = parse_sizes(payload.get("sizes"), max_variant_side)
    except ValueError as exc:
        raise TokenError("invalid_token", str(exc)) from None
    files = ()
    if scope == "read":
        files = payload.get("files")
        if (not isinstance(files, list) or not 0 < len(files) <= MAX_READ_FILES
                or any(not isinstance(k, str) or not PROTECTED_KEY_RE.match(k)
                       or k.split("/")[1] != payload["cid"] for k in files)):
            raise TokenError("invalid_token", "Invalid files")
        files = tuple(files)

    return Claims(
        client_id=payload["cid"],
        origin=origin,
        expires_at=exp,
        token_id=jti,
        subject=subject,
        max_files=min(max_files, max_files_cap),
        types=tuple(t for t in CATEGORIES if t in types),
        access=access,
        scope=scope,
        files=files,
        folder=folder,
        path=path,
        sizes=sizes,
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
    parser.add_argument("--access", choices=ACCESS_LEVELS)
    parser.add_argument("--folder")
    parser.add_argument("--path")
    parser.add_argument("--size", action="append", dest="sizes", metavar="NAME-W-H[-crop]")
    args = parser.parse_args()
    print(create_token(args.client, args.secret, args.origin, ttl=args.ttl, subject=args.sub,
                       max_files=args.max_files, types=args.types, access=args.access,
                       folder=args.folder, sizes=args.sizes, path=args.path))


if __name__ == "__main__":
    main()
