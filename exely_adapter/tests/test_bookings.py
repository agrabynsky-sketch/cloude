from datetime import date

from exely_adapter.bookings import booking_version, strip_card, to_exely_booking
from exely_adapter.unit_travel import Reservation

from conftest import make_reservation


def booking(**overrides):
    raw = strip_card(make_reservation(checkin=date(2026, 7, 10), **overrides))
    return to_exely_booking(Reservation.model_validate(raw), booking_version(raw))


def test_maps_a_reservation():
    result = booking()

    assert result["number"] == "UT-9F3A21"
    assert result["status"] == "new"
    assert result["hotelId"] == "123"
    assert result["created"] == "2026-07-01 09:59:00"
    assert result["arrivalDate"] == "2026-07-10"
    assert result["departureDate"] == "2026-07-13"
    assert result["currencyCode"] == "EUR"
    assert result["paymentMethod"] == "CREDIT"
    assert result["paymentMethodComment"] == "Guarantee, VCC"
    assert "paymentInfo" not in result
    assert result["guestComment"] == "Late arrival ~23:00"
    assert result["customer"] == {
        "firstName": "Ivan",
        "lastName": "Petrenko",
        "email": "ivan@example.com",
        "phone": "+380671234567",
    }
    [stay] = result["roomStays"]
    assert stay["roomTypeId"] == "4500"
    assert stay["ratePlanId"] == "13500"
    assert stay["adults"] == 2
    assert stay["children"] == 1
    assert stay["guests"][2] == {"firstName": "Taras", "lastName": "Petrenko", "isChild": True, "age": 6}
    assert stay["bookingPerDayPrices"] == [
        {"dateYmd": "2026-07-10", "price": "225.00"},
        {"dateYmd": "2026-07-11", "price": "225.00"},
        {"dateYmd": "2026-07-12", "price": "225.00"},
    ]
    assert stay["total"] == {"amountAfterTaxes": "675.00"}
    assert stay["commission"] == "0.00"  # net rate
    info = result["additionalInfo"]
    assert "Guest nationality: UA" in info
    assert "Room 1 cancellation: Free cancellation" in info
    assert "card details are not transmitted" in info


def test_card_data_is_stripped():
    raw = strip_card(make_reservation())

    assert raw["payment"]["card"] == {"brand": "VISA"}


def test_nightly_prices_add_up_to_the_room_total():
    result = booking(rooms=[{**make_reservation()["rooms"][0], "price": 100}], total_price=100)

    prices = [night["price"] for night in result["roomStays"][0]["bookingPerDayPrices"]]
    assert prices == ["33.33", "33.33", "33.34"]


def test_gross_commission():
    percent = booking(rate_type="gross", commission="15%")
    assert percent["roomStays"][0]["commission"] == "101.25"

    amount = booking(rate_type="gross", commission="101.25 EUR")
    assert amount["roomStays"][0]["commission"] == "101.25"

    unparseable = booking(rate_type="gross", commission="per contract")
    assert "commission" not in unparseable["roomStays"][0]
    assert "commission per contract" in unparseable["additionalInfo"]


def test_prepaid_booking_reports_the_prepayment():
    result = booking(payment={"type": "prepaid", "method": "card online"})

    assert result["paymentMethod"] == "PREPAY"
    assert result["paymentInfo"] == {"prepayment": {"value": "675.00"}}


def test_multi_room_booking_splits_guests_and_missing_prices():
    room = make_reservation()["rooms"][0]
    result = booking(
        total_price=500,
        rooms=[
            {**room, "price": None, "occupancy": {"adults": 1}},
            {**room, "price": None, "occupancy": {"adults": 1}},
        ],
        guests=[
            {"first_name": "A", "last_name": "One", "room_index": 0},
            {"first_name": "B", "last_name": "Two", "room_index": 1},
        ],
    )

    first, second = result["roomStays"]
    assert first["guests"] == [{"firstName": "A", "lastName": "One", "isChild": False}]
    assert second["guests"] == [{"firstName": "B", "lastName": "Two", "isChild": False}]
    assert first["total"] == second["total"] == {"amountAfterTaxes": "250.00"}


def test_version_changes_with_the_reservation():
    original = make_reservation()
    assert booking_version(original) == booking_version(dict(original))
    assert booking_version(original) != booking_version({**original, "modified_at": "2026-07-02T10:00:00Z"})
    assert booking_version(original) != booking_version({**original, "status": "cancelled"})
