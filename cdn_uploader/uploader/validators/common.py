"""Shared types for the content validators.

Every validator takes the raw bytes of one file and either raises
ValidationError or returns the bytes that are safe to store. Validators
that can re-encode (images, archives, office files) return a rebuilt copy,
so anything a parser ignored (appended payloads, metadata, zip prefixes)
never reaches the CDN.
"""

import re
import unicodedata
from dataclasses import dataclass, field

MB = 1024 * 1024


class ValidationError(Exception):
    """The file was rejected. `code` is a stable machine-readable reason."""

    def __init__(self, code, message):
        super().__init__(message)
        self.code = code
        self.message = message


@dataclass(frozen=True)
class Limits:
    max_file_size: int = 50 * MB
    # Total bytes any single upload may expand to (archive/office/pdf
    # decompression, nested archives included). Guards against zip bombs.
    max_uncompressed: int = 200 * MB
    archive_max_entries: int = 1000
    # How many archives may be nested inside an archive (0 = none).
    archive_max_nesting: int = 1
    # Compression ratio above which a single entry is treated as a bomb.
    max_compression_ratio: int = 200
    image_max_pixels: int = 40_000_000
    image_max_side: int = 20_000
    image_max_frames: int = 300
    image_max_total_pixels: int = 400_000_000
    office_max_entries: int = 5000
    jpeg_quality: int = 90


class Budget:
    """Byte budget shared by everything decompressed while validating one upload."""

    def __init__(self, total):
        self.remaining = total

    def consume(self, n):
        if n > self.remaining:
            raise ValidationError(
                "too_large_uncompressed",
                "File expands to more data than allowed (possible decompression bomb)",
            )
        self.remaining -= n


@dataclass
class Context:
    limits: Limits
    budget: Budget
    archive_depth: int = 0
    # "outer.zip/inner/file.pdf" for messages about nested entries.
    path: str = ""

    def child(self, name, archive=False):
        return Context(
            limits=self.limits,
            budget=self.budget,
            archive_depth=self.archive_depth + (1 if archive else 0),
            path=f"{self.path}/{name}" if self.path else name,
        )

    def fail(self, code, message):
        if self.path:
            message = f"{self.path}: {message}"
        raise ValidationError(code, message)


@dataclass
class Sanitized:
    data: bytes
    ext: str
    mime: str
    category: str
    notes: list = field(default_factory=list)


# Magic numbers of things that must never be stored, whatever they claim to be.
EXECUTABLE_MAGIC = (
    b"MZ",                     # Windows PE / DOS
    b"\x7fELF",                # Linux ELF
    b"\xfe\xed\xfa\xce", b"\xfe\xed\xfa\xcf",  # Mach-O
    b"\xce\xfa\xed\xfe", b"\xcf\xfa\xed\xfe",
    b"\xca\xfe\xba\xbe",       # Mach-O fat / Java class
    b"#!",                     # shebang scripts
    b"\x00asm",                # WebAssembly
    b"dex\n",                  # Android DEX
)
OLE_MAGIC = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"  # legacy Office / MSI / embedded OLE

# Bidi overrides etc. let "gpj.exe" render as "exe.jpg"; never keep them.
_INVISIBLE_RE = re.compile(
    "[\u0000-\u001f\u007f-\u009f​-‏‪-‮⁠-⁩﻿]"
)


def looks_executable(data):
    return data.startswith(EXECUTABLE_MAGIC)


def clean_display_name(name, max_len=200):
    """Make a user-supplied filename safe to echo back (never used on disk)."""
    name = unicodedata.normalize("NFC", name or "")
    name = _INVISIBLE_RE.sub("", name)
    name = name.replace("\\", "/").rsplit("/", 1)[-1]
    name = re.sub(r"[<>:\"|?*]", "_", name).strip(" .")
    if len(name) > max_len:
        stem, dot, ext = name.rpartition(".")
        if dot and len(ext) <= 10:
            name = stem[: max_len - len(ext) - 1] + "." + ext
        else:
            name = name[:max_len]
    return name or "file"


def clean_entry_path(raw):
    """Validate a path stored inside an archive; return it normalized or None if unsafe."""
    name = unicodedata.normalize("NFC", raw).replace("\\", "/")
    if not name or "\x00" in name or _INVISIBLE_RE.search(name):
        return None
    if name.startswith("/") or re.match(r"^[A-Za-z]:", name):
        return None
    parts = [p for p in name.split("/") if p not in ("", ".")]
    if not parts or any(p == ".." for p in parts):
        return None
    if len(parts) > 32 or any(len(p.encode("utf-8")) > 255 for p in parts):
        return None
    if any(re.search(r"[<>:\"|?*]", p) for p in parts):
        return None
    return "/".join(parts)
