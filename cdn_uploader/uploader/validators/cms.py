"""E-signature containers: CMS / PKCS#7 SignedData (RFC 5652).

A document with an "internal" signature (Ukrainian КЕП from Дія.Підпис, banks,
the tax office: often .p7s, sometimes still named .pdf) is

    <DER header> <the signed document> <certificates, signatures, timestamps>

It has to be stored byte for byte, or the signature breaks, so it is checked
instead of rebuilt:

* the whole file is one well-formed SignedData, with nothing before or after;
* the signed document is inside (a detached signature has nothing to check);
  it may itself be a signed container, a signature over a signature, up to
  MAX_NESTING levels; the caller then checks the document like any other;
* everything that is not the document (headers, certificates, signatures,
  timestamps, revocation data) is limited in size and must not hide another
  file: an archive, a PDF, a program or markup.

The signature itself is NOT verified: Ukrainian DSTU 4145 / Kupyna need a
dedicated library. "Signed" means the file carries a signature.
"""

import re
from dataclasses import dataclass, field

# Value bytes of the object identifiers that matter here.
SIGNED_DATA = bytes.fromhex("2a864886f70d010702")   # 1.2.840.113549.1.7.2
DATA = bytes.fromhex("2a864886f70d010701")          # 1.2.840.113549.1.7.1

MAX_NESTING = 3            # signature over signature over signature
MAX_SIGNERS = 20           # per container
MAX_CERTIFICATES = 50      # per container
MAX_OVERHEAD = 2 * 1024 * 1024   # everything but the document, all levels together
MAX_DEPTH = 64             # of the DER tree
MAX_NODES = 200_000        # DER elements in one container

UNIVERSAL, CONTEXT = 0, 2
INTEGER, OCTET_STRING, OID, SEQUENCE, SET = 2, 4, 6, 16, 17

# Signatures, keys and certificate fields are mostly random bytes, so only
# markers of 4+ bytes are searched: shorter ones would match by chance.
HIDDEN_FILE_RE = re.compile(
    rb"PK\x03\x04|PK\x05\x06|PK\x01\x02|%PDF-|\x7fELF|PE\x00\x00|Rar!\x1a\x07|7z\xbc\xaf\x27\x1c"
    rb"|<\?php|<script|<html|<svg|<iframe|<!doctype|<body|<object|<embed|javascript:",
    re.I,
)


@dataclass
class Node:
    cls: int
    constructed: bool
    tag: int
    start: int            # first byte of the header
    body: int             # first byte of the value
    end: int              # one past the element (end-of-contents included)
    children: list = field(default_factory=list)


class _Reader:
    """DER, plus the BER forms signers produce (indefinite lengths, chunked strings)."""

    def __init__(self, data, ctx):
        self.data, self.ctx, self.count = data, ctx, 0

    def fail(self):
        self.ctx.fail("content_mismatch", "Signature container is malformed")

    def node(self, pos, limit, depth):
        if depth > MAX_DEPTH:
            self.fail()
        self.count += 1
        if self.count > MAX_NODES:
            self.fail()
        data = self.data
        if pos + 2 > limit:
            self.fail()
        first = data[pos]
        cls, constructed, tag = first >> 6, bool(first & 0x20), first & 0x1F
        i = pos + 1
        if tag == 0x1F:                       # high tag number form
            tag = 0
            for _ in range(4):
                if i >= limit:
                    self.fail()
                b = data[i]
                i += 1
                tag = (tag << 7) | (b & 0x7F)
                if not b & 0x80:
                    break
            else:
                self.fail()
        if i >= limit:
            self.fail()
        lb = data[i]
        i += 1
        if lb == 0x80:                        # indefinite length: children until 00 00
            if not constructed:
                self.fail()
            node = Node(cls, constructed, tag, pos, i, 0)
            while True:
                if i + 2 > limit:
                    self.fail()
                if data[i] == 0 and data[i + 1] == 0:
                    node.end = i + 2
                    return node
                child = self.node(i, limit, depth + 1)
                node.children.append(child)
                i = child.end
        if lb < 0x80:
            length = lb
        else:
            n = lb & 0x7F
            if n > 4 or i + n > limit:
                self.fail()
            length = int.from_bytes(data[i:i + n], "big")
            i += n
        end = i + length
        if end > limit:
            self.fail()
        node = Node(cls, constructed, tag, pos, i, end)
        if constructed:
            j = i
            while j < end:
                child = self.node(j, end, depth + 1)
                node.children.append(child)
                j = child.end
        return node


def looks_signed(data):
    """Does `data` start like a SignedData container (SEQUENCE, then its OID)?"""
    if len(data) < 16 or data[0] != 0x30:
        return False
    lb = data[1]
    i = 2 + (lb & 0x7F if lb > 0x80 else 0)
    return data[i:i + 2 + len(SIGNED_DATA)] == b"\x06\x09" + SIGNED_DATA


def _is(node, cls, tag, constructed):
    return node.cls == cls and node.tag == tag and node.constructed == constructed


def _octets(node, data, reader):
    """Spans of an OCTET STRING's bytes (a BER one may come in chunks)."""
    if _is(node, UNIVERSAL, OCTET_STRING, False):
        return [(node.body, node.end)]
    if not _is(node, UNIVERSAL, OCTET_STRING, True):
        reader.fail()
    return [span for child in node.children for span in _octets(child, data, reader)]


def unwrap(data, ctx):
    """Return (signed document, number of signers) of a signed container.

    Fails with ValidationError unless `data` is exactly one SignedData that
    carries its document and hides nothing else.
    """
    document, signers, overhead = data, 0, 0
    for _ in range(MAX_NESTING):
        reader = _Reader(document, ctx)
        root = reader.node(0, len(document), 0)
        if root.end != len(document):
            ctx.fail("content_mismatch", "Signature container has data after its end")
        spans, count = _signed_data(root, document, reader, ctx)
        content = b"".join(document[s:e] for s, e in spans)
        # Everything but the document: no other file may hide in it.
        rest = bytearray(document)
        for s, e in spans:
            rest[s:e] = bytes(e - s)
        overhead += len(document) - len(content)
        if overhead > MAX_OVERHEAD:
            ctx.fail("content_mismatch", "Signature container is too large")
        if HIDDEN_FILE_RE.search(rest):
            ctx.fail("active_content", "Signature container hides another file")
        document, signers = content, signers + count
        if not looks_signed(document):
            return document, signers
    ctx.fail("content_mismatch", f"More than {MAX_NESTING} nested signatures")


def _signed_data(root, data, reader, ctx):
    """Check ContentInfo{signedData, SignedData}; return (document spans, signers)."""
    # ContentInfo ::= SEQUENCE { contentType OID, content [0] EXPLICIT SignedData }
    if not _is(root, UNIVERSAL, SEQUENCE, True) or len(root.children) != 2:
        reader.fail()
    oid, wrapper = root.children
    if not _is(oid, UNIVERSAL, OID, False) or data[oid.body:oid.end] != SIGNED_DATA:
        ctx.fail("content_mismatch", "Not a signed document")
    if not _is(wrapper, CONTEXT, 0, True) or len(wrapper.children) != 1:
        reader.fail()
    signed = wrapper.children[0]
    # SignedData ::= SEQUENCE { version, digestAlgorithms SET, encapContentInfo,
    #                certificates [0] OPTIONAL, crls [1] OPTIONAL, signerInfos SET }
    if not _is(signed, UNIVERSAL, SEQUENCE, True) or len(signed.children) < 4:
        reader.fail()
    version, digests, encap, *extras, signer_infos = signed.children
    if (not _is(version, UNIVERSAL, INTEGER, False) or not _is(digests, UNIVERSAL, SET, True)
            or not _is(encap, UNIVERSAL, SEQUENCE, True) or not _is(signer_infos, UNIVERSAL, SET, True)):
        reader.fail()
    if [x.tag for x in extras] not in ([], [0], [1], [0, 1]) or any(
            x.cls != CONTEXT or not x.constructed for x in extras):
        reader.fail()
    if extras and extras[0].tag == 0 and len(extras[0].children) > MAX_CERTIFICATES:
        ctx.fail("content_mismatch", "Signature container has too many certificates")
    signers = signer_infos.children
    if not signers or len(signers) > MAX_SIGNERS or any(
            not _is(s, UNIVERSAL, SEQUENCE, True) for s in signers):
        reader.fail()
    # EncapsulatedContentInfo ::= SEQUENCE { eContentType OID, eContent [0] EXPLICIT OCTET STRING OPTIONAL }
    if not encap.children or not _is(encap.children[0], UNIVERSAL, OID, False):
        reader.fail()
    ctype = encap.children[0]
    if data[ctype.body:ctype.end] != DATA:
        ctx.fail("content_mismatch", "Signed content is not a document")
    if len(encap.children) == 1:
        ctx.fail("detached_signature", "The signed document is not inside the file (detached signature)")
    content = encap.children[1]
    if len(encap.children) != 2 or not _is(content, CONTEXT, 0, True) or len(content.children) != 1:
        reader.fail()
    return _octets(content.children[0], data, reader), len(signers)
