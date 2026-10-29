"""Exely `update` → Unit.Travel ARI message parts.

Exely omits a field it has already delivered, and Unit.Travel leaves a field
it does not receive unchanged, so only the fields present are passed on.
"""

from collections.abc import Callable, Iterable, Iterator
from dataclasses import dataclass, field
from typing import Any

from .catalog import parse_occupancy_code
from .protocol import Code, ExelyFailure, Issue, Update

ARI_KINDS = ("availability", "rates", "restrictions")

CurrencyLookup = Callable[[int], str]


@dataclass
class AriParts:
    availability: list[dict[str, Any]] = field(default_factory=list)
    rates: list[dict[str, Any]] = field(default_factory=list)
    restrictions: list[dict[str, Any]] = field(default_factory=list)
    warnings: list[Issue] = field(default_factory=list)

    def is_empty(self) -> bool:
        return not (self.availability or self.rates or self.restrictions)

    def chunks(self, max_items: int) -> Iterator[dict[str, list[dict[str, Any]]]]:
        """Split into message bodies of at most max_items items each."""
        items = [(kind, item) for kind in ARI_KINDS for item in getattr(self, kind)]
        for start in range(0, len(items), max_items):
            chunk: dict[str, list[dict[str, Any]]] = {}
            for kind, item in items[start : start + max_items]:
                chunk.setdefault(kind, []).append(item)
            yield chunk


def build_ari(updates: Iterable[Update], currency_for: CurrencyLookup) -> AriParts:
    """Translate Exely updates; currency_for supplies a rate plan's currency when Exely omits it."""
    parts = AriParts()
    for index, update in enumerate(updates):
        _translate(update, f"updates[{index}]", parts, currency_for)
    return parts


def _translate(update: Update, where: str, parts: AriParts, currency_for: CurrencyLookup) -> None:
    room_id = _parse_id(update.room_type_id, Code.INVALID_ROOM_TYPE, f"{where}: invalid roomTypeId")
    rate_plan_id = (
        _parse_id(update.rate_plan_id, Code.INVALID_RATE_PLAN, f"{where}: invalid ratePlanId")
        if update.rate_plan_id
        else None
    )
    period = {"date_from": update.start_date_ymd.isoformat(), "date_to": update.end_date_ymd.isoformat()}
    restriction = _restriction_fields(update, where, parts.warnings)

    if update.quota is not None:
        if update.quota < 0:
            raise ExelyFailure(Issue(Code.INVALID_REQUEST, f"{where}: quota must not be negative"))
        availability: dict[str, Any] = {"room_id": room_id, **period, "units": update.quota}
        if rate_plan_id is None and "stop_sell" in restriction:
            availability["closed"] = restriction.pop("stop_sell")
        parts.availability.append(availability)

    if update.prices:
        if rate_plan_id is None:
            raise ExelyFailure(Issue(Code.INVALID_RATE_PLAN, f"{where}: ratePlanId is required for prices"))
        parts.rates.append(_rate_item(update, room_id, rate_plan_id, period, where, currency_for))

    if restriction:
        if rate_plan_id is None:
            if set(restriction) == {"stop_sell"}:
                parts.warnings.append(
                    Issue(
                        Code.UNSUPPORTED_VALUE,
                        f"{where}: closing room type {room_id} needs a quota or a ratePlanId; ignored",
                    )
                )
                return
            raise ExelyFailure(Issue(Code.INVALID_RATE_PLAN, f"{where}: ratePlanId is required for restrictions"))
        # A Unit.Travel rate plan belongs to exactly one room, so room + rate maps to the rate plan alone.
        parts.restrictions.append({"rate_plan_id": rate_plan_id, **period, **restriction})


def _restriction_fields(update: Update, where: str, warnings: list[Issue]) -> dict[str, Any]:
    fields: dict[str, Any] = {}
    if update.closed is not None:
        fields["stop_sell"] = update.closed
    if update.closed_to_arrival is not None:
        fields["closed_to_arrival"] = update.closed_to_arrival
    if update.closed_to_departure is not None:
        fields["closed_to_departure"] = update.closed_to_departure
    if update.min_los is not None:
        # Both sides check MinLOS against every night of the stay. Exely removes
        # the restriction with 0, while Unit.Travel's min_stay starts at 1.
        fields["min_stay"] = max(update.min_los, 1)
    if update.max_los is not None:
        fields["max_stay"] = max(update.max_los, 0)  # 0 means "no limit" on both sides
    if update.minimum_advance_booking is not None:
        fields["min_advance_days"] = max(update.minimum_advance_booking, 0)
    if update.maximum_advance_booking is not None:
        if update.maximum_advance_booking < 0:
            fields["max_advance_days"] = 0  # Exely removes the limit with -1, Unit.Travel with 0
        elif update.maximum_advance_booking == 0:
            warnings.append(
                Issue(
                    Code.UNSUPPORTED_VALUE,
                    f"{where}: maximumAdvanceBooking=0 (same-day bookings only) is not supported; ignored",
                )
            )
        else:
            fields["max_advance_days"] = update.maximum_advance_booking
    for name, value in (
        ("minLosArrivalBased", update.min_los_arrival_based),
        ("maxLosArrivalBased", update.max_los_arrival_based),
    ):
        if value:  # 0 only removes a restriction Unit.Travel never had
            warnings.append(Issue(Code.UNSUPPORTED_VALUE, f"{where}: {name} is not supported by Unit.Travel; ignored"))
    return fields


def _rate_item(
    update: Update,
    room_id: int,
    rate_plan_id: int,
    period: dict[str, str],
    where: str,
    currency_for: CurrencyLookup,
) -> dict[str, Any]:
    room_price = None
    occupancy_prices = {}
    for entry in update.prices or []:
        if entry.price < 0:
            raise ExelyFailure(Issue(Code.INVALID_REQUEST, f"{where}: negative price for {entry.code}"))
        try:
            adults = parse_occupancy_code(entry.code, room_id)
        except ValueError as exc:
            raise ExelyFailure(Issue(Code.INVALID_ACCOMMODATION, f"{where}: {exc}")) from None
        if adults is None:
            room_price = entry.price
        else:
            occupancy_prices[adults] = entry.price
    if room_price is not None and occupancy_prices:
        raise ExelyFailure(
            Issue(Code.INVALID_ACCOMMODATION, f"{where}: per-room and per-guest prices mixed for room type {room_id}")
        )

    currency = (update.currency_code or "").strip().upper() or currency_for(rate_plan_id)
    item: dict[str, Any] = {"room_id": room_id, "rate_plan_id": rate_plan_id, **period, "currency": currency}
    if room_price is not None:
        item.update(pricing_model="per_room", price=float(room_price))
    else:
        item.update(
            pricing_model="per_guest",
            occupancy_prices=[{"occupancy": n, "price": float(p)} for n, p in sorted(occupancy_prices.items())],
        )
    return item


def _parse_id(value: str, code: Code, message: str) -> int:
    try:
        parsed = int(value.strip())
    except ValueError:
        parsed = 0
    if parsed <= 0:
        raise ExelyFailure(Issue(code, f"{message}: {value!r}"))
    return parsed
