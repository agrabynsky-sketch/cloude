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

Signed PDFs (Ukrainian КЕП with an "internal" signature: bank statements,
Дія.Підпис) are a PDF wrapped in a CMS SignedData container. Rebuilding it
would break the signature, so the file is kept byte for byte and checked
instead: exactly one well-formed container with nothing before or after it,
the PDF inside (a detached signature has nothing to check), limits on size
and structure, a signature over a signature at most MAX_SIGNED_NESTING
deep. The PDF inside gets every check above, and the rest of the container
(certificates, signatures, timestamps) must not hide another file. The
signature itself is not verified.
"""

import base64
import binascii
import re
import zlib
from dataclasses import dataclass, field

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


# ---- signed PDFs: CMS SignedData (RFC 5652) ------------------------------------

SIGNED_DATA_OID = bytes.fromhex("2a864886f70d010702")   # 1.2.840.113549.1.7.2
DATA_OID = bytes.fromhex("2a864886f70d010701")          # 1.2.840.113549.1.7.1
MAX_SIGNED_NESTING = 3          # signature over signature over signature
MAX_SIGNERS = 20
MAX_CERTIFICATES = 50
MAX_SIGNATURE_OVERHEAD = 2 * 1024 * 1024   # everything but the PDF, all levels together
MAX_DER_DEPTH = 64
MAX_DER_NODES = 200_000
# Certificates and signatures are mostly random bytes: only markers of 4+
# bytes are searched, shorter ones would match by chance.
HIDDEN_FILE_RE = re.compile(
    rb"PK\x03\x04|PK\x05\x06|PK\x01\x02|%PDF-|\x7fELF|PE\x00\x00|Rar!\x1a\x07|7z\xbc\xaf\x27\x1c"
    rb"|<\?php|<script|<html|<svg|<iframe|<!doctype|<body|<object|<embed|javascript:",
    re.I,
)
SEQUENCE, SET = (0, 16), (0, 17)            # (class, tag): universal
INTEGER, OCTET_STRING, OID = (0, 2), (0, 4), (0, 6)


@dataclass
class _Der:
    cls: int
    tag: int
    constructed: bool
    body: int             # first byte of the value
    end: int              # one past the element (end-of-contents included)
    children: list = field(default_factory=list)

    def is_(self, kind, constructed):
        return (self.cls, self.tag) == kind and self.constructed == constructed


def _looks_signed(data):
    """Does `data` start like a SignedData container (SEQUENCE, then its OID)?"""
    if len(data) < 16 or data[0] != 0x30:
        return False
    i = 2 + (data[1] & 0x7F if data[1] > 0x80 else 0)
    return data[i:i + 11] == b"\x06\x09" + SIGNED_DATA_OID


def _parse_der(data, ctx):
    """DER, plus the BER forms signers produce (indefinite lengths, chunked strings)."""
    count = 0

    def bad():
        ctx.fail("content_mismatch", "Signature container is malformed")

    def node(pos, limit, depth):
        nonlocal count
        count += 1
        if depth > MAX_DER_DEPTH or count > MAX_DER_NODES or pos + 2 > limit:
            bad()
        first = data[pos]
        cls, constructed, tag = first >> 6, bool(first & 0x20), first & 0x1F
        i = pos + 1
        if tag == 0x1F:                         # high tag number form
            tag = 0
            for _ in range(4):
                if i >= limit:
                    bad()
                tag = (tag << 7) | (data[i] & 0x7F)
                i += 1
                if not data[i - 1] & 0x80:
                    break
            else:
                bad()
        if i >= limit:
            bad()
        lb = data[i]
        i += 1
        if lb == 0x80:                          # indefinite length: children until 00 00
            if not constructed:
                bad()
            el = _Der(cls, tag, constructed, i, 0)
            while True:
                if i + 2 > limit:
                    bad()
                if data[i:i + 2] == b"\0\0":
                    break
                el.children.append(node(i, limit, depth + 1))
                i = el.children[-1].end
            el.end = i + 2
            return el
        if lb > 0x80:
            n = lb & 0x7F
            if n > 4 or i + n > limit:
                bad()
            lb, i = int.from_bytes(data[i:i + n], "big"), i + n
        if i + lb > limit:
            bad()
        el = _Der(cls, tag, constructed, i, i + lb)
        while constructed and i < el.end:
            el.children.append(node(i, el.end, depth + 1))
            i = el.children[-1].end
        return el

    root = node(0, len(data), 0)
    if root.end != len(data):
        ctx.fail("content_mismatch", "Signature container has data after its end")
    return root


def _octets(el, ctx):
    """Spans of an OCTET STRING's bytes (a BER one may come in chunks)."""
    if el.is_(OCTET_STRING, False):
        return [(el.body, el.end)]
    if not el.is_(OCTET_STRING, True):
        ctx.fail("content_mismatch", "Signature container is malformed")
    return [span for child in el.children for span in _octets(child, ctx)]


def _signed_content(data, ctx):
    """Spans of the document inside ContentInfo{signedData, SignedData}."""
    def bad():
        ctx.fail("content_mismatch", "Signature container is malformed")

    root = _parse_der(data, ctx)
    # ContentInfo ::= SEQUENCE { contentType OID, content [0] EXPLICIT SignedData }
    if not root.is_(SEQUENCE, True) or len(root.children) != 2:
        bad()
    oid, wrapper = root.children
    if not oid.is_(OID, False) or data[oid.body:oid.end] != SIGNED_DATA_OID:
        ctx.fail("content_mismatch", "Not a signed document")
    if not wrapper.is_((2, 0), True) or len(wrapper.children) != 1:
        bad()
    signed = wrapper.children[0]
    # SignedData ::= SEQUENCE { version, digestAlgorithms SET, encapContentInfo,
    #                certificates [0] OPTIONAL, crls [1] OPTIONAL, signerInfos SET }
    if not signed.is_(SEQUENCE, True) or len(signed.children) < 4:
        bad()
    version, digests, encap, *extras, signers = signed.children
    if (not version.is_(INTEGER, False) or not digests.is_(SET, True)
            or not encap.is_(SEQUENCE, True) or not signers.is_(SET, True)):
        bad()
    if [x.tag for x in extras] not in ([], [0], [1], [0, 1]) or any(
            x.cls != 2 or not x.constructed for x in extras):
        bad()
    if extras and extras[0].tag == 0 and len(extras[0].children) > MAX_CERTIFICATES:
        ctx.fail("content_mismatch", "Signature container has too many certificates")
    if not 0 < len(signers.children) <= MAX_SIGNERS or any(
            not s.is_(SEQUENCE, True) for s in signers.children):
        bad()
    # EncapsulatedContentInfo ::= SEQUENCE { eContentType OID, eContent [0] EXPLICIT OCTET STRING OPTIONAL }
    if not encap.children or not encap.children[0].is_(OID, False):
        bad()
    ctype = encap.children[0]
    if data[ctype.body:ctype.end] != DATA_OID:
        ctx.fail("content_mismatch", "Signed content is not a document")
    if len(encap.children) == 1:
        ctx.fail("content_mismatch", "Detached signature: the signed document is not inside the file")
    content = encap.children[1]
    if len(encap.children) != 2 or not content.is_((2, 0), True) or len(content.children) != 1:
        bad()
    return _octets(content.children[0], ctx)


def _unwrap_signed(data, ctx):
    """The document inside a signed container (and inside any signature over it)."""
    overhead = 0
    for _ in range(MAX_SIGNED_NESTING):
        spans = _signed_content(data, ctx)
        content = b"".join(data[s:e] for s, e in spans)
        # Everything but the document cannot be rewritten: no other file may hide in it.
        rest = bytearray(data)
        for s, e in spans:
            rest[s:e] = bytes(e - s)
        overhead += len(data) - len(content)
        if overhead > MAX_SIGNATURE_OVERHEAD:
            ctx.fail("content_mismatch", "Signature container is too large")
        if HIDDEN_FILE_RE.search(rest):
            ctx.fail("active_content", "Signature container hides another file")
        data = content
        if not _looks_signed(data):
            return data
    ctx.fail("content_mismatch", f"More than {MAX_SIGNED_NESTING} nested signatures")


# ---- entry point -------------------------------------------------------------------

def validate_pdf(data, ctx):
    """A PDF, or a PDF inside an e-signature container. Returns `data` unchanged."""
    if _looks_signed(data):
        document = _unwrap_signed(data, ctx)
        if not document.startswith(b"%PDF-"):
            ctx.fail("content_mismatch", "The signed document is not a PDF")
        _check_pdf(document, ctx)
        return data
    return _check_pdf(data, ctx)


def _check_pdf(data, ctx):
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
