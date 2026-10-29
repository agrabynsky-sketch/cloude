"""Archives (zip, tar, tar.gz): every entry is validated like a standalone upload.

The archive is accepted only if each file inside it is an allowed type and
passes its own validator. A new archive is then written from the sanitized
entries, so the stored archive holds exactly what was checked: no
symlinks, no traversal paths, no owner/permission bits, no hidden prefix
or comment data. rar/7z are not supported because the standard library
cannot inspect them.
"""

import calendar
import gzip
import io
import tarfile
import time
import zlib

from .common import clean_entry_path
from .ziputil import Entry, build_zip, read_zip

# OS junk that is silently dropped instead of failing the whole archive.
JUNK_NAMES = {".ds_store", "thumbs.db", "desktop.ini"}


def _is_junk(name):
    parts = name.lower().split("/")
    return parts[0] == "__macosx" or parts[-1] in JUNK_NAMES or parts[-1].startswith("._")


def _sanitize_entries(entries, ctx):
    from . import sanitize  # circular: archives are themselves a registered type

    if ctx.archive_depth >= ctx.limits.archive_max_nesting + 1:
        ctx.fail("archive_nesting", "Archives nested too deeply")
    kept = []
    for entry in entries:
        if _is_junk(entry.name):
            continue
        if not entry.is_dir:
            result = sanitize(entry.name, entry.data, ctx.child(entry.name, archive=True))
            entry.data = result.data
        kept.append(entry)
    if not any(not e.is_dir for e in kept):
        ctx.fail("invalid_archive", "Archive contains no files")
    return kept


def validate_zip(data, ctx):
    entries = read_zip(data, ctx, ctx.limits.archive_max_entries)
    return build_zip(_sanitize_entries(entries, ctx))


def _gunzip(data, ctx):
    if not data.startswith(b"\x1f\x8b"):
        ctx.fail("content_mismatch", "Content is not a gzip-compressed archive")
    # Decompress up front with a hard cap: tarfile would otherwise happily
    # inflate gigabytes while seeking over a crafted member.
    out = bytearray()
    d = zlib.decompressobj(wbits=16 + zlib.MAX_WBITS)
    pending = data
    try:
        while True:
            chunk = d.decompress(pending, 1024 * 1024)
            ctx.budget.consume(len(chunk))
            out += chunk
            if d.eof:
                break
            pending = d.unconsumed_tail
            if not pending and not chunk:
                ctx.fail("invalid_archive", "Archive is truncated")
    except zlib.error:
        ctx.fail("invalid_archive", "Archive is corrupt")
    if d.unused_data.strip(b"\x00"):
        ctx.fail("invalid_archive", "Archive has data appended after its end")
    return bytes(out)


def _read_tar(data, ctx):
    limits = ctx.limits
    entries, seen = [], set()
    try:
        with tarfile.open(fileobj=io.BytesIO(data), mode="r:") as tf:
            for count, member in enumerate(tf, start=1):
                if count > limits.archive_max_entries:
                    ctx.fail("archive_too_many_entries",
                             f"Archive has more than {limits.archive_max_entries} entries")
                name = clean_entry_path(member.name)
                if name is None:
                    ctx.fail("unsafe_path", f"Unsafe path inside archive: {member.name[:100]!r}")
                if name.casefold() in seen:
                    ctx.fail("unsafe_path", f"Duplicate entry inside archive: {name[:100]!r}")
                seen.add(name.casefold())
                mtime = time.gmtime(min(max(int(member.mtime), 315532800), int(time.time())))
                date_time = tuple(mtime[:6])
                if member.isdir():
                    entries.append(Entry(name, b"", True, date_time))
                    continue
                if not member.isreg():
                    ctx.fail("unsafe_path", f"Symlinks and special files are not allowed: {name[:100]!r}")
                if member.size > limits.max_file_size:
                    ctx.fail("file_too_large", f"Entry {name[:100]!r} is too large")
                ctx.budget.consume(member.size)
                fh = tf.extractfile(member)
                content = fh.read(member.size + 1) if fh else b""
                if len(content) != member.size:
                    ctx.fail("invalid_archive", f"Entry {name[:100]!r} is truncated")
                entries.append(Entry(name, content, False, date_time))
    except (tarfile.TarError, EOFError, OSError, ValueError, UnicodeError):
        ctx.fail("invalid_archive", "TAR structure is corrupt")
    if not entries:
        ctx.fail("invalid_archive", "Archive is empty")
    return entries


def _build_tar(entries):
    out = io.BytesIO()
    with tarfile.open(fileobj=out, mode="w:", format=tarfile.PAX_FORMAT) as tf:
        for entry in entries:
            info = tarfile.TarInfo(entry.name)
            info.mtime = calendar.timegm(entry.date_time)
            info.uid = info.gid = 0
            info.uname = info.gname = ""
            if entry.is_dir:
                info.type, info.mode = tarfile.DIRTYPE, 0o755
                tf.addfile(info)
            else:
                info.mode, info.size = 0o644, len(entry.data)
                tf.addfile(info, io.BytesIO(entry.data))
    return out.getvalue()


def validate_tar(data, ctx):
    if not (len(data) >= 512 and data[257:262] == b"ustar"):
        ctx.fail("content_mismatch", "Content is not a TAR archive")
    return _build_tar(_sanitize_entries(_read_tar(data, ctx), ctx))


def validate_tar_gz(data, ctx):
    tar = _gunzip(data, ctx)
    if not (len(tar) >= 512 and tar[257:262] == b"ustar"):
        ctx.fail("content_mismatch", "Content is not a gzip-compressed TAR archive")
    rebuilt = _build_tar(_sanitize_entries(_read_tar(tar, ctx), ctx))
    # mtime=0 keeps the output (and so its hash name) deterministic.
    return gzip.compress(rebuilt, mtime=0)
