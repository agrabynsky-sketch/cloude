"""Content-addressed storage: files are named by a keyed hash of their bytes.

name = HMAC-SHA256(UPLOADER_HASH_KEY, sanitized bytes), laid out as
ab/cd/abcd...ef.<ext>. The key makes names unguessable even for someone who
has a copy of the file, while identical uploads still deduplicate.
The user's original filename never touches the filesystem.
"""

import hashlib
import hmac
import os
import re
import tempfile
from pathlib import Path

from .validators import FILE_TYPES

_EXT_PATTERN = "|".join(re.escape(e) for e in sorted(FILE_TYPES, key=len, reverse=True))
STORED_PATH_RE = re.compile(rf"^([0-9a-f]{{2}})/([0-9a-f]{{2}})/([0-9a-f]{{64}})\.({_EXT_PATTERN})$")


class Storage:
    def __init__(self, root, hash_key, base_url):
        self.root = Path(root)
        self.hash_key = hash_key
        self.base_url = base_url.rstrip("/")

    def relative_path(self, data, ext):
        digest = hmac.new(self.hash_key, data, hashlib.sha256).hexdigest()
        return digest, f"{digest[:2]}/{digest[2:4]}/{digest}.{ext}"

    def url(self, rel):
        return f"{self.base_url}/{rel}"

    def save(self, data, ext):
        """Store `data`; returns (digest, relative path). Idempotent."""
        digest, rel = self.relative_path(data, ext)
        target = self.root / rel
        if target.exists():
            return digest, rel
        target.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        fd, tmp = tempfile.mkstemp(dir=target.parent, prefix=".upload-")
        try:
            with os.fdopen(fd, "wb") as fh:
                fh.write(data)
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
        return digest, rel

    def resolve(self, rel):
        """Path for a stored file, or None. Only exact hash paths are accepted."""
        m = STORED_PATH_RE.match(rel)
        if not m or m.group(3)[:2] != m.group(1) or m.group(3)[2:4] != m.group(2):
            return None
        path = self.root / rel
        return path if path.is_file() and not path.is_symlink() else None
