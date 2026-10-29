"""File type registry: the extension decides the validator, the content must agree.

Anything not listed here is rejected. Notably absent on purpose: SVG, HTML,
XML, RTF, legacy .doc/.xls/.ppt, macro-enabled Office formats, rar/7z and
every executable or script type.
"""

from dataclasses import dataclass
from typing import Callable

from .archives import validate_tar, validate_tar_gz, validate_zip
from .common import Budget, Context, Limits, Sanitized, ValidationError, looks_executable
from .images import sanitize_image
from .office import validate_odf, validate_ooxml
from .pdf import validate_pdf
from .text import validate_csv, validate_text

__all__ = [
    "ALIASES", "FILE_TYPES", "Limits", "Sanitized", "ValidationError",
    "extension_of", "new_context", "sanitize",
]

CATEGORIES = ("image", "document", "archive")


@dataclass(frozen=True)
class FileType:
    ext: str
    mime: str
    category: str
    handler: Callable


def _image(ext):
    return lambda data, ctx: sanitize_image(data, ext, ctx)


def _ooxml(ext):
    return lambda data, ctx: validate_ooxml(data, ext, ctx)


def _odf(ext):
    return lambda data, ctx: validate_odf(data, ext, ctx)


FILE_TYPES = {
    t.ext: t
    for t in (
        FileType("jpg", "image/jpeg", "image", _image("jpg")),
        FileType("png", "image/png", "image", _image("png")),
        FileType("gif", "image/gif", "image", _image("gif")),
        FileType("webp", "image/webp", "image", _image("webp")),
        FileType("pdf", "application/pdf", "document", validate_pdf),
        FileType("docx", "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                 "document", _ooxml("docx")),
        FileType("xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                 "document", _ooxml("xlsx")),
        FileType("pptx", "application/vnd.openxmlformats-officedocument.presentationml.presentation",
                 "document", _ooxml("pptx")),
        FileType("odt", "application/vnd.oasis.opendocument.text", "document", _odf("odt")),
        FileType("ods", "application/vnd.oasis.opendocument.spreadsheet", "document", _odf("ods")),
        FileType("odp", "application/vnd.oasis.opendocument.presentation", "document", _odf("odp")),
        FileType("txt", "text/plain; charset=utf-8", "document", validate_text),
        FileType("csv", "text/csv; charset=utf-8", "document", validate_csv),
        FileType("zip", "application/zip", "archive", validate_zip),
        FileType("tar", "application/x-tar", "archive", validate_tar),
        FileType("tar.gz", "application/gzip", "archive", validate_tar_gz),
    )
}
ALIASES = {"jpeg": "jpg", "tgz": "tar.gz"}


def extension_of(filename):
    """Canonical extension of `filename`, or None. Only the last suffix counts
    ("invoice.pdf.exe" is an .exe), except for the compound .tar.gz."""
    lower = (filename or "").strip().lower()
    if lower.endswith(".tar.gz") and len(lower) > 7:
        return "tar.gz"
    stem, dot, ext = lower.rpartition(".")
    if not dot or not stem.strip(" ."):
        return None
    return ALIASES.get(ext, ext)


def new_context(limits):
    return Context(limits=limits, budget=Budget(limits.max_uncompressed))


def sanitize(filename, data, ctx, categories=CATEGORIES):
    """Validate one file and return what may be stored. Raises ValidationError."""
    ext = extension_of(filename)
    ftype = FILE_TYPES.get(ext)
    if ftype is None or ftype.category not in categories:
        shown = f".{ext}" if ext else "without extension"
        ctx.fail("type_not_allowed", f"File type {shown} is not allowed")
    if not data:
        ctx.fail("empty_file", "File is empty")
    if len(data) > ctx.limits.max_file_size:
        ctx.fail("file_too_large", "File is too large")
    if looks_executable(data):
        ctx.fail("active_content", "Executable files are not allowed")
    out = ftype.handler(data, ctx)
    return Sanitized(data=out, ext=ftype.ext, mime=ftype.mime, category=ftype.category)
