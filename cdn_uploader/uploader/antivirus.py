"""Optional ClamAV scan through clamd's INSTREAM protocol (no extra dependency).

UPLOADER_CLAMD=unix:/run/clamav/clamd.ctl   or   tcp:127.0.0.1:3310
"""

import socket
import struct

CHUNK = 64 * 1024


class AntivirusError(Exception):
    pass


class ClamdScanner:
    def __init__(self, address, timeout=30):
        self.address = address
        self.timeout = timeout

    def _connect(self):
        kind, _, target = self.address.partition(":")
        if kind == "unix":
            sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            sock.settimeout(self.timeout)
            sock.connect(target)
        elif kind == "tcp":
            host, _, port = target.rpartition(":")
            sock = socket.create_connection((host, int(port)), timeout=self.timeout)
        else:
            raise AntivirusError(f"Unsupported clamd address {self.address!r}")
        return sock

    def scan(self, data):
        """Return the signature name if infected, None if clean."""
        try:
            with self._connect() as sock:
                sock.sendall(b"zINSTREAM\0")
                for i in range(0, len(data), CHUNK):
                    chunk = data[i:i + CHUNK]
                    sock.sendall(struct.pack("!L", len(chunk)) + chunk)
                sock.sendall(struct.pack("!L", 0))
                reply = b""
                while not reply.endswith(b"\0"):
                    part = sock.recv(4096)
                    if not part:
                        break
                    reply += part
        except OSError as exc:
            raise AntivirusError(f"clamd unavailable: {exc}") from None
        text = reply.rstrip(b"\0").decode("utf-8", "replace").strip()
        if text.endswith("OK"):
            return None
        if text.endswith("FOUND"):
            return text.removeprefix("stream:").removesuffix("FOUND").strip()
        raise AntivirusError(f"clamd error: {text}")
