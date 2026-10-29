"""PDF: reject documents that carry active content.

A PDF cannot be re-rendered losslessly without a heavy dependency, so it is
inspected instead, in layers that do not trust each other:

1. Every name in the raw file is decoded (/J#61vaScript == /JavaScript)
   and compared with a blocklist of active-content keys.
2. A small lexer finds the stream bodies the way a PDF reader does
   (skipping strings and comments) and decompresses every object stream
   (/Type /ObjStm), because that is where /JS or /Launch get hidden.
3. Fail closed: if the number of object streams the lexer decoded differs
   from the number declared in the raw file, the structure is ambiguous
   and the file is rejected.

Encrypted files are rejected because their objects cannot be inspected.
"""

import base64
import binascii
import re
import zlib

# Long enough that matching them anywhere, even inside binary stream data,
# cannot realistically produce false positives.
DANGEROUS_NAMES = {
    "JavaScript",                          # script actions / document-level scripts
    "Launch",                              # run an external program
    "EmbeddedFile", "EmbeddedFiles",       # attached payloads
    "RichMedia", "Flash",
    "SubmitForm", "ImportData",            # send / pull data
    "GoToR", "GoToE",                      # open remote/embedded files (NTLM leaks via UNC)
    "Encrypt",                             # cannot be inspected
}
# Short keys: only matched outside stream bodies, where they are real dictionary keys.
DANGEROUS_SHORT_NAMES = {"JS", "XFA"}

NAME_RE = re.compile(rb"/([^\s\x00/<>\[\]()\{\}%]+)")
HEX_ESCAPE_RE = re.compile(rb"#([0-9A-Fa-f]{2})")
FILTER_RE = re.compile(rb"/Filter\s*(\[[^\]]*\]|/[^\s\x00/<>\[\]()]+)")
# Characters the lexer has to look at; everything else is skipped in bulk.
LEX_RE = re.compile(rb"[%()<>]|(?<=[\s\x00>])stream(?:\r\n|\n|\r)")
STRING_RE = re.compile(rb"[()\\]")
EOL_RE = re.compile(rb"[\r\n]")


def _names(chunk):
    for match in NAME_RE.finditer(chunk):
        raw = HEX_ESCAPE_RE.sub(lambda m: bytes([int(m.group(1), 16)]), match.group(1))
        yield raw.decode("latin-1")


def _normalize(chunk):
    """Rewrite #xx escapes inside names so later regexes see the real names."""
    return NAME_RE.sub(
        lambda m: b"/" + HEX_ESCAPE_RE.sub(lambda h: bytes([int(h.group(1), 16)]), m.group(1)),
        chunk,
    )


def _check_names(chunk, ctx, blocked):
    for name in _names(chunk):
        if name in blocked:
            ctx.fail("active_content", f"PDF contains forbidden element /{name}")


def _skip_literal_string(data, i):
    """`data[i]` is "("; return the index just past the matching ")"."""
    depth = 0
    while True:
        m = STRING_RE.search(data, i)
        if not m:
            return len(data)
        ch = m.group()
        if ch == b"\\":
            i = m.end() + 1
            continue
        depth += 1 if ch == b"(" else -1
        i = m.end()
        if depth == 0:
            return i


def _streams(data, ctx):
    """Yield (dictionary bytes, body start, body end) for every stream."""
    i, depth, dict_start, last_dict = 0, 0, None, None
    while True:
        m = LEX_RE.search(data, i)
        if not m:
            return
        tok, pos = m.group(), m.start()
        if tok == b"%":
            eol = EOL_RE.search(data, pos)
            i = eol.end() if eol else len(data)
        elif tok == b"(":
            i = _skip_literal_string(data, pos)
        elif tok == b"<":
            if data[pos + 1:pos + 2] == b"<":
                if depth == 0:
                    dict_start = pos
                depth += 1
                i = pos + 2
            else:
                end = data.find(b">", pos + 1)
                i = end + 1 if end >= 0 else len(data)
        elif tok == b">":
            if data[pos + 1:pos + 2] == b">":
                depth = max(depth - 1, 0)
                if depth == 0 and dict_start is not None:
                    last_dict = (dict_start, pos + 2)
                    dict_start = None
                i = pos + 2
            else:
                i = pos + 1
        elif tok == b")":
            i = pos + 1  # unbalanced, readers ignore it
        else:  # "stream" + EOL
            body_start = m.end()
            body_end = data.find(b"endstream", body_start)
            if body_end < 0:
                ctx.fail("invalid_pdf", "PDF has an unterminated stream")
            header = b""
            if last_dict and not data[last_dict[1]:pos].strip():
                header = data[last_dict[0]:last_dict[1]]
            yield header, body_start, body_end
            i, last_dict, depth, dict_start = body_end + 9, None, 0, None


def _inflate(data, ctx):
    out = bytearray()
    d = zlib.decompressobj()
    pending = data
    try:
        while True:
            chunk = d.decompress(pending, 1024 * 1024)
            ctx.budget.consume(len(chunk))
            out += chunk
            pending = d.unconsumed_tail
            # Writers often omit the zlib trailer, so "ran out of input" is fine.
            if d.eof or (not pending and not chunk):
                break
    except zlib.error:
        ctx.fail("invalid_pdf", "PDF contains a corrupt compressed object stream")
    return bytes(out)


def _decode_stream(body, filters, ctx):
    for name in filters:
        if name in ("FlateDecode", "Fl"):
            body = _inflate(body, ctx)
        elif name in ("ASCIIHexDecode", "AHx"):
            hexdata = re.sub(rb"\s+", b"", body).split(b">", 1)[0]
            if len(hexdata) % 2:
                hexdata += b"0"
            try:
                body = binascii.unhexlify(hexdata)
            except binascii.Error:
                ctx.fail("invalid_pdf", "PDF contains a corrupt object stream")
        elif name in ("ASCII85Decode", "A85"):
            try:
                body = base64.a85decode(body.strip(), adobe=body.strip().startswith(b"<~"))
            except ValueError:
                ctx.fail("invalid_pdf", "PDF contains a corrupt object stream")
        else:
            # LZW, RunLength, crypt filters... object streams never need them
            # in practice, and what cannot be decoded cannot be inspected.
            ctx.fail("active_content", f"PDF object stream uses unsupported filter /{name}")
    return body


def validate_pdf(data, ctx):
    if not re.match(rb"%PDF-[12]\.\d", data[:8]):
        ctx.fail("content_mismatch", "Content is not a PDF document")
    # Only whitespace may follow the final %%EOF: nothing can ride along after it.
    if not data.rstrip(b" \t\r\n\f\x00").endswith(b"%%EOF"):
        ctx.fail("invalid_pdf", "PDF is truncated or has data appended after its end")

    _check_names(data, ctx, DANGEROUS_NAMES)
    declared_objstm = sum(1 for name in _names(data) if name == "ObjStm")

    decoded_objstm = 0
    outside_streams = []
    pos = 0
    for header, start, end in _streams(data, ctx):
        outside_streams.append(data[pos:start])
        pos = end
        header = _normalize(header)
        if not re.search(rb"/Type\s*/ObjStm\b", header):
            continue
        if re.search(rb"/Predictor\s*(?:[2-9]|1\d)", header):
            ctx.fail("active_content", "PDF object stream uses an unsupported predictor")
        fm = FILTER_RE.search(header)
        filters = list(_names(fm.group(1))) if fm else []
        body = _decode_stream(data[start:end].rstrip(b"\r\n"), filters, ctx)
        # An object stream starts with "objnum offset" pairs; anything else
        # means we decoded it differently than a reader would. Fail closed.
        if not re.match(rb"\s*\d+\s+\d+", body):
            ctx.fail("invalid_pdf", "PDF contains an object stream that cannot be inspected")
        _check_names(body, ctx, DANGEROUS_NAMES | DANGEROUS_SHORT_NAMES)
        decoded_objstm += 1
    outside_streams.append(data[pos:])

    for chunk in outside_streams:
        _check_names(chunk, ctx, DANGEROUS_SHORT_NAMES)
    if decoded_objstm != declared_objstm:
        ctx.fail("invalid_pdf", "PDF structure is ambiguous and cannot be inspected")
    return data
