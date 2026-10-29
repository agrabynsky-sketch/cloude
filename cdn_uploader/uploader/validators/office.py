"""Office documents (OOXML: docx/xlsx/pptx, OpenDocument: odt/ods/odp).

Only the macro-free formats are accepted, and their contents are checked
for the ways a macro-free document is still weaponised:

* VBA projects / ActiveX / OLE objects smuggled into a .docx (renamed .docm)
* remote templates and external OLE links (Follina CVE-2022-30190 and
  "template injection" use an External relationship)
* DDE fields and DDE formulas
* PowerPoint "run program" actions
* LibreOffice Basic/script event handlers
* DTD/entity declarations (XXE, billion laughs against later parsers)

The document is then rebuilt into a fresh zip from the checked parts.
"""

import re

from .common import OLE_MAGIC, looks_executable
from .ziputil import build_zip, read_zip

OOXML_MAIN_TYPES = {
    "docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml",
    "xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml",
    "pptx": "application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml",
}
ODF_MIMETYPES = {
    "odt": b"application/vnd.oasis.opendocument.text",
    "ods": b"application/vnd.oasis.opendocument.spreadsheet",
    "odp": b"application/vnd.oasis.opendocument.presentation",
}

FORBIDDEN_SUFFIXES = (
    ".exe", ".dll", ".com", ".scr", ".pif", ".cpl", ".msi", ".msp", ".jar", ".js",
    ".jse", ".vbs", ".vbe", ".wsf", ".wsh", ".ps1", ".bat", ".cmd", ".hta", ".lnk",
    ".sh", ".py", ".php", ".html", ".htm", ".xhtml", ".doc", ".xls", ".ppt",
    ".docm", ".xlsm", ".pptm", ".dotm", ".xltm", ".potm", ".xlam", ".ppam",
)
EMBEDDABLE_IMAGES = (".png", ".jpg", ".jpeg", ".gif", ".emf", ".wmf", ".bmp", ".tif", ".tiff")
XML_SUFFIXES = (".xml", ".rels", ".vml")

DTD_RE = re.compile(r"<!\s*(DOCTYPE|ENTITY)", re.I)
XML_DECL_ENCODING_RE = re.compile(r"""^\s*<\?xml[^>]*encoding\s*=\s*["']([^"']+)["']""", re.I)
SAFE_XML_ENCODINGS = {
    "utf-8", "utf8", "utf-16", "us-ascii", "ascii", "iso-8859-1", "latin-1",
    "windows-1250", "windows-1251", "windows-1252",
}
CHAR_REF_RE = re.compile(r"&(#x[0-9A-Fa-f]{1,8}|#\d{1,8}|amp|lt|gt|quot|apos);")
RELATIONSHIP_RE = re.compile(r"<(?:[\w.-]+:)?Relationship\b[^>]*>", re.S)
INSTR_RE = re.compile(
    r"<(?:[\w.-]+:)?instrText\b[^>]*>(.*?)</(?:[\w.-]+:)?instrText>"
    r"|\b(?:[\w.-]+:)?instr\s*=\s*[\"']([^\"']*)[\"']",
    re.S,
)
FORMULA_RE = re.compile(r"<(?:[\w.-]+:)?f\b[^>]*>(.*?)</(?:[\w.-]+:)?f>", re.S)
EXCEL_STRING_RE = re.compile(r'"(?:[^"]|"")*"')
PPT_ACTION_RE = re.compile(r"ppaction://(program|macro|ole)", re.I)
ODF_SCRIPT_RE = re.compile(
    r"script:event-listener|vnd\.sun\.star\.script|macro://"
    r"|office:dde-|text:dde-connection|table:dde-link",
    re.I,
)

_ENTITIES = {"amp": "&", "lt": "<", "gt": ">", "quot": '"', "apos": "'"}


def _unescape(text):
    def repl(m):
        ref = m.group(1)
        if ref.startswith("#x"):
            return chr(int(ref[2:], 16)) if int(ref[2:], 16) < 0x110000 else ""
        if ref.startswith("#"):
            return chr(int(ref[1:])) if int(ref[1:]) < 0x110000 else ""
        return _ENTITIES[ref]
    return CHAR_REF_RE.sub(repl, text)


def _xml_text(entry, ctx):
    """Decode an XML part the way an XML parser would, refusing exotic encodings."""
    raw = entry.data
    if raw.startswith((b"\xff\xfe", b"\xfe\xff")):
        text = raw.decode("utf-16", errors="replace")
    else:
        if b"\x00" in raw[:200]:
            ctx.fail("active_content", f"Document part {entry.name!r} uses an unsupported encoding")
        text = raw.decode("utf-8", errors="replace")
    m = XML_DECL_ENCODING_RE.match(text.lstrip("﻿"))
    if m and m.group(1).lower() not in SAFE_XML_ENCODINGS:
        ctx.fail("active_content", f"Document part {entry.name!r} uses encoding {m.group(1)!r}")
    if DTD_RE.search(text):
        ctx.fail("active_content", "Document contains DTD/entity declarations")
    return _unescape(text)


def _attr(tag, name):
    m = re.search(rf"\b{name}\s*=\s*[\"']([^\"']*)[\"']", tag)
    return m.group(1) if m else None


def _check_common(entry, ctx):
    lower = entry.name.lower()
    base = lower.rsplit("/", 1)[-1]
    if base in ("vbaproject.bin", "vbadata.xml", "vbaprojectsignature.bin") or "/activex/" in f"/{lower}":
        ctx.fail("active_content", "Document contains macros or ActiveX controls")
    if lower.endswith(FORBIDDEN_SUFFIXES):
        ctx.fail("active_content", f"Document contains a forbidden embedded file {entry.name!r}")
    if entry.data.startswith(OLE_MAGIC):
        ctx.fail("active_content", "Document contains embedded OLE objects")
    if looks_executable(entry.data):
        ctx.fail("active_content", "Document contains embedded executable code")


def validate_ooxml(data, ext, ctx, nesting=0):
    entries = read_zip(data, ctx, ctx.limits.office_max_entries)
    by_name = {e.name: e for e in entries}
    ctypes = by_name.get("[Content_Types].xml")
    if ctypes is None:
        ctx.fail("content_mismatch", f"Content is not a valid .{ext} document")
    ctypes_text = _xml_text(ctypes, ctx)
    if OOXML_MAIN_TYPES[ext] not in ctypes_text:
        ctx.fail("content_mismatch", f"Content is not a valid .{ext} document (wrong type or macro-enabled)")
    if re.search(r"macroEnabled|vbaProject|activeX|oleObject", ctypes_text, re.I):
        ctx.fail("active_content", "Document contains macros, ActiveX or OLE objects")

    for entry in entries:
        if entry.is_dir:
            continue
        _check_common(entry, ctx)
        lower = entry.name.lower()

        if "/embeddings/" in f"/{lower}":
            sub_ext = lower.rsplit(".", 1)[-1]
            if sub_ext in OOXML_MAIN_TYPES and nesting == 0:
                # e.g. the workbook behind a chart in a .pptx: check it the same way.
                entry.data = validate_ooxml(entry.data, sub_ext, ctx.child(entry.name), nesting + 1)
            elif not lower.endswith(EMBEDDABLE_IMAGES):
                ctx.fail("active_content", f"Document contains a forbidden embedded object {entry.name!r}")
            continue
        if lower.startswith(("xl/externallinks/", "xl/querytables/")) or lower == "xl/connections.xml":
            ctx.fail("active_content", "Workbook contains external links or data connections")
        if not lower.endswith(XML_SUFFIXES):
            continue

        text = _xml_text(entry, ctx)
        if lower.endswith(".rels"):
            for tag in RELATIONSHIP_RE.findall(text):
                mode = (_attr(tag, "TargetMode") or "").strip().lower()
                rel_type = (_attr(tag, "Type") or "").strip()
                if mode == "external" and not rel_type.endswith("/hyperlink"):
                    ctx.fail("active_content", "Document loads external content (remote template or object)")
        if PPT_ACTION_RE.search(text):
            ctx.fail("active_content", "Presentation contains run-program/macro actions")
        if ext == "docx":
            instructions = "".join(a or b for a, b in INSTR_RE.findall(text))
            if re.search(r"\bDDE(AUTO)?\b", instructions, re.I):
                ctx.fail("active_content", "Document contains DDE fields")
        if ext == "xlsx" and lower.startswith("xl/worksheets/"):
            for formula in FORMULA_RE.findall(text):
                if "|" in EXCEL_STRING_RE.sub("", formula):
                    ctx.fail("active_content", "Workbook contains DDE formulas")
    return build_zip(entries)


def validate_odf(data, ext, ctx):
    entries = read_zip(data, ctx, ctx.limits.office_max_entries)
    first = entries[0]
    if first.name != "mimetype" or first.data.strip() != ODF_MIMETYPES[ext]:
        ctx.fail("content_mismatch", f"Content is not a valid .{ext} document")
    by_name = {e.name: e for e in entries}
    manifest = by_name.get("META-INF/manifest.xml")
    if manifest is None:
        ctx.fail("content_mismatch", f"Content is not a valid .{ext} document")

    for entry in entries:
        if entry.is_dir or entry is first:
            continue
        _check_common(entry, ctx)
        lower = entry.name.lower()
        if lower.startswith(("basic/", "scripts/")):
            ctx.fail("active_content", "Document contains macros")
        if not lower.endswith(XML_SUFFIXES):
            continue
        text = _xml_text(entry, ctx)
        if entry is manifest and "encryption-data" in text:
            ctx.fail("encrypted", "Password-protected documents cannot be checked")
        if ODF_SCRIPT_RE.search(text):
            ctx.fail("active_content", "Document contains scripts, macro bindings or DDE links")
    # ODF requires "mimetype" to be first and uncompressed.
    return build_zip(entries, stored_names=("mimetype",))
