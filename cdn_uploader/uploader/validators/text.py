"""Plain text and CSV.

Text is served as text/plain with nosniff, so a browser never runs it, but
the uploader's contract is "no scripts", so markup/script content is refused
outright instead of relying on how the file is later served or opened.
CSV additionally rejects spreadsheet formulas (CSV/formula injection).
"""

import csv
import io
import re

from .common import looks_executable

CONTROL_RE = re.compile(r"[\x00-\x08\x0b\x0e-\x1f\x7f]")
MARKUP_RE = re.compile(
    r"<\s*/?\s*(script|iframe|frame|object|embed|applet|svg|math|html|body|head|meta|link"
    r"|style|base|form|img|video|audio|xml|xsl)\b"
    r"|<\?(php|=)|<%|\b(java|vb)script\s*:|data\s*:\s*text/html",
    re.I,
)
PHONE_OR_NUMBER_RE = re.compile(r"[+-][\d\s().,-]*")


def _decode(data, ctx):
    try:
        text = data.decode("utf-8-sig")
    except UnicodeDecodeError:
        ctx.fail("invalid_text", "Text files must be UTF-8 encoded")
    if CONTROL_RE.search(text):
        ctx.fail("invalid_text", "File contains binary data")
    if looks_executable(data) or MARKUP_RE.search(text):
        ctx.fail("active_content", "Text contains HTML, script or server-side code")
    return text


def validate_text(data, ctx):
    _decode(data, ctx)
    return data


def _is_formula(cell):
    if not cell:
        return False
    if cell[0] in "=@\t\r":
        return True
    # "-12.5" or "+38 (050) 123-45-67" are data; "+cmd|..." or "-1+A1" are formulas.
    return cell[0] in "+-" and not PHONE_OR_NUMBER_RE.fullmatch(cell)


def validate_csv(data, ctx):
    text = _decode(data, ctx)
    # The delimiter Excel uses depends on the viewer's locale, so check the
    # cells as every common delimiter would split them.
    for delimiter in ",;\t|":
        try:
            reader = csv.reader(io.StringIO(text), delimiter=delimiter)
            for row_no, row in enumerate(reader, start=1):
                for cell in row:
                    if _is_formula(cell):
                        ctx.fail("active_content", f"CSV row {row_no} contains a spreadsheet formula")
        except csv.Error as exc:
            ctx.fail("invalid_text", f"CSV could not be parsed ({exc})")
    return data
