"""Content-addressed storage: files are named by a keyed hash of their bytes.

Every stored file has a key, which is what an application keeps in its database:

    f/ab/cd/<hash>.<ext>          public: served by nginx as is
    s/<client>/ab/cd/<hash>.<ext> "signed": only through a temporary signed link
    p/<client>/ab/cd/<hash>.<ext> "private": only with a read token of that client

Protected files uploaded on a white-label site of a shared client go to
<s|p>/<client>.<site>/... and open only on that site.

With a folder and/or image sizes the layout is <folder>/<size>/<path>/<hash>.<ext>,
"orig" being the original, e.g. f/rooms/orig/ab/cd/<hash>.jpg and
f/rooms/small/ab/cd/<hash>.jpg; <path> is the hash shard unless the token
gives one (e.g. "10/10991"). All sizes of one upload share the same name.

hash = HMAC-SHA256(UPLOADER_HASH_KEY, [access + client[.site] +] [layout +] sanitized
bytes). The key makes names unguessable even for someone who has a copy of
the file, while identical uploads with the same settings still deduplicate;
the size settings are part of it, so "small" never means two different things. Protected files live in their
own directory that nginx never serves directly, and the client id in the
path ties each one to the secret that may grant access to it.
The user's original filename never touches the filesystem.
"""

import hashlib
import hmac
import os
import re
import shutil
import tempfile
from pathlib import Path

from .validators import FILE_TYPES

_EXT_PATTERN = "|".join(re.escape(e) for e in sorted(FILE_TYPES, key=len, reverse=True))
KEY_RE = re.compile(
    rf"^(?:f|[sp]/[a-z0-9][a-z0-9_-]{{0,31}}(?:\.[a-z0-9][a-z0-9_-]{{0,31}})?)/"
    rf"(?:[a-z0-9][a-z0-9_-]{{0,63}}/){{1,10}}[0-9a-f]{{64}}"
    rf"\.(?:{_EXT_PATTERN})$")
ACCESS_PREFIX = {"public": "f", "signed": "s", "private": "p"}


def namespace(client_id, site=""):
    return f"{client_id}.{site}" if site else client_id


class Storage:
    def __init__(self, public_root, protected_root, hash_key):
        self.public_root = Path(public_root)
        self.protected_root = Path(protected_root)
        self.hash_key = hash_key

    def _mac(self, access, client_id, layout, site):
        mac = hmac.new(self.hash_key, digestmod=hashlib.sha256)
        if access != "public":
            mac.update(f"{access}:{namespace(client_id, site)}\0".encode("utf-8"))
        if layout:
            mac.update(f"layout:{layout}\0".encode("utf-8"))
        return mac

    def digest(self, data, access="public", client_id=None, layout="", site=""):
        mac = self._mac(access, client_id, layout, site)
        mac.update(data)
        return mac.hexdigest()

    def digest_file(self, src, access="public", client_id=None, layout="", site=""):
        mac = self._mac(access, client_id, layout, site)
        with open(src, "rb") as fh:
            for chunk in iter(lambda: fh.read(1024 * 1024), b""):
                mac.update(chunk)
        return mac.hexdigest()

    @staticmethod
    def key_for(digest, ext, access="public", client_id=None, folder="", size="", path="", site=""):
        parts = ["f" if access == "public" else f"{ACCESS_PREFIX[access]}/{namespace(client_id, site)}"]
        parts += [p for p in (folder, size) if p]
        parts.append(path or f"{digest[:2]}/{digest[2:4]}")
        return "/".join(parts) + f"/{digest}.{ext}"

    def _path(self, key):
        return (self.public_root / key[2:]) if key.startswith("f/") else (self.protected_root / key)

    def _write(self, target, write):
        target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        fd, tmp = tempfile.mkstemp(dir=target.parent, prefix=".upload-")
        try:
            with os.fdopen(fd, "wb") as fh:
                write(fh)
                fh.flush()
                os.fsync(fh.fileno())
            os.chmod(tmp, 0o644)
            os.replace(tmp, target)
        except BaseException:
            try:
                os.unlink(tmp)
            except FileNotFoundError:
                pass
            raise

    def put(self, key, data):
        """Store `data` under `key` unless it is there already (keys are content-derived)."""
        target = self._path(key)
        if not target.exists():
            self._write(target, lambda fh: fh.write(data))

    def put_file(self, key, src):
        """Store the file at `src` under `key` (consumed: moved when possible)."""
        target = self._path(key)
        if target.exists():
            return
        target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        os.chmod(src, 0o644)
        try:
            os.replace(src, target)  # same filesystem: atomic and free
        except OSError:
            with open(src, "rb") as fh:
                self._write(target, lambda out: shutil.copyfileobj(fh, out, 1024 * 1024))

    def resolve(self, key):
        """Path of a stored file, or None. Only exact, well-formed keys are accepted."""
        if not KEY_RE.match(key):
            return None
        path = self._path(key)
        return path if path.is_file() and not path.is_symlink() else None
