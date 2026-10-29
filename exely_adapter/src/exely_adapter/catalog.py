"""Unit.Travel rooms and rate plans → Exely get-rooms-and-rate-plans.

Exely is handed Unit.Travel's own ids (roomTypeId = room_id, ratePlanId =
rate_plan_id), so no code mapping through /mappings is needed: the property
maps them to its Exely rooms in the Exely account.

Accommodation codes carry the pricing model, so `update` can be translated
without looking the room up again:
  "<room_id>_<n>"   per_guest room, the price for n adults
  "<room_id>_room"  per_room room, one price for the room
Child and extra-bed accommodations are not offered: Unit.Travel ARI takes no
child prices.
"""

from collections import defaultdict
from typing import Any

from .protocol import Code, Issue
from .unit_travel import ConnectionScope, RatePlan, Room

PER_ROOM_SUFFIX = "room"


def occupancy_code(room_id: int, adults: int | None) -> str:
    return f"{room_id}_{adults if adults else PER_ROOM_SUFFIX}"


def parse_occupancy_code(code: str, room_id: int) -> int | None:
    """Return the number of adults a code prices, or None for a per-room price.

    Raises ValueError for a code of another room or of an unknown format.
    """
    prefix, separator, suffix = code.strip().rpartition("_")
    if not separator or prefix != str(room_id):
        raise ValueError(f"accommodation code {code!r} does not belong to room type {room_id}")
    if suffix == PER_ROOM_SUFFIX:
        return None
    if suffix.isdigit() and int(suffix) > 0:
        return int(suffix)
    raise ValueError(f"unknown accommodation code {code!r}")


def build_catalog(
    rooms: list[Room], rate_plans: list[RatePlan], scope: ConnectionScope
) -> tuple[list[dict[str, Any]], list[Issue]]:
    plans_by_room: dict[int, list[RatePlan]] = defaultdict(list)
    for plan in rate_plans:
        if plan.room_id is not None:
            plans_by_room[plan.room_id].append(plan)

    catalog: list[dict[str, Any]] = []
    warnings: list[Issue] = []
    for room in rooms:
        plans = plans_by_room.get(room.id)
        if not plans:
            # Exely requires at least one rate plan per room type.
            warnings.append(
                Issue(Code.ROOM_SKIPPED, f"Room type {room.id} ({room.name}) has no rate plans; not offered")
            )
            continue
        catalog.append(
            {
                "roomTypeId": str(room.id),
                "roomName": room.name or room.code or str(room.id),
                "ratePlans": [_rate_plan(plan, scope) for plan in plans],
                "occupancies": _occupancies(room),
            }
        )
    return catalog, warnings


def _rate_plan(plan: RatePlan, scope: ConnectionScope) -> dict[str, Any]:
    entry: dict[str, Any] = {"ratePlanId": str(plan.id), "ratePlanName": plan.name or plan.code or str(plan.id)}
    unsupported = {}
    if scope.rates is False:
        unsupported["prices"] = True
    if scope.restrictions is False:
        unsupported["restrictions"] = True
    if unsupported:
        entry["nonSupportedUpdates"] = unsupported
    return entry


def _occupancies(room: Room) -> list[dict[str, Any]]:
    if room.pricing_model == "per_guest":
        most = room.max_adults or room.max_occupancy or room.base_occupancy or 1
        return [_adult_bed(occupancy_code(room.id, n), n) for n in range(1, most + 1)]
    return [_adult_bed(occupancy_code(room.id, None), room.base_occupancy or room.max_adults or 1)]


def _adult_bed(code: str, adults: int) -> dict[str, Any]:
    return {"code": code, "bedType": "adultBed", "personQuantity": adults}
