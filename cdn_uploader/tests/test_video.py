import json
import shutil
import subprocess

import pytest

from uploader.validators import ValidationError, VideoSettings, process_video

from . import samples

pytestmark = pytest.mark.skipif(not shutil.which("ffmpeg"), reason="ffmpeg not installed")

SETTINGS = VideoSettings(output_max_side=160, threads=2)


@pytest.fixture(scope="module")
def clips(tmp_path_factory):
    d = tmp_path_factory.mktemp("clips")
    return {
        "mp4": samples.video(d / "a.mp4", extra=["-metadata", "title=<script>alert(1)</script>",
                                                 "-metadata", "location=+50.45+030.52/"]),
        "mov": samples.video(d / "a.mov", "mov"),
        "webm": samples.video(d / "a.webm", "webm"),
        "mkv": samples.video(d / "a.mkv", "mkv", extra=["-attach", str(samples.__file__),
                                                         "-metadata:s:t", "mimetype=text/x-python"]),
        "mpeg2": samples.video(d / "b.mkv", "mpeg2.mkv"),
    }


def run(path, ext, tmp_path, vs=SETTINGS):
    return process_video(str(path), ext, str(tmp_path), vs)


def streams(path):
    out = subprocess.run(["ffprobe", "-v", "error", "-show_entries",
                          "stream=codec_type,codec_name,width,height:format_tags", "-of", "json", path],
                         capture_output=True, check=True).stdout
    return json.loads(out)


def rejected(path, ext, tmp_path, vs=SETTINGS):
    with pytest.raises(ValidationError) as info:
        run(path, ext, tmp_path, vs)
    return info.value.code


@pytest.mark.parametrize("kind", ["mp4", "mov", "webm", "mkv"])
def test_transcoded_to_clean_h264_mp4(clips, kind, tmp_path):
    progress = []
    out, ext, mime = process_video(str(clips[kind]), kind, str(tmp_path), SETTINGS, progress.append)
    assert (ext, mime) == ("mp4", "video/mp4")
    info = streams(out)
    assert sorted(s["codec_name"] for s in info["streams"]) == ["aac", "h264"]  # attachments dropped
    video = next(s for s in info["streams"] if s["codec_type"] == "video")
    assert max(video["width"], video["height"]) <= 160
    assert progress and progress[-1] == 1.0


def test_metadata_stripped(clips, tmp_path):
    out, _, _ = run(clips["mp4"], "mp4", tmp_path)
    tags = streams(out).get("format", {}).get("tags", {})
    assert "title" not in tags and "location" not in tags
    assert b"script" not in open(out, "rb").read()


def test_remux_keeps_codecs(clips, tmp_path):
    out, ext, _ = run(clips["webm"], "webm", tmp_path, VideoSettings(mode="remux"))
    assert ext == "webm"
    assert sorted(s["codec_name"] for s in streams(out)["streams"]) == ["opus", "vp9"]


def test_hls_playlist_disguised_as_mp4_rejected(tmp_path):
    # Classic ffmpeg SSRF / local file read: an HLS playlist named .mp4.
    p = tmp_path / "x.mp4"
    p.write_bytes(b"#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:10.0,\nfile:///etc/passwd\n#EXT-X-ENDLIST\n")
    assert rejected(p, "mp4", tmp_path) == "content_mismatch"


def test_playlist_behind_fake_ftyp_still_not_parsed_as_playlist(tmp_path):
    # Even with a valid-looking first box the demuxer is forced to mov/mp4,
    # so ffmpeg never interprets the rest as a playlist or concat script.
    p = tmp_path / "x.mp4"
    p.write_bytes(b"\x00\x00\x00\x14ftypisom\x00\x00\x02\x00isom"
                  b"#EXTM3U\n#EXTINF:10.0,\nfile:///etc/passwd\n#EXT-X-ENDLIST\n")
    assert rejected(p, "mp4", tmp_path) == "invalid_video"


def test_concat_script_rejected(tmp_path):
    p = tmp_path / "x.mkv"
    p.write_bytes(b"ffconcat version 1.0\nfile '/etc/passwd'\n")
    assert rejected(p, "mkv", tmp_path) == "content_mismatch"


def test_image_disguised_as_video_rejected(tmp_path):
    p = tmp_path / "x.mp4"
    p.write_bytes(samples.png())
    assert rejected(p, "mp4", tmp_path) == "content_mismatch"


def test_webm_extension_on_mkv_rejected(clips, tmp_path):
    assert rejected(clips["mkv"], "webm", tmp_path) == "content_mismatch"


def test_unsupported_codec_rejected(clips, tmp_path):
    assert rejected(clips["mpeg2"], "mkv", tmp_path) == "unsupported_codec"


def test_too_long_rejected(clips, tmp_path):
    assert rejected(clips["mp4"], "mp4", tmp_path, VideoSettings(max_seconds=1)) == "video_too_long"


def test_resolution_limit(clips, tmp_path):
    assert rejected(clips["mp4"], "mp4", tmp_path, VideoSettings(max_input_side=200)) == "video_too_large"


def test_timeout_kills_hung_ffmpeg(clips, tmp_path):
    hung = tmp_path / "ffmpeg"
    hung.write_text("#!/bin/sh\nsleep 30\n")
    hung.chmod(0o755)
    vs = VideoSettings(timeout=1, ffmpeg=str(hung))
    assert rejected(clips["mp4"], "mp4", tmp_path, vs) == "video_timeout"


def test_truncated_video_rejected(clips, tmp_path):
    p = tmp_path / "cut.mp4"
    p.write_bytes(clips["mp4"].read_bytes()[:2000])
    assert rejected(p, "mp4", tmp_path) == "invalid_video"
