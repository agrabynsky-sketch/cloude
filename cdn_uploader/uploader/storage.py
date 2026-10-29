"""Content-addressed storage: files are named by a keyed hash of their bytes.

Every stored file has a key, which is what an application keeps in its database:

    f/ab/cd/<hash>.<ext>          public: served by nginx as is
    s/<client>/ab/cd/<hash>.<ext> "signed": only through a temporary signed link
    p/<client>/ab/cd/<hash>.<ext> "private": only with a read token of that client

hash = HMAC-SHA256(UPLOADER_HASH_KEY, [access + client +] sanitized bytes). The
key makes names unguessable even for someone who has a copy of the file,
while identical uploads still deduplicate. Protected files live in their
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
    rf"^(?:f|([sp])/([a-z0-9][a-z0-9_-]{{0,31}}))/([0-9a-f]{{2}})/([0-9a-f]{{2}})/([0-9a-f]{{64}})"
    rf"\.({_EXT_PATTERN})$")
ACCESS_PREFIX = {"public": "f", "signed": "s", "private": "p"}


class Storage:
    def __init__(self, public_root, protected_root, hash_key):
        self.public_root = Path(public_root)
        self.protected_root = Path(protected_root)
        self.hash_key = hash_key

    def _mac(self, access, client_id):
        mac = hmac.new(self.hash_key, digestmod=hashlib.sha256)
        if access != "public":
            mac.update(f"{access}:{client_id}\0".encode("utf-8"))
        return mac

    @staticmethod
    def key_for(digest, ext, access="public", client_id=None):
        prefix = "f" if access == "public" else f"{ACCESS_PREFIX[access]}/{client_id}"
        return f"{prefix}/{digest[:2]}/{digest[2:4]}/{digest}.{ext}"

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

    def save(self, data, ext, access="public", client_id=None):
        """Store `data`; returns (digest, key). Idempotent."""
        mac = self._mac(access, client_id)
        mac.update(data)
        digest = mac.hexdigest()
        key = self.key_for(digest, ext, access, client_id)
        target = self._path(key)
        if not target.exists():
            self._write(target, lambda fh: fh.write(data))
        return digest, key

    def save_file(self, src, ext, access="public", client_id=None):
        """Store the file at `src` (consumed: moved when possible)."""
        mac = self._mac(access, client_id)
        with open(src, "rb") as fh:
            for chunk in iter(lambda: fh.read(1024 * 1024), b""):
                mac.update(chunk)
        digest = mac.hexdigest()
        key = self.key_for(digest, ext, access, client_id)
        target = self._path(key)
        if target.exists():
            return digest, key
        target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        os.chmod(src, 0o644)
        try:
            os.replace(src, target)  # same filesystem: atomic and free
        except OSError:
            with open(src, "rb") as fh:
                self._write(target, lambda out: shutil.copyfileobj(fh, out, 1024 * 1024))
        return digest, key

    def resolve(self, key):
        """Path of a stored file, or None. Only exact, well-formed keys are accepted."""
        m = KEY_RE.match(key)
        if not m or m.group(5)[:2] != m.group(3) or m.group(5)[2:4] != m.group(4):
            return None
        path = self._path(key)
        return path if path.is_file() and not path.is_symlink() else None
