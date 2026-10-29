"""Raster images: decode fully, then re-encode from pixels only.

Re-encoding is what makes this safe: EXIF/XMP/ICC metadata, comments,
trailing data (PHP/JS appended to a JPEG, GIFAR polyglots) and malformed
chunks aimed at other decoders do not survive. SVG is deliberately not
supported because it is a script-capable document, not an image.

make_variants() then derives the resized copies an app asked for (small,
medium, ...) from the already sanitized image.
"""

import io

from PIL import Image, ImageOps, ImageSequence, UnidentifiedImageError

from .common import ValidationError

# Pillow's own bomb check is replaced by the explicit limits below.
Image.MAX_IMAGE_PIXELS = None

PIL_FORMATS = {"jpg": "JPEG", "png": "PNG", "gif": "GIF", "webp": "WEBP"}


def _magic_ok(data, ext):
    if ext == "jpg":
        return data.startswith(b"\xff\xd8\xff")
    if ext == "png":
        return data.startswith(b"\x89PNG\r\n\x1a\n")
    if ext == "gif":
        return data[:6] in (b"GIF87a", b"GIF89a")
    if ext == "webp":
        return data[:4] == b"RIFF" and data[8:12] == b"WEBP"
    return False


def _bare(img):
    """A pixel-only copy: Pillow copies .info on every operation, so clear it."""
    img = img.copy()
    img.info = {}
    return img


def sanitize_image(data, ext, ctx):
    limits = ctx.limits
    if not _magic_ok(data, ext):
        ctx.fail("content_mismatch", f"Content is not a valid .{ext} image")
    fmt = PIL_FORMATS[ext]
    try:
        # `formats` pins the decoder, so a crafted file cannot be routed to
        # some rarely used (and less audited) Pillow plugin.
        img = Image.open(io.BytesIO(data), formats=[fmt])
        if img.format != fmt:
            ctx.fail("content_mismatch", f"Content is not a valid .{ext} image")
        # Only the header has been parsed so far: check size before decoding.
        width, height = img.size
        if (
            width < 1 or height < 1
            or width > limits.image_max_side or height > limits.image_max_side
            or width * height > limits.image_max_pixels
        ):
            ctx.fail("image_too_large", f"Image dimensions {width}x{height} are not allowed")
        frames = getattr(img, "n_frames", 1)
        if frames > limits.image_max_frames or width * height * frames > limits.image_max_total_pixels:
            ctx.fail("image_too_large", "Animated image has too many frames")
        out = io.BytesIO()
        if frames > 1 and ext in ("gif", "webp"):
            _save_animated(img, fmt, out)
        else:
            _save_still(img, ext, fmt, out, limits)
    except ValidationError:
        raise
    except (UnidentifiedImageError, OSError, ValueError, SyntaxError,
            Image.DecompressionBombError, EOFError, MemoryError) as exc:
        ctx.fail("invalid_image", f"Image could not be decoded ({type(exc).__name__})")
    return out.getvalue()


def _save_still(img, ext, fmt, out, limits):
    img.load()
    # Apply EXIF orientation before the EXIF block is dropped.
    transparency = img.info.get("transparency")
    img = _bare(ImageOps.exif_transpose(img))
    _encode(img, ext, fmt, out, limits, transparency)


def _encode(img, ext, fmt, out, limits, transparency=None):
    if ext == "jpg":
        if img.mode not in ("RGB", "L", "CMYK"):
            img = img.convert("RGB")
        img.save(out, "JPEG", quality=limits.jpeg_quality, optimize=True)
    elif ext == "webp":
        if img.mode not in ("RGB", "RGBA"):
            img = img.convert("RGBA")
        img.save(out, "WEBP", quality=limits.jpeg_quality, method=4)
    else:
        params = {}
        if transparency is not None and img.mode in ("P", "L", "RGB", "1"):
            params["transparency"] = transparency
        img.save(out, fmt, **params)


def _save_animated(img, fmt, out, transform=None):
    frames, durations = [], []
    for frame in ImageSequence.Iterator(img):
        durations.append(max(int(frame.info.get("duration", 100) or 0), 10))
        frame = frame.convert("RGBA")
        frames.append(_bare(transform(frame) if transform else frame))
    loop = int(img.info.get("loop", 0) or 0)
    frames[0].save(
        out, fmt, save_all=True, append_images=frames[1:],
        duration=durations, loop=loop, disposal=2,
    )


def _px(value):
    # Half up, like most image tools (Python's round() would turn 52.5 into 52).
    return max(1, int(value + 0.5))


def target_box(width, height, w, h, mode):
    """(crop box or None, output size) for one requested size; never upscales."""
    if mode == "crop":
        # Largest centred region with the requested aspect ratio...
        if width * h > height * w:
            cw, ch = _px(height * w / h), height
        else:
            cw, ch = width, _px(width * h / w)
        left, top = (width - cw) // 2, (height - ch) // 2
        # ...scaled down to w x h, or kept as is if it is smaller already.
        size = (w, h) if cw >= w else (cw, ch)
        return (left, top, left + cw, top + ch), size
    if w and h:
        scale = min(w / width, h / height)
    else:
        scale = w / width if w else h / height
    scale = min(scale, 1.0)
    return None, (_px(width * scale), _px(height * scale))


def _resize(img, box, size):
    if box is not None:
        img = img.crop(box)
    if img.size != size:
        img = img.resize(size, Image.LANCZOS, reducing_gap=3.0)
    return img


def make_variants(data, ext, sizes, limits):
    """Resized copies of an already sanitized image.

    Returns ((width, height), [(name, bytes, width, height), ...]).
    """
    fmt = PIL_FORMATS[ext]
    img = Image.open(io.BytesIO(data), formats=[fmt])
    width, height = img.size
    variants = []
    if not sizes:
        return (width, height), variants
    animated = getattr(img, "n_frames", 1) > 1 and ext in ("gif", "webp")
    if not animated:
        img.load()
        transparency = img.info.get("transparency")
        base = _bare(img)
        # Resample palette/1-bit images in full colour, then encode as usual.
        if base.mode in ("P", "1", "LA") or (base.mode == "L" and transparency is not None):
            base, transparency = base.convert("RGBA"), None
    for name, w, h, mode in sizes:
        box, size = target_box(width, height, w, h, mode)
        out = io.BytesIO()
        if animated:
            img.seek(0)
            _save_animated(img, fmt, out, transform=lambda frame: _resize(frame, box, size))
        else:
            _encode(_bare(_resize(base, box, size)), ext, fmt, out, limits, transparency)
        variants.append((name, out.getvalue(), size[0], size[1]))
    return (width, height), variants
