import pytest

from exely_adapter.ari import AriParts, build_ari
from exely_adapter.protocol import Code, ExelyFailure, Update


def updates(*items):
    return [Update.model_validate(item) for item in items]


def no_currency(rate_plan_id):
    raise AssertionError(f"currency lookup for {rate_plan_id} not expected")


def test_quota_becomes_availability():
    parts = build_ari(
        updates({"startDateYmd": "2024-07-28", "endDateYmd": "2024-07-29", "roomTypeId": "4500", "quota": "0"}),
        no_currency,
    )

    assert parts.availability == [{"room_id": 4500, "date_from": "2024-07-28", "date_to": "2024-07-29", "units": 0}]
    assert not parts.rates and not parts.restrictions


def test_per_room_and_per_guest_prices():
    parts = build_ari(
        updates(
            {
                "startDateYmd": "2024-07-23",
                "endDateYmd": "2024-07-25",
                "roomTypeId": "4500",
                "ratePlanId": "13500",
                "currencyCode": "eur",
                "prices": [{"price": "15,500.00", "code": "4500_room"}],
            },
            {
                "startDateYmd": "2024-07-23",
                "endDateYmd": "2024-07-25",
                "roomTypeId": "4501",
                "ratePlanId": "13510",
                "currencyCode": "EUR",
                "prices": [{"price": "120.00", "code": "4501_2"}, {"price": "80", "code": "4501_1"}],
            },
        ),
        no_currency,
    )

    assert parts.rates == [
        {
            "room_id": 4500,
            "rate_plan_id": 13500,
            "date_from": "2024-07-23",
            "date_to": "2024-07-25",
            "currency": "EUR",
            "pricing_model": "per_room",
            "price": 15500.0,
        },
        {
            "room_id": 4501,
            "rate_plan_id": 13510,
            "date_from": "2024-07-23",
            "date_to": "2024-07-25",
            "currency": "EUR",
            "pricing_model": "per_guest",
            "occupancy_prices": [{"occupancy": 1, "price": 80.0}, {"occupancy": 2, "price": 120.0}],
        },
    ]


def test_missing_currency_is_looked_up():
    parts = build_ari(
        updates(
            {
                "startDateYmd": "2024-07-23",
                "endDateYmd": "2024-07-23",
                "roomTypeId": "4500",
                "ratePlanId": "13500",
                "prices": [{"price": "100", "code": "4500_room"}],
            }
        ),
        lambda rate_plan_id: {13500: "UAH"}[rate_plan_id],
    )

    assert parts.rates[0]["currency"] == "UAH"


def test_restrictions_follow_unit_travel_conventions():
    parts = build_ari(
        updates(
            {
                "startDateYmd": "2024-07-20",
                "endDateYmd": "2024-07-23",
                "roomTypeId": "4500",
                "ratePlanId": "13500",
                "closed": "false",
                "closedToArrival": True,
                "closedToDeparture": False,
                "minLos": 0,
                "maxLos": 7,
                "minimumAdvanceBooking": 2,
                "maximumAdvanceBooking": -1,
                "minLosArrivalBased": 0,
                "mealPlanType": "allInclusive",
            }
        ),
        no_currency,
    )

    assert parts.restrictions == [
        {
            "rate_plan_id": 13500,
            "date_from": "2024-07-20",
            "date_to": "2024-07-23",
            "stop_sell": False,
            "closed_to_arrival": True,
            "closed_to_departure": False,
            "min_stay": 1,
            "max_stay": 7,
            "min_advance_days": 2,
            "max_advance_days": 0,
        }
    ]
    assert parts.warnings == []


def test_only_fields_present_are_sent():
    parts = build_ari(
        updates(
            {
                "startDateYmd": "2024-07-20",
                "endDateYmd": "2024-07-20",
                "roomTypeId": "4500",
                "ratePlanId": "13500",
                "minLos": 3,
            }
        ),
        no_currency,
    )

    assert parts.restrictions == [
        {"rate_plan_id": 13500, "date_from": "2024-07-20", "date_to": "2024-07-20", "min_stay": 3}
    ]


def test_unsupported_restrictions_are_warned_about():
    parts = build_ari(
        updates(
            {
                "startDateYmd": "2024-07-20",
                "endDateYmd": "2024-07-20",
                "roomTypeId": "4500",
                "ratePlanId": "13500",
                "minLosArrivalBased": 3,
                "maximumAdvanceBooking": 0,
            }
        ),
        no_currency,
    )

    assert parts.is_empty()
    assert [w.code for w in parts.warnings] == [Code.UNSUPPORTED_VALUE, Code.UNSUPPORTED_VALUE]


def test_room_level_closed_with_quota():
    parts = build_ari(
        updates(
            {"startDateYmd": "2024-07-20", "endDateYmd": "2024-07-20", "roomTypeId": "4500", "quota": 3, "closed": True}
        ),
        no_currency,
    )

    assert parts.availability[0]["closed"] is True
    assert not parts.restrictions


@pytest.mark.parametrize(
    ("item", "code"),
    [
        ({"roomTypeId": "STD100", "quota": 1}, Code.INVALID_ROOM_TYPE),
        (
            {"roomTypeId": "4500", "prices": [{"price": "1", "code": "4500_room"}], "currencyCode": "EUR"},
            Code.INVALID_RATE_PLAN,
        ),
        ({"roomTypeId": "4500", "minLos": 2}, Code.INVALID_RATE_PLAN),
        (
            {
                "roomTypeId": "4500",
                "ratePlanId": "13500",
                "currencyCode": "EUR",
                "prices": [{"price": "1", "code": "4501_2"}],
            },
            Code.INVALID_ACCOMMODATION,
        ),
        (
            {
                "roomTypeId": "4501",
                "ratePlanId": "13510",
                "currencyCode": "EUR",
                "prices": [{"price": "1", "code": "4501_2"}, {"price": "1", "code": "4501_room"}],
            },
            Code.INVALID_ACCOMMODATION,
        ),
        ({"roomTypeId": "4500", "quota": -1}, Code.INVALID_REQUEST),
    ],
)
def test_invalid_updates_fail(item, code):
    with pytest.raises(ExelyFailure) as failure:
        build_ari(updates({"startDateYmd": "2024-07-20", "endDateYmd": "2024-07-20", **item}), no_currency)
    assert [e.code for e in failure.value.errors] == [code]


def test_chunks_respect_the_size_limit():
    parts = AriParts(availability=[{"a": 1}, {"a": 2}], rates=[{"r": 1}], restrictions=[{"s": 1}])

    chunks = list(parts.chunks(3))

    assert chunks == [{"availability": [{"a": 1}, {"a": 2}], "rates": [{"r": 1}]}, {"restrictions": [{"s": 1}]}]
