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
