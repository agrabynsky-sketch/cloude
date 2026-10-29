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
* embedded packages (a .docm inside a .docx)

Readers find parts through relationships and [Content_Types].xml, not by
file name, so every part that *is* XML gets the XML checks, wherever it
lives and whatever it is called. All regexes here are linear: they never
scan past the next "<" or ">" from a given start.

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
ZIP_MAGIC = b"PK\x03\x04"

DTD_RE = re.compile(r"<!\s*(DOCTYPE|ENTITY)", re.I)
XML_DECL_ENCODING_RE = re.compile(r"""^\s*<\?xml[^<>]*encoding\s*=\s*["']([^"'<>]+)["']""", re.I)
SAFE_XML_ENCODINGS = {
    "utf-8", "utf8", "utf-16", "us-ascii", "ascii", "iso-8859-1", "latin-1",
    "windows-1250", "windows-1251", "windows-1252",
}
CHAR_REF_RE = re.compile(r"&(#x[0-9A-Fa-f]{1,8}|#\d{1,8}|amp|lt|gt|quot|apos);")
# Markup that an XML parser removes or turns into plain text.
SPECIAL_OPEN_RE = re.compile(r"<!--|<!\[CDATA\[|<\?")
RELATIONSHIP_RE = re.compile(r"<(?:[\w.-]+:)?Relationship\b[^<>]*>")
INSTR_RE = re.compile(
    r"<(?:[\w.-]+:)?instrText\b[^<>]*>([^<]*)</(?:[\w.-]+:)?instrText>"
    r"|(?<![\w.-])(?:[\w.-]+:)?instr\s*=\s*(?:\"([^\"<]*)\"|'([^'<]*)')"
)
FORMULA_RE = re.compile(
    r"<(?:[\w.-]+:)?(?:f|definedName)\b[^<>]*>([^<]*)</(?:[\w.-]+:)?(?:f|definedName)>")
EXCEL_STRING_RE = re.compile(r'"(?:[^"]|"")*"')
PPT_ACTION_RE = re.compile(r"ppaction://(program|macro|ole)", re.I)
ODF_SCRIPT_RE = re.compile(
    r"(?<![\w.-])[\w.-]+:(?:event-listener|dde-)|vnd\.sun\.star\.script|macro://",
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


def _escape(text):
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def _is_xml(data):
    """Content sniffing: does a parser see this part as XML?"""
    if data.startswith((b"\xff\xfe", b"\xfe\xff")):
        return True
    head = data[:4]
    if head[:2] in (b"<\x00", b"\x00<"):  # UTF-16/32 without a BOM
        return True
    return data.lstrip(b"\xef\xbb\xbf \t\r\n")[:1] == b"<"


def _normalize(text, ctx, name):
    """Drop comments/PIs and turn CDATA into escaped text, in one linear pass,
    so that a formula or field cannot be split or hidden by them."""
    out, pos = [], 0
    for m in SPECIAL_OPEN_RE.finditer(text):
        if m.start() < pos:
            continue
        closer = {"<!--": "-->", "<?": "?>"}.get(m.group(), "]]>")
        end = text.find(closer, m.end())
        if end < 0:
            ctx.fail("invalid_document", f"Document part {name!r} is malformed")
        out.append(text[pos:m.start()])
        if closer == "]]>":
            out.append(_escape(text[m.end():end]))
        pos = end + len(closer)
    out.append(text[pos:])
    return "".join(out)


def _xml_text(entry, ctx):
    """Decode an XML part the way an XML parser would, refusing exotic encodings.

    Returns the markup with comments/PIs/CDATA normalized; entity references
    are still escaped, so "<" in the result is always real markup."""
    raw = entry.data
    if raw.startswith((b"\xff\xfe", b"\xfe\xff")):
        text = raw.decode("utf-16", errors="replace")
    else:
        if b"\x00" in raw[:200]:
            ctx.fail("active_content", f"Document part {entry.name!r} uses an unsupported encoding")
        text = raw.decode("utf-8", errors="replace")
    text = text.lstrip("﻿")
    m = XML_DECL_ENCODING_RE.match(text)
    if m and m.group(1).lower() not in SAFE_XML_ENCODINGS:
        ctx.fail("active_content", f"Document part {entry.name!r} uses encoding {m.group(1)!r}")
    if DTD_RE.search(text):
        ctx.fail("active_content", "Document contains DTD/entity declarations")
    return _normalize(text, ctx, entry.name)


def _attr(tag, name):
    m = re.search(rf"\b{name}\s*=\s*(?:\"([^\"]*)\"|'([^']*)')", tag)
    return _unescape(m.group(1) if m.group(1) is not None else m.group(2)) if m else None


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


def _check_ooxml_xml(entry, ext, ctx):
    markup = _xml_text(entry, ctx)
    plain = _unescape(markup)
    for tag in RELATIONSHIP_RE.findall(markup):
        mode = (_attr(tag, "TargetMode") or "").strip().lower()
        rel_type = (_attr(tag, "Type") or "").strip()
        if mode == "external" and not rel_type.endswith("/hyperlink"):
            ctx.fail("active_content", "Document loads external content (remote template or object)")
    if PPT_ACTION_RE.search(plain):
        ctx.fail("active_content", "Presentation contains run-program/macro actions")
    if ext == "docx":
        instructions = "".join(_unescape(a or b or c) for a, b, c in INSTR_RE.findall(markup))
        if re.search(r"\bDDE(AUTO)?\b", instructions, re.I):
            ctx.fail("active_content", "Document contains DDE fields")
    if ext == "xlsx":
        for formula in FORMULA_RE.findall(markup):
            if "|" in EXCEL_STRING_RE.sub("", _unescape(formula)):
                ctx.fail("active_content", "Workbook contains DDE formulas")
    return markup


def validate_ooxml(data, ext, ctx, nesting=0):
    entries = read_zip(data, ctx, ctx.limits.office_max_entries)
    by_name = {e.name: e for e in entries}
    ctypes = by_name.get("[Content_Types].xml")
    if ctypes is None:
        ctx.fail("content_mismatch", f"Content is not a valid .{ext} document")
    ctypes_text = _unescape(_xml_text(ctypes, ctx))
    if OOXML_MAIN_TYPES[ext] not in ctypes_text:
        ctx.fail("content_mismatch", f"Content is not a valid .{ext} document (wrong type or macro-enabled)")
    if re.search(r"macroEnabled|vbaProject|activeX|oleObject", ctypes_text, re.I):
        ctx.fail("active_content", "Document contains macros, ActiveX or OLE objects")

    for entry in entries:
        if entry.is_dir:
            continue
        _check_common(entry, ctx)
        lower = entry.name.lower()
        if lower.startswith(("xl/externallinks/", "xl/querytables/")) or lower == "xl/connections.xml":
            ctx.fail("active_content", "Workbook contains external links or data connections")
        if entry.data.startswith(ZIP_MAGIC):
            # An embedded package (e.g. the workbook behind a chart). Only
            # plain OOXML is allowed, checked the same way, one level deep.
            sub_ext = lower.rsplit(".", 1)[-1]
            if sub_ext not in OOXML_MAIN_TYPES or nesting > 0:
                ctx.fail("active_content", f"Document contains a forbidden embedded package {entry.name!r}")
            entry.data = validate_ooxml(entry.data, sub_ext, ctx.child(entry.name), nesting + 1)
            continue
        if "/embeddings/" in f"/{lower}" and not lower.endswith(EMBEDDABLE_IMAGES):
            ctx.fail("active_content", f"Document contains a forbidden embedded object {entry.name!r}")
        if _is_xml(entry.data):
            _check_ooxml_xml(entry, ext, ctx)
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
        if entry.data.startswith(ZIP_MAGIC):
            ctx.fail("active_content", f"Document contains a forbidden embedded package {entry.name!r}")
        if not _is_xml(entry.data):
            continue
        plain = _unescape(_xml_text(entry, ctx))
        if entry is manifest and "encryption-data" in plain:
            ctx.fail("encrypted", "Password-protected documents cannot be checked")
        if ODF_SCRIPT_RE.search(plain):
            ctx.fail("active_content", "Document contains scripts, macro bindings or DDE links")
    # ODF requires "mimetype" to be first and uncompressed.
    return build_zip(entries, stored_names=("mimetype",))
