"""Builders for test files, benign and malicious."""

import io
import tarfile
import zipfile
import zlib

from PIL import Image


def png(size=(32, 24), mode="RGB", **save):
    out = io.BytesIO()
    Image.new(mode, size, "red" if mode != "P" else 1).save(out, "PNG", **save)
    return out.getvalue()


def jpeg(size=(32, 24), **save):
    out = io.BytesIO()
    Image.new("RGB", size, "blue").save(out, "JPEG", **save)
    return out.getvalue()


def gif_animated(frames=3):
    imgs = [Image.new("RGB", (16, 16), c) for c in ("red", "green", "blue")[:frames]]
    out = io.BytesIO()
    imgs[0].save(out, "GIF", save_all=True, append_images=imgs[1:], duration=120, loop=0,
                 comment=b"<script>alert(1)</script>")
    return out.getvalue()


def pdf(catalog_extra=b"", extra_objects=b""):
    return (
        b"%PDF-1.7\n"
        b"1 0 obj\n<< /Type /Catalog /Pages 2 0 R " + catalog_extra + b">>\nendobj\n"
        b"2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        b"3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>\nendobj\n"
        b"4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 24 Tf 72 720 Td (Hello) Tj ET\nendstream\nendobj\n"
        + extra_objects +
        b"trailer\n<< /Root 1 0 R >>\n%%EOF\n"
    )


def der(tag, *parts):
    """One DER element: tag byte, definite length, the parts concatenated."""
    content = b"".join(parts)
    n = len(content)
    if n < 0x80:
        length = bytes([n])
    else:
        raw = n.to_bytes((n.bit_length() + 7) // 8, "big")
        length = bytes([0x80 | len(raw)]) + raw
    return bytes([tag]) + length + content


def ber(tag, *parts):
    """A constructed element with indefinite length, as streaming signers write it."""
    return bytes([tag, 0x80]) + b"".join(parts) + b"\0\0"


OID_SIGNED_DATA = der(0x06, bytes.fromhex("2a864886f70d010702"))
OID_DATA = der(0x06, bytes.fromhex("2a864886f70d010701"))
OID_DSTU_HASH = der(0x06, bytes.fromhex("2a86240201010101020201"))                 # 1.2.804.2.1.1.1.1.2.2.1
OID_DSTU_SIGN = der(0x06, bytes.fromhex("2a8624020101010103060101"))               # 1.2.804.2.1.1.1.1.3.6.1.1


def certificate(subject="Тестовий підписувач"):
    """Shaped like an X.509 certificate; nothing here checks its contents."""
    name = der(0x30, der(0x31, der(0x30, der(0x06, b"\x55\x04\x03"), der(0x0C, subject.encode()))))
    tbs = der(0x30, der(0xA0, der(0x02, b"\x02")), der(0x02, bytes(range(1, 21))),
              der(0x30, OID_DSTU_SIGN), name, name, der(0x30, OID_DSTU_SIGN, der(0x03, b"\x00" + bytes(33))))
    return der(0x30, tbs, der(0x30, OID_DSTU_SIGN), der(0x03, b"\x00" + bytes(range(64))))


def signer_info():
    return der(0x30, der(0x02, b"\x01"), der(0x30, OID_DSTU_HASH), der(0x30, OID_DSTU_SIGN),
               der(0x04, bytes(range(64, 128))))


def signed(document=None, *, signers=1, certificates=None, detached=False, content_type=OID_DATA,
           oid=OID_SIGNED_DATA):
    """A file with an internal e-signature (CMS SignedData, DER), like a КЕП from a bank or Дія."""
    document = pdf() if document is None else document
    certificates = [certificate()] if certificates is None else certificates
    encap = der(0x30, content_type) if detached else der(0x30, content_type, der(0xA0, der(0x04, document)))
    signed_data = der(0x30, der(0x02, b"\x01"), der(0x31, der(0x30, OID_DSTU_HASH)), encap,
                      der(0xA0, *certificates), der(0x31, *[signer_info() for _ in range(signers)]))
    return der(0x30, oid, der(0xA0, signed_data))


def signed_ber(document=None, chunk=1000):
    """The same as streaming signers write it: indefinite lengths, the document in chunks."""
    document = pdf() if document is None else document
    chunks = [der(0x04, document[i:i + chunk]) for i in range(0, len(document), chunk)]
    encap = ber(0x30, OID_DATA, ber(0xA0, ber(0x24, *chunks)))
    signed_data = ber(0x30, der(0x02, b"\x01"), der(0x31, der(0x30, OID_DSTU_HASH)), encap,
                      der(0xA0, certificate()), der(0x31, signer_info()))
    return ber(0x30, OID_SIGNED_DATA, ber(0xA0, signed_data))


def pdf_objstm(inner, filters=b"/FlateDecode", encode=zlib.compress):
    body = encode(b"5 0 " + inner)
    obj = (b"6 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter " + filters
           + b" /Length " + str(len(body)).encode() + b" >>\nstream\n" + body + b"\nendstream\nendobj\n")
    return pdf(extra_objects=obj)


def zip_of(entries, **kw):
    """entries: list of (name, bytes) or (ZipInfo, bytes)."""
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", compression=kw.get("compression", zipfile.ZIP_DEFLATED)) as zf:
        for name, data in entries:
            zf.writestr(name, data)
    return out.getvalue()


CT_DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"
CT_XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"

RELS_ROOT = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
    'relationships/officeDocument" Target="word/document.xml"/></Relationships>'
)


def content_types(main, part="/word/document.xml", extra=""):
    return (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        '<Default Extension="xml" ContentType="application/xml"/>'
        f'<Override PartName="{part}" ContentType="{main}"/>{extra}</Types>'
    )


def docx(body="<w:p><w:r><w:t>Hello</w:t></w:r></w:p>", doc_rels=None, extra=(), main=CT_DOCX):
    document = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        f"<w:body>{body}</w:body></w:document>"
    )
    entries = [
        ("[Content_Types].xml", content_types(main)),
        ("_rels/.rels", RELS_ROOT),
        ("word/document.xml", document),
    ]
    if doc_rels is not None:
        entries.append(("word/_rels/document.xml.rels", doc_rels))
    entries.extend(extra)
    return zip_of(entries)


def rels(*relationships):
    items = "".join(
        f'<Relationship Id="rId{i}" Type="{t}" Target="{target}"{mode}/>'
        for i, (t, target, mode) in enumerate(relationships, start=1)
    )
    return ('<?xml version="1.0" encoding="UTF-8"?><Relationships '
            f'xmlns="http://schemas.openxmlformats.org/package/2006/relationships">{items}</Relationships>')


def xlsx(formula="SUM(A1:A2)"):
    sheet = (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        f'<row r="1"><c r="A1"><f>{formula}</f></c></row></sheetData></worksheet>'
    )
    return zip_of([
        ("[Content_Types].xml", content_types(CT_XLSX, part="/xl/workbook.xml")),
        ("_rels/.rels", RELS_ROOT.replace("word/document.xml", "xl/workbook.xml")),
        ("xl/workbook.xml", '<?xml version="1.0"?><workbook/>'),
        ("xl/worksheets/sheet1.xml", sheet),
    ])


def odt(content='<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>',
        extra=()):
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w") as zf:
        zf.writestr(zipfile.ZipInfo("mimetype"), "application/vnd.oasis.opendocument.text")
        zf.writestr("META-INF/manifest.xml", '<?xml version="1.0"?><manifest:manifest '
                    'xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"/>')
        zf.writestr("content.xml", content)
        for name, data in extra:
            zf.writestr(name, data)
    return out.getvalue()


def tar_gz(entries, symlink=None):
    out = io.BytesIO()
    with tarfile.open(fileobj=out, mode="w:gz") as tf:
        for name, data in entries:
            info = tarfile.TarInfo(name)
            info.size = len(data)
            tf.addfile(info, io.BytesIO(data))
        if symlink:
            info = tarfile.TarInfo(symlink[0])
            info.type = tarfile.SYMTYPE
            info.linkname = symlink[1]
            tf.addfile(info)
    return out.getvalue()


def video(path, kind="mp4", seconds=2, size="320x240", extra=()):
    """Render a test clip with ffmpeg (tests using it skip when ffmpeg is missing)."""
    import subprocess
    codecs = {
        "mp4": ["-c:v", "libx264", "-c:a", "aac"],
        "mov": ["-c:v", "libx264", "-c:a", "aac"],
        "webm": ["-c:v", "libvpx-vp9", "-b:v", "200k", "-c:a", "libopus"],
        "mkv": ["-c:v", "libx264", "-c:a", "aac"],
        "mpeg2.mkv": ["-c:v", "mpeg2video", "-c:a", "mp2"],
    }[kind]
    subprocess.run(
        ["ffmpeg", "-v", "error", "-y", "-f", "lavfi", "-i", f"testsrc2=size={size}:rate=25",
         "-f", "lavfi", "-i", "sine=frequency=440", "-t", str(seconds), *codecs, *extra, str(path)],
        check=True,
    )
    return path
