import pytest

from exely_adapter.catalog import build_catalog, occupancy_code, parse_occupancy_code
from exely_adapter.protocol import Code
from exely_adapter.unit_travel import ConnectionScope, RatePlan, Room

from conftest import RATE_PLANS, ROOMS


def test_occupancy_codes_round_trip():
    assert parse_occupancy_code(occupancy_code(4501, 2), 4501) == 2
    assert parse_occupancy_code(occupancy_code(4500, None), 4500) is None


@pytest.mark.parametrize("code", ["4502_2", "4501_0", "4501_x", "4501", "STD100_1"])
def test_parse_occupancy_code_rejects_foreign_codes(code):
    with pytest.raises(ValueError):
        parse_occupancy_code(code, 4501)


def test_build_catalog():
    rooms = [Room.model_validate(r) for r in ROOMS]
    plans = [RatePlan.model_validate(p) for p in RATE_PLANS]

    catalog, warnings = build_catalog(rooms, plans, ConnectionScope(rates=True, availability=True, restrictions=True))

    assert catalog == [
        {
            "roomTypeId": "4500",
            "roomName": "Deluxe Room",
            "ratePlans": [{"ratePlanId": "13500", "ratePlanName": "Deluxe BB Flexible"}],
            "occupancies": [{"code": "4500_room", "bedType": "adultBed", "personQuantity": 2}],
        },
        {
            "roomTypeId": "4501",
            "roomName": "Standard Room",
            "ratePlans": [{"ratePlanId": "13510", "ratePlanName": "Standard RO"}],
            "occupancies": [
                {"code": "4501_1", "bedType": "adultBed", "personQuantity": 1},
                {"code": "4501_2", "bedType": "adultBed", "personQuantity": 2},
                {"code": "4501_3", "bedType": "adultBed", "personQuantity": 3},
            ],
        },
    ]
    assert [w.code for w in warnings] == [Code.ROOM_SKIPPED]


def test_build_catalog_flags_updates_the_connection_may_not_send():
    rooms = [Room.model_validate(ROOMS[0])]
    plans = [RatePlan.model_validate(RATE_PLANS[0])]

    catalog, _ = build_catalog(rooms, plans, ConnectionScope(rates=False, restrictions=True))

    assert catalog[0]["ratePlans"][0]["nonSupportedUpdates"] == {"prices": True}
