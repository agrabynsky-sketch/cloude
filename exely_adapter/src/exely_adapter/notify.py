"""Booking push plumbing: the Unit.Travel webhook signature and the Exely booking notification."""

import hashlib
import hmac
import logging

import httpx

log = logging.getLogger(__name__)

SIGNATURE_HEADER = "X-UnitTravel-Signature"


def verify_signature(secret: str, body: bytes, signature: str | None) -> bool:
    """Check the HMAC-SHA256 Unit.Travel signs webhooks with (hex, optionally "sha256=" prefixed)."""
    if not signature:
        return False
    received = signature.strip()
    if received.lower().startswith("sha256="):
        received = received[len("sha256=") :]
    expected = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, received.lower())


class ExelyNotifier:
    """Asks Exely to call get-bookings now instead of at its next 10-minute poll."""

    def __init__(self, url: str, *, timeout: float = 10.0, transport: httpx.BaseTransport | None = None):
        self._url = url
        self._http = httpx.Client(timeout=timeout, transport=transport) if url else None

    def notify(self) -> None:
        if self._http is None:
            return
        try:
            response = self._http.get(self._url)
        except httpx.HTTPError as exc:
            log.warning("Exely booking notification failed: %s", exc)
            return
        if response.status_code != 200:
            log.warning("Exely booking notification answered %s", response.status_code)

    def close(self) -> None:
        if self._http is not None:
            self._http.close()
