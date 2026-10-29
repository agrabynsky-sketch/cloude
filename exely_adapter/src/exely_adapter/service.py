"""Exely Channel Manager requests → Unit.Travel Partner API calls."""

import hmac
import logging
import threading
import time
import uuid
from collections.abc import Callable, Hashable
from datetime import UTC, date, datetime
from typing import Any

from pydantic import ValidationError

from .ari import ARI_KINDS, AriParts, build_ari
from .bookings import to_exely_booking
from .catalog import build_catalog
from .config import Settings
from .protocol import (
    Code,
    ConfirmData,
    Envelope,
    ExelyFailure,
    HotelData,
    Issue,
    UpdateData,
    failure,
    success,
)
from .store import BookingRecord, BookingStore, ConfirmOutcome
from .unit_travel import (
    AriStatus,
    Connection,
    ConnectionScope,
    RatePlan,
    Reservation,
    UnitTravelClient,
    UnitTravelError,
)

log = logging.getLogger(__name__)

CONNECTION_NOTE = "Exely Channel Manager"
CONNECTION_TTL_SECONDS = 60.0
RATE_PLANS_TTL_SECONDS = 300.0
FINAL_ARI_STATUSES = {"applied", "failed", "skipped"}

ActionResult = tuple[Any, list[Issue]]


class ExelyService:
    def __init__(
        self,
        settings: Settings,
        unit_travel: UnitTravelClient,
        store: BookingStore,
        *,
        today: Callable[[], date] = lambda: datetime.now(UTC).date(),
    ):
        self._settings = settings
        self._ut = unit_travel
        self._store = store
        self._today = today
        self._connections = TtlCache(CONNECTION_TTL_SECONDS)
        self._rate_plans = TtlCache(RATE_PLANS_TTL_SECONDS)
        self._revisions = RevisionClock()
        self._actions: dict[str, Callable[[dict[str, Any]], ActionResult]] = {
            "get-rooms-and-rate-plans": self._get_rooms_and_rate_plans,
            "update": self._update,
            "get-bookings": self._get_bookings,
            "confirm-bookings": self._confirm_bookings,
        }

    def handle(self, payload: object) -> dict[str, Any]:
        """Answer one Exely request; never raises."""
        try:
            envelope = Envelope.model_validate(payload)
        except ValidationError:
            return failure(
                [Issue(Code.INVALID_REQUEST, "Malformed request: expected username, password, action and data")]
            )
        if not self._authorized(envelope):
            return failure([Issue(Code.INVALID_CREDENTIALS, "Invalid username or password")])
        action = self._actions.get(envelope.action)
        if action is None:
            return failure([Issue(Code.UNKNOWN_ACTION, f"Unknown action {envelope.action!r}")])
        try:
            data, warnings = action(envelope.data)
        except ExelyFailure as exc:
            log.info("Exely %s failed: %s", envelope.action, exc)
            return failure(exc.errors, exc.warnings)
        except ValidationError as exc:
            return failure([Issue(Code.INVALID_REQUEST, _describe(exc))])
        except UnitTravelError as exc:
            log.warning("Unit.Travel call for Exely %s failed: %s", envelope.action, exc)
            return failure(issues_from_unit_travel(exc))
        except Exception:
            log.exception("Exely %s crashed", envelope.action)
            return failure([Issue(Code.INTERNAL_ERROR, "Internal adapter error")])
        return success(data, warnings)

    def accept_reservation(self, reservation: dict[str, Any]) -> bool:
        """Store a reservation pushed by the Unit.Travel webhook; True when Exely should be notified."""
        return self._store.upsert(reservation)

    def forget_connection(self, property_id: Hashable) -> None:
        self._connections.drop(property_id)
        self._rate_plans.drop(property_id)

    def _authorized(self, envelope: Envelope) -> bool:
        user_ok = hmac.compare_digest(envelope.username.encode(), self._settings.exely_username.encode())
        password_ok = hmac.compare_digest(envelope.password.encode(), self._settings.exely_password.encode())
        return user_ok and password_ok

    # get-rooms-and-rate-plans

    def _get_rooms_and_rate_plans(self, data: dict[str, Any]) -> ActionResult:
        property_id = _property_id(HotelData.model_validate(data).hotel_id)
        # The property asks Exely to connect here, so this is where the Unit.Travel connection is requested.
        connection = self._active_connection(property_id, request_if_missing=True)
        rooms = self._ut.get_rooms(property_id)
        rate_plans = self._ut.get_rate_plans(property_id)
        self._rate_plans.put(property_id, rate_plans)
        catalog, warnings = build_catalog(rooms, rate_plans, connection.scope)
        return {"hotelId": str(property_id), "roomsAndRatePlans": catalog}, warnings

    def _active_connection(self, property_id: int, *, request_if_missing: bool = False) -> Connection:
        cached = self._connections.get(property_id)
        if cached is not None:
            return cached
        try:
            if request_if_missing:
                connection: Connection | None = self._ut.create_connection(property_id, note=CONNECTION_NOTE)
            else:
                connection = _pick_connection(self._ut.list_connections(property_id=property_id))
        except UnitTravelError as exc:
            if exc.status == 404:
                raise ExelyFailure(
                    Issue(Code.HOTEL_NOT_FOUND, f"Property {property_id} does not exist in Unit.Travel")
                ) from exc
            raise
        if connection is None or connection.status != "active":
            raise ExelyFailure(Issue(Code.HOTEL_NOT_CONNECTED, _not_active_message(property_id, connection)))
        self._connections.put(property_id, connection)
        return connection

    # update

    def _update(self, data: dict[str, Any]) -> ActionResult:
        request = UpdateData.model_validate(data)
        property_id = _property_id(request.hotel_id)
        connection = self._active_connection(property_id)
        parts = build_ari(request.updates, lambda rate_plan_id: self._currency(property_id, rate_plan_id))
        warnings = parts.warnings + _drop_not_permitted(parts, connection.scope)
        if not parts.is_empty():
            warnings += self._send_ari(property_id, parts)
        return None, warnings

    def _currency(self, property_id: int, rate_plan_id: int) -> str:
        plans: list[RatePlan] | None = self._rate_plans.get(property_id)
        if plans is None:
            plans = self._ut.get_rate_plans(property_id)
            self._rate_plans.put(property_id, plans)
        plan = next((p for p in plans if p.id == rate_plan_id), None)
        if plan is None:
            raise ExelyFailure(Issue(Code.INVALID_RATE_PLAN, f"Rate plan {rate_plan_id} does not exist in Unit.Travel"))
        if not plan.currency:
            raise ExelyFailure(
                Issue(Code.INVALID_CURRENCY, f"currencyCode is missing and rate plan {rate_plan_id} has no currency")
            )
        return plan.currency

    def _send_ari(self, property_id: int, parts: AriParts) -> list[Issue]:
        warnings: list[Issue] = []
        for chunk in parts.chunks(self._settings.ari_max_items_per_message):
            message = {
                "message_uid": f"exely-{property_id}-{uuid.uuid4().hex}",
                # Exely sends updates one after another, so arrival order is the order of change.
                "revision": self._revisions.next(),
                "property_id": property_id,
                **chunk,
            }
            status = self._ut.post_ari(message)
            if status.status not in FINAL_ARI_STATUSES:
                status = self._wait_for_ari(message["message_uid"], status)
            if status.status == "failed":
                errors = issues_from_errors(status.errors) or [
                    Issue(Code.UPSTREAM_ERROR, "Unit.Travel failed to apply the update")
                ]
                raise ExelyFailure(*errors, warnings=warnings)
            if status.status not in FINAL_ARI_STATUSES:
                # Answering "false" would make Exely resend and pile up duplicates while we are slow.
                log.warning("ARI message %s still %s after waiting", message["message_uid"], status.status)
                warnings.append(
                    Issue(
                        Code.STILL_PROCESSING,
                        f"Unit.Travel accepted update {message['message_uid']} but has not applied it yet",
                    )
                )
        return warnings

    def _wait_for_ari(self, message_uid: str, status: AriStatus) -> AriStatus:
        deadline = time.monotonic() + self._settings.ari_status_wait_seconds
        while time.monotonic() < deadline:
            time.sleep(self._settings.ari_status_poll_interval)
            status = self._ut.get_ari_status(message_uid)
            if status.status in FINAL_ARI_STATUSES:
                break
        return status

    # get-bookings / confirm-bookings

    def _get_bookings(self, data: dict[str, Any]) -> ActionResult:
        request = HotelData.model_validate(data)
        self._flush_acks()
        today, days = self._today(), self._settings.booking_relevance_days
        if request.hotel_id:
            property_id = _property_id(request.hotel_id)
            if self._settings.ut_pull_reservations:
                self._pull(property_id)
            records = self._store.for_property(property_id, today, days)
        else:
            if self._settings.ut_pull_reservations:
                self._pull_all()
            records = self._store.pending(today, days)
        return {"bookings": [_to_exely(record) for record in records]}, []

    def _pull_all(self) -> None:
        try:
            connections = self._ut.list_connections(status="active")
        except UnitTravelError as exc:
            log.warning("Could not list Unit.Travel connections, answering from stored bookings: %s", exc)
            return
        for connection in connections:
            self._pull(connection.property_id)

    def _pull(self, property_id: int) -> None:
        try:
            for reservation in self._ut.iter_reservations(property_id):
                try:
                    self._store.upsert(reservation)
                except ValidationError:
                    log.error("Skipping malformed reservation %s", reservation.get("reservation_id"))
        except UnitTravelError as exc:
            log.warning("Could not pull reservations of property %s: %s", property_id, exc)

    def _confirm_bookings(self, data: dict[str, Any]) -> ActionResult:
        request = ConfirmData.model_validate(data)
        warnings = []
        for item in request.confirm_bookings:
            outcome = self._store.confirm(
                item.number, echo_token=item.echo_token, status=item.status, exely_number=item.external_number
            )
            if outcome is ConfirmOutcome.UNKNOWN:
                warnings.append(Issue(Code.UNKNOWN_BOOKING, f"Booking {item.number} is unknown to the channel"))
            elif outcome is ConfirmOutcome.REJECTED:
                log.error(
                    "Exely did not create booking %s (no externalNumber): the property may be overbooked", item.number
                )
        self._flush_acks()
        return None, warnings

    def _flush_acks(self) -> None:
        """Acknowledge to Unit.Travel what Exely confirmed; failures are retried on the next call."""
        for record in self._store.pending_acks():
            details = _ack_details(record) if self._settings.ut_ack_with_details else None
            try:
                self._ut.acknowledge(record.reservation_id, details)
            except UnitTravelError as exc:
                if exc.status == 0 or exc.status == 429 or exc.status >= 500:
                    log.warning("Acknowledging reservations paused, will retry: %s", exc)
                    return
                # Retrying a rejected acknowledge would fail the same way forever.
                log.error("Unit.Travel rejected acknowledging %s: %s", record.reservation_id, exc)
            self._store.mark_acked(record.reservation_id, record.version)


def issues_from_unit_travel(exc: UnitTravelError) -> list[Issue]:
    if exc.status == 409:
        return [Issue(Code.HOTEL_NOT_CONNECTED, f"Unit.Travel: {exc.message}")]
    if exc.status == 404:
        return [Issue(Code.HOTEL_NOT_FOUND, f"Unit.Travel: {exc.message}")]
    if exc.status == 429:
        wait = f", retry in {exc.retry_after}s" if exc.retry_after else ""
        return [Issue(Code.RATE_LIMITED, f"Unit.Travel rate limit exceeded{wait}")]
    if exc.status in (400, 422):
        return issues_from_errors([{"code": exc.code, "message": exc.message, "details": exc.details}])
    if exc.status in (401, 403):
        return [Issue(Code.UPSTREAM_ERROR, "The adapter is not authorised in Unit.Travel")]
    return [Issue(Code.UPSTREAM_ERROR, f"Unit.Travel is unavailable: {exc.message}")]


def issues_from_errors(errors: list[dict[str, Any]]) -> list[Issue]:
    """Map Unit.Travel validation errors onto Exely's error codes."""
    issues = []
    for error in errors:
        code = str(error.get("code") or "")
        message = str(error.get("message") or code or "error")
        details = error.get("details") or []
        if not details:
            issues.append(Issue(_classify(code), f"Unit.Travel: {message}"))
        for detail in details:
            path = str(detail.get("path") or "")
            reason = str(detail.get("reason") or message)
            issues.append(
                Issue(
                    _classify(f"{path} {code}"), f"Unit.Travel: {path}: {reason}" if path else f"Unit.Travel: {reason}"
                )
            )
    return issues


def _classify(text: str) -> Code:
    text = text.lower()
    if "connection" in text:
        return Code.HOTEL_NOT_CONNECTED
    if "currency" in text:
        return Code.INVALID_CURRENCY
    if "occupanc" in text or "pricing_model" in text:
        return Code.INVALID_ACCOMMODATION
    if "rate_plan" in text:
        return Code.INVALID_RATE_PLAN
    if "room" in text:
        return Code.INVALID_ROOM_TYPE
    return Code.INVALID_REQUEST


def _drop_not_permitted(parts: AriParts, scope: ConnectionScope) -> list[Issue]:
    warnings = []
    for kind in ARI_KINDS:
        if getattr(scope, kind) is False and getattr(parts, kind):
            setattr(parts, kind, [])
            warnings.append(
                Issue(
                    Code.NOT_PERMITTED, f"{kind} updates are not permitted for this connection in Unit.Travel; ignored"
                )
            )
    return warnings


def _property_id(hotel_id: str | None) -> int:
    if not hotel_id:
        raise ExelyFailure(Issue(Code.INVALID_REQUEST, "hotelId is required"))
    try:
        property_id = int(hotel_id.strip())
    except ValueError:
        property_id = 0
    if property_id <= 0:
        raise ExelyFailure(Issue(Code.HOTEL_NOT_FOUND, f"Hotel {hotel_id!r} does not exist in Unit.Travel"))
    return property_id


def _pick_connection(connections: list[Connection]) -> Connection | None:
    active = [c for c in connections if c.status == "active"]
    return (active or connections or [None])[-1]


def _not_active_message(property_id: int, connection: Connection | None) -> str:
    if connection is None:
        return f"Property {property_id} is not connected to Exely in Unit.Travel"
    if connection.status == "pending":
        return f"Connection of property {property_id} awaits the property's confirmation in Unit.Travel Extranet"
    if connection.status == "rejected":
        return f"The property rejected the connection of property {property_id} in Unit.Travel Extranet"
    return f"Connection of property {property_id} is {connection.status} in Unit.Travel"


def _ack_details(record: BookingRecord) -> dict[str, Any]:
    return {
        "external_reservation_code": record.exely_number,
        "status": record.exely_status,
        "result": "rejected" if record.rejected_by_exely else "accepted",
    }


def _to_exely(record: BookingRecord) -> dict[str, Any]:
    return to_exely_booking(Reservation.model_validate_json(record.payload), record.version)


def _describe(exc: ValidationError) -> str:
    problems = [f"{'.'.join(str(part) for part in error['loc'])}: {error['msg']}" for error in exc.errors()[:5]]
    return "Invalid request: " + "; ".join(problems)


class TtlCache:
    def __init__(self, ttl_seconds: float):
        self._ttl = ttl_seconds
        self._items: dict[Hashable, tuple[float, Any]] = {}

    def get(self, key: Hashable) -> Any:
        item = self._items.get(key)
        if item is None or item[0] < time.monotonic():
            return None
        return item[1]

    def put(self, key: Hashable, value: Any) -> None:
        self._items[key] = (time.monotonic() + self._ttl, value)

    def drop(self, key: Hashable) -> None:
        self._items.pop(key, None)


class RevisionClock:
    """Strictly increasing millisecond timestamps for ARI `revision` (last-write-wins)."""

    def __init__(self) -> None:
        self._last = 0
        self._lock = threading.Lock()

    def next(self) -> int:
        with self._lock:
            self._last = max(self._last + 1, time.time_ns() // 1_000_000)
            return self._last
