"""Unit.Travel reservations → Exely get-bookings entries."""

import re
import uuid
from datetime import UTC, datetime, timedelta
from decimal import ROUND_HALF_UP, Decimal
from typing import Any

from .unit_travel import Occupancy, Payment, PrimaryGuest, Reservation, ReservedRoom
from .values import CENT, allocate_amount, format_money, parse_decimal, split_amount

_VERSION_NAMESPACE = uuid.UUID("6d54ebb4-fcb1-4ff6-9ced-b4dc46858db7")

PAYMENT_METHODS = {"prepaid": "PREPAY", "deposit": "PREPAY", "guarantee": "CREDIT", "pay_at_hotel": "CASH"}
PAYMENT_LABELS = {"prepaid": "Prepaid", "deposit": "Deposit", "guarantee": "Guarantee", "pay_at_hotel": "Pay at hotel"}
MAX_PAYMENT_COMMENT = 70

_PERCENT = re.compile(r"^\s*(\d+(?:[.,]\d+)?)\s*%\s*$")
_AMOUNT = re.compile(r"^\s*([\d\s.,]+?)\s*([A-Za-z]{3})?\s*$")


def booking_version(reservation: dict[str, Any]) -> str:
    """The echoToken: changes whenever Unit.Travel changes the reservation."""
    stamp = reservation.get("modified_at") or reservation.get("created_at") or ""
    key = f"{reservation['reservation_id']}|{reservation.get('status')}|{stamp}"
    return str(uuid.uuid5(_VERSION_NAMESPACE, key))


def strip_card(reservation: dict[str, Any]) -> dict[str, Any]:
    """Drop card data except the brand: Exely has no card fields, so the adapter never needs it."""
    payment = reservation.get("payment")
    if not isinstance(payment, dict) or not payment.get("card"):
        return reservation
    card = payment["card"]
    brand = card.get("brand") if isinstance(card, dict) else None
    return {**reservation, "payment": {**payment, "card": {"brand": brand}}}


def to_exely_booking(res: Reservation, version: str) -> dict[str, Any]:
    nights = max((res.checkout - res.checkin).days, 1)
    totals = _room_totals(res)
    commissions = _commissions(res, totals)
    payment_type = res.payment.type if res.payment else None

    booking: dict[str, Any] = {
        "number": res.reservation_id,
        "echoToken": version,
        "status": res.status,
        "hotelId": str(res.property_id),
        "arrivalDate": res.checkin.isoformat(),
        "departureDate": res.checkout.isoformat(),
        "currencyCode": res.currency,
        "paymentMethod": PAYMENT_METHODS.get(payment_type or "", "CASH"),
        "paymentMethodComment": _payment_comment(res.payment),
        "roomStays": [
            _room_stay(res, index, room, totals[index], commissions[index], nights)
            for index, room in enumerate(res.rooms)
        ],
        "customer": _customer(res),
        "additionalInfo": _additional_info(res),
    }
    created = res.created_at or res.modified_at
    if created:
        booking["created"] = _utc(created).strftime("%Y-%m-%d %H:%M:%S")
    if res.special_request:
        booking["guestComment"] = res.special_request
    if payment_type == "prepaid" and res.total_price is not None:
        booking["paymentInfo"] = {"prepayment": {"value": format_money(res.total_price)}}
    return booking


def _room_totals(res: Reservation) -> list[Decimal]:
    prices = [room.price for room in res.rooms]
    if all(price is not None for price in prices) or res.total_price is None or not prices:
        return [price or Decimal(0) for price in prices]
    return split_amount(res.total_price, len(prices))


def _commissions(res: Reservation, totals: list[Decimal]) -> list[Decimal | None]:
    """The fee the property owes the channel, per room; None when it cannot be determined."""
    unknown: list[Decimal | None] = [None] * len(totals)
    if res.rate_type == "net":
        return [Decimal(0)] * len(totals)
    if res.rate_type != "gross" or not res.commission:
        return unknown
    if match := _PERCENT.match(res.commission):
        rate = parse_decimal(match.group(1)) / 100
        return [(total * rate).quantize(CENT, rounding=ROUND_HALF_UP) for total in totals]
    match = _AMOUNT.match(res.commission)
    if not match or (match.group(2) and match.group(2).upper() != res.currency.upper()):
        return unknown
    try:
        amount = parse_decimal(match.group(1))
    except ValueError:
        return unknown
    return list(allocate_amount(amount, totals))


def _room_stay(
    res: Reservation, index: int, room: ReservedRoom, total: Decimal, commission: Decimal | None, nights: int
) -> dict[str, Any]:
    occupancy = room.occupancy or Occupancy()
    guests = _stay_guests(res, index, occupancy)
    adults = occupancy.adults
    if adults is None:
        adults = max(sum(1 for guest in guests if not guest["isChild"]), 1)
    stay: dict[str, Any] = {
        "roomTypeId": _reference(room.room_id, room.room_code),
        "ratePlanId": _reference(room.rate_plan_id, room.rate_plan_code),
        "guests": guests,
        "adults": adults,
        "children": len(occupancy.children_ages) + (occupancy.infants or 0),
        # Unit.Travel has no nightly prices; Exely asks to split the total evenly then.
        "bookingPerDayPrices": [
            {"dateYmd": (res.checkin + timedelta(days=night)).isoformat(), "price": format_money(price)}
            for night, price in enumerate(split_amount(total, nights))
        ],
        "total": {"amountAfterTaxes": format_money(total)},
    }
    if commission is not None:
        stay["commission"] = format_money(commission)
    return stay


def _stay_guests(res: Reservation, index: int, occupancy: Occupancy) -> list[dict[str, Any]]:
    ages = iter(occupancy.children_ages)
    guests = []
    for guest in res.guests:
        if (guest.room_index or 0) != index:
            continue
        is_child = guest.type == "child"
        entry: dict[str, Any] = {
            "firstName": guest.first_name or "",
            "lastName": guest.last_name or "",
            "isChild": is_child,
        }
        if is_child and (age := next(ages, None)) is not None:
            entry["age"] = age
        guests.append(entry)
    if not guests and res.primary_guest:
        guests.append(
            {
                "firstName": res.primary_guest.first_name or "",
                "lastName": res.primary_guest.last_name or "",
                "isChild": False,
            }
        )
    return guests


def _customer(res: Reservation) -> dict[str, Any]:
    person = res.primary_guest
    if person is None:
        first = res.guests[0] if res.guests else None
        person = PrimaryGuest(
            first_name=first.first_name if first else None, last_name=first.last_name if first else None
        )
    customer = {"firstName": person.first_name or "", "lastName": person.last_name or ""}
    if person.email:
        customer["email"] = person.email
    if person.phone:
        customer["phone"] = person.phone
    return customer


def _payment_comment(payment: Payment | None) -> str:
    if payment is None or not payment.type:
        return PAYMENT_LABELS["pay_at_hotel"]
    parts = [PAYMENT_LABELS.get(payment.type, payment.type), payment.method]
    return ", ".join(part for part in parts if part)[:MAX_PAYMENT_COMMENT]


def _additional_info(res: Reservation) -> str:
    """What Exely has no field for, shown at the bottom of the property's confirmation."""
    lines = [f"Unit.Travel reservation {res.reservation_id}" + (f" ({res.channel})" if res.channel else "")]
    if res.rate_type:
        commission = f", commission {res.commission}" if res.rate_type == "gross" and res.commission else ""
        lines.append(f"Rate type: {res.rate_type}{commission}")
    for number, room in enumerate(res.rooms, start=1):
        terms = room.cancellation_policy or ("non-refundable" if room.is_refundable is False else None)
        if terms:
            lines.append(f"Room {number} cancellation: {terms}")
    if res.primary_guest and res.primary_guest.nationality:
        lines.append(f"Guest nationality: {res.primary_guest.nationality}")
    if res.payment and res.payment.type == "deposit":
        lines.append("Deposit: the amount is not transmitted via Exely, see Unit.Travel")
    if res.payment and res.payment.card is not None:
        brand = res.payment.card.brand or "card"
        lines.append(f"Payment card ({brand}) provided: card details are not transmitted via Exely, see Unit.Travel")
    return "\n".join(lines)


def _reference(item_id: int | None, code: str | None) -> str:
    return str(item_id) if item_id is not None else code or ""


def _utc(moment: datetime) -> datetime:
    return moment.replace(tzinfo=UTC) if moment.tzinfo is None else moment.astimezone(UTC)
