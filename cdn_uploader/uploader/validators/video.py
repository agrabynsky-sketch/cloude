"""Video: probe with ffprobe, then re-encode (default) or remux with ffmpeg.

Re-encoding is the video equivalent of redrawing an image: the stored file
is produced by x264/AAC from decoded frames, so nothing from the uploaded
container or bitstream (metadata, attachments, subtitles, extra tracks,
malformed NAL units aimed at players) reaches viewers. "remux" mode only
rebuilds the container (fast, keeps the original codec data).

ffmpeg itself parses untrusted input, so it is boxed in:
  * the demuxer is forced from the extension (no auto-detected HLS/concat
    playlists, the classic ffmpeg SSRF/local-file-read vector),
  * only the "file" protocol is allowed and MOV external references are off,
  * codecs are allowlisted before anything is decoded,
  * the process gets CPU/memory/file-size limits, a timeout and an empty env.
"""

import json
import logging
import os
import resource
import signal
import subprocess
import threading
import time
from dataclasses import dataclass

from .common import MB, ValidationError

DEMUXERS = {"mp4": "mov", "mov": "mov", "webm": "matroska", "mkv": "matroska"}
FORMAT_FAMILY = {"mov": "mov,mp4", "matroska": "matroska"}

VIDEO_CODECS = {"h264", "hevc", "vp8", "vp9", "av1", "mpeg4"}
AUDIO_CODECS = {"aac", "mp3", "opus", "vorbis", "ac3", "eac3", "alac", "flac",
                "pcm_s16le", "pcm_s16be", "pcm_s24le"}
# What each output container can carry without re-encoding (remux mode).
MP4_COPY = ({"h264", "hevc", "av1", "mpeg4", "vp9"}, {"aac", "mp3", "opus", "ac3", "eac3", "alac", "flac"})
WEBM_COPY = ({"vp8", "vp9", "av1"}, {"opus", "vorbis"})
MOV_FIRST_ATOMS = (b"ftyp", b"wide", b"free", b"skip", b"mdat", b"moov", b"pnot")
SAFE_ENV = {"PATH": "/usr/local/bin:/usr/bin:/bin", "LC_ALL": "C"}

log = logging.getLogger("uploader")


@dataclass(frozen=True)
class VideoSettings:
    mode: str = "transcode"          # "transcode" | "remux"
    max_size: int = 500 * MB
    max_seconds: int = 600
    max_input_side: int = 8192
    output_max_side: int = 1920
    max_fps: int = 60
    crf: int = 23
    threads: int = 4
    timeout: int = 900
    memory: int = 4096 * MB
    ffmpeg: str = "ffmpeg"
    ffprobe: str = "ffprobe"


def _fail(code, message):
    raise ValidationError(code, message)


def check_magic(path, ext):
    with open(path, "rb") as fh:
        head = fh.read(64)
    if ext in ("mp4", "mov"):
        ok = head[4:8] == b"ftyp" if ext == "mp4" else head[4:8] in MOV_FIRST_ATOMS
    else:
        ok = head.startswith(b"\x1a\x45\xdf\xa3") and (b"webm" if ext == "webm" else b"matroska") in head
    if not ok:
        _fail("content_mismatch", f"Content is not a valid .{ext} video")


def _input_args(path, ext):
    demuxer = DEMUXERS[ext]
    args = ["-protocol_whitelist", "file", "-f", demuxer]
    if demuxer == "mov":
        args += ["-enable_drefs", "0"]
    return args + ["-i", "file:" + os.path.abspath(path)]


def _start(cmd, vs, stdout):
    proc = subprocess.Popen(
        cmd, stdin=subprocess.DEVNULL, stdout=stdout, stderr=subprocess.PIPE,
        env=SAFE_ENV, close_fds=True, start_new_session=True,
    )
    # Applied right after spawn (preexec_fn is unsafe in a threaded server).
    for limit, value in (
        (resource.RLIMIT_AS, vs.memory),
        (resource.RLIMIT_FSIZE, vs.max_size * 2),
        (resource.RLIMIT_CPU, vs.timeout * max(vs.threads, 1)),
        (resource.RLIMIT_NOFILE, 64),
    ):
        try:
            resource.prlimit(proc.pid, limit, (value, value))
        except (ProcessLookupError, PermissionError, ValueError, OSError):
            pass
    return proc


def _kill(proc):
    try:
        os.killpg(proc.pid, signal.SIGKILL)
    except (ProcessLookupError, PermissionError):
        pass


def _drain(stream, keep, limit=4096):
    """Read a pipe to EOF (so the child never blocks on it), keeping the start."""
    size = 0
    for chunk in iter(lambda: stream.read(8192), b""):
        if size < limit:
            keep.append(chunk[:limit - size])
            size += len(keep[-1])


def probe(path, ext, vs):
    cmd = [vs.ffprobe, "-v", "error", "-hide_banner", *_input_args(path, ext),
           "-show_entries",
           "format=format_name,duration:stream=index,codec_type,codec_name,width,height,"
           "avg_frame_rate,duration:stream_disposition=attached_pic",
           "-of", "json"]
    proc = _start(cmd, vs, subprocess.PIPE)
    try:
        out, err = proc.communicate(timeout=60)
    except subprocess.TimeoutExpired:
        _kill(proc)
        proc.communicate()
        _fail("invalid_video", "Video could not be analysed in time")
    if proc.returncode != 0:
        _fail("invalid_video", "Video is corrupt or not supported")
    try:
        return json.loads(out)
    except json.JSONDecodeError:
        _fail("invalid_video", "Video is corrupt or not supported")


def _float(value, default=0.0):
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def inspect(info, ext, vs):
    """Pick the streams to keep, or reject. Returns (video, audio or None, duration)."""
    fmt = info.get("format") or {}
    if not str(fmt.get("format_name", "")).startswith(FORMAT_FAMILY[DEMUXERS[ext]]):
        _fail("content_mismatch", f"Content is not a valid .{ext} video")
    streams = info.get("streams") or []
    if len(streams) > 32:
        _fail("invalid_video", "Video has too many streams")
    videos = [s for s in streams if s.get("codec_type") == "video"
              and not (s.get("disposition") or {}).get("attached_pic")]
    audios = [s for s in streams if s.get("codec_type") == "audio"]
    if not videos:
        _fail("invalid_video", "File contains no video stream")
    video = videos[0]
    audio = audios[0] if audios else None

    if video.get("codec_name") not in VIDEO_CODECS:
        _fail("unsupported_codec", f"Video codec {video.get('codec_name')!r} is not supported")
    if audio is not None and audio.get("codec_name") not in AUDIO_CODECS:
        _fail("unsupported_codec", f"Audio codec {audio.get('codec_name')!r} is not supported")
    width, height = int(video.get("width") or 0), int(video.get("height") or 0)
    if not (0 < width <= vs.max_input_side and 0 < height <= vs.max_input_side):
        _fail("video_too_large", f"Video resolution {width}x{height} is not allowed")
    duration = _float(fmt.get("duration")) or _float(video.get("duration"))
    if duration <= 0:
        _fail("invalid_video", "Video duration cannot be determined")
    if duration > vs.max_seconds:
        _fail("video_too_long", f"Video is longer than {vs.max_seconds // 60} minutes")
    return video, audio, duration


def _output_plan(ext, video, audio, vs):
    """ffmpeg codec arguments plus the stored extension and MIME type."""
    if vs.mode == "remux":
        vcodec, acodec = video["codec_name"], audio["codec_name"] if audio else None
        for out_ext, mime, (vok, aok) in (("webm", "video/webm", WEBM_COPY), ("mp4", "video/mp4", MP4_COPY)):
            if ext in ("mp4", "mov") and out_ext == "webm":
                continue
            if vcodec in vok and (acodec is None or acodec in aok):
                args = ["-c", "copy"]
                if out_ext == "mp4":
                    args += ["-movflags", "+faststart"]
                    if vcodec == "hevc":
                        args += ["-tag:v", "hvc1"]
                return args, out_ext, mime
        _fail("unsupported_codec", "Codecs cannot be stored without re-encoding")
    side = vs.output_max_side
    args = [
        "-c:v", "libx264", "-preset", "veryfast", "-crf", str(vs.crf),
        "-profile:v", "high", "-pix_fmt", "yuv420p", "-fpsmax", str(vs.max_fps),
        "-vf", f"scale=w='min(iw,{side})':h='min(ih,{side})':force_original_aspect_ratio=decrease"
               ":force_divisible_by=2",
        "-c:a", "aac", "-b:a", "128k", "-ac", "2",
        "-movflags", "+faststart",
    ]
    return args, "mp4", "video/mp4"


def process_video(src, ext, workdir, vs, progress=None):
    """Validate `src` and write a clean copy. Returns (output path, ext, mime)."""
    check_magic(src, ext)
    video, audio, duration = inspect(probe(src, ext, vs), ext, vs)
    codec_args, out_ext, mime = _output_plan(ext, video, audio, vs)
    out = os.path.join(workdir, f"{os.path.basename(src)}.out.{out_ext}")
    maps = ["-map", f"0:{int(video['index'])}"]
    if audio is not None:
        maps += ["-map", f"0:{int(audio['index'])}"]
    cmd = [
        vs.ffmpeg, "-nostdin", "-hide_banner", "-loglevel", "error", "-y",
        "-threads", str(vs.threads), *_input_args(src, ext),
        *maps, "-map_metadata", "-1", "-map_chapters", "-1", "-sn", "-dn",
        "-fflags", "+bitexact", "-threads", str(vs.threads), *codec_args,
        "-progress", "pipe:1", "-nostats",
        "-f", "mp4" if out_ext == "mp4" else "webm", "file:" + out,
    ]
    proc = _start(cmd, vs, subprocess.PIPE)
    timer = threading.Timer(vs.timeout, _kill, args=(proc,))
    timer.start()
    started = time.monotonic()
    errors = []
    reader = threading.Thread(target=_drain, args=(proc.stderr, errors), daemon=True)
    reader.start()
    try:
        for line in proc.stdout:
            key, _, value = line.decode("ascii", "replace").strip().partition("=")
            if key == "out_time_us" and progress and value.isdigit():
                progress(min(int(value) / 1e6 / duration, 0.99))
        proc.wait()
    finally:
        timer.cancel()
    reader.join(timeout=5)
    if proc.returncode != 0:
        log.info("ffmpeg failed rc=%s: %s", proc.returncode,
                 b"".join(errors).decode("utf-8", "replace").strip()[:500])
        try:
            os.unlink(out)
        except FileNotFoundError:
            pass
        if time.monotonic() - started >= vs.timeout:
            _fail("video_timeout", "Video processing took too long")
        _fail("invalid_video", "Video could not be processed (corrupt or unsupported)")
    if progress:
        progress(1.0)
    return out, out_ext, mime
