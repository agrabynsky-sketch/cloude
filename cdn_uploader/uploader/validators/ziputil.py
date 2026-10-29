"""Defensive ZIP reading and rebuilding, shared by archives and office files."""

import io
import stat
import zipfile
import zlib
from dataclasses import dataclass

from .common import clean_entry_path

ALLOWED_METHODS = (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED)


@dataclass
class Entry:
    name: str
    data: bytes  # b"" for directories
    is_dir: bool
    date_time: tuple


def read_zip(data, ctx, max_entries):
    """Return the entries of a zip after checking it is safe to unpack.

    Rejects: data before the first local header (polyglots), path traversal,
    absolute paths, symlinks/devices, encryption, unusual compression
    methods, duplicate names, and anything that would exceed the
    decompression budget.
    """
    if not data.startswith(b"PK\x03\x04"):
        ctx.fail("content_mismatch", "Content is not a valid ZIP container")
    try:
        zf = zipfile.ZipFile(io.BytesIO(data))
        infos = zf.infolist()
    except (zipfile.BadZipFile, zipfile.LargeZipFile, ValueError, OSError):
        ctx.fail("invalid_archive", "ZIP structure is corrupt")
    if not infos:
        ctx.fail("invalid_archive", "Archive is empty")
    if len(infos) > max_entries:
        ctx.fail("archive_too_many_entries", f"Archive has more than {max_entries} entries")

    limits = ctx.limits
    entries, seen = [], set()
    for info in infos:
        name = clean_entry_path(info.filename)
        if name is None:
            ctx.fail("unsafe_path", f"Unsafe path inside archive: {info.filename[:100]!r}")
        if name.casefold() in seen:
            ctx.fail("unsafe_path", f"Duplicate entry inside archive: {name[:100]!r}")
        seen.add(name.casefold())
        if info.flag_bits & 0x1:
            ctx.fail("encrypted", "Password-protected archives cannot be checked")
        mode = info.external_attr >> 16
        if stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR):
            ctx.fail("unsafe_path", f"Symlinks and special files are not allowed: {name[:100]!r}")
        date_time = max(info.date_time, (1980, 1, 1, 0, 0, 0))
        if info.is_dir():
            entries.append(Entry(name, b"", True, date_time))
            continue
        if info.compress_type not in ALLOWED_METHODS:
            ctx.fail("invalid_archive", "Archive uses an unsupported compression method")
        if info.file_size > limits.max_file_size:
            ctx.fail("file_too_large", f"Entry {name[:100]!r} is too large")
        if info.file_size > 1024 * 1024 and (
            info.compress_size == 0
            or info.file_size / info.compress_size > limits.max_compression_ratio
        ):
            ctx.fail("too_large_uncompressed", "Archive looks like a decompression bomb")
        ctx.budget.consume(info.file_size)
        try:
            with zf.open(info) as fh:
                content = fh.read(info.file_size + 1)
        except (zipfile.BadZipFile, zlib.error, EOFError, OSError, RuntimeError,
                NotImplementedError, ValueError):
            ctx.fail("invalid_archive", f"Entry {name[:100]!r} is corrupt")
        if len(content) != info.file_size:
            ctx.fail("invalid_archive", f"Entry {name[:100]!r} has an inconsistent size")
        entries.append(Entry(name, content, False, date_time))
    return entries


def build_zip(entries, stored_names=()):
    """Write a fresh zip from validated entries (no comments, extra fields or prefixes)."""
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w") as zf:
        for entry in entries:
            if entry.is_dir:
                info = zipfile.ZipInfo(entry.name + "/", entry.date_time)
                info.external_attr = (stat.S_IFDIR | 0o755) << 16
                zf.writestr(info, b"")
                continue
            info = zipfile.ZipInfo(entry.name, entry.date_time)
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = (
                zipfile.ZIP_STORED if entry.name in stored_names else zipfile.ZIP_DEFLATED
            )
            zf.writestr(info, entry.data)
    return out.getvalue()
