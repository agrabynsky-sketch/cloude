import hashlib
import hmac
import json

from exely_adapter.protocol import Code

from conftest import WEBHOOK_SECRET, call_exely, error_codes, make_reservation, warning_codes


def push_webhook(client, event, reservation, *, secret=WEBHOOK_SECRET):
    body = json.dumps({"event": event, "occurred_at": "2026-07-01T10:00:00Z", "reservation": reservation}).encode()
    signature = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    return client.post("/webhooks/unit-travel", content=body, headers={"X-UnitTravel-Signature": signature})


def confirm(client, booking, external_number="20241111-111111-22548963"):
    item = {"number": booking["number"], "echoToken": booking["echoToken"], "status": booking["status"]}
    if external_number is not None:
        item["externalNumber"] = external_number
    return call_exely(client, "confirm-bookings", {"confirmBookings": [item]})


# Envelope


def test_rejects_wrong_credentials(client):
    response = call_exely(client, "get-bookings", password="wrong")

    assert response["success"] is False
    assert error_codes(response) == [Code.INVALID_CREDENTIALS]


def test_rejects_unknown_action(client):
    assert error_codes(call_exely(client, "get-prices")) == [Code.UNKNOWN_ACTION]


def test_answers_non_json_in_the_exely_envelope(client):
    response = client.post("/exely", content=b"not json")

    assert response.status_code == 200
    assert response.json()["success"] is False
    assert error_codes(response.json()) == [Code.INVALID_REQUEST]


# get-rooms-and-rate-plans


def test_get_rooms_requests_the_connection_until_the_hotel_confirms(client, fake):
    pending = call_exely(client, "get-rooms-and-rate-plans", {"hotelId": "123"})

    assert error_codes(pending) == [Code.HOTEL_NOT_CONNECTED]
    assert fake.connections[123]["status"] == "pending"

    fake.activate(123)
    response = call_exely(client, "get-rooms-and-rate-plans", {"hotelId": "123"})

    assert response["success"] is True
    assert response["data"]["hotelId"] == "123"
    assert [room["roomTypeId"] for room in response["data"]["roomsAndRatePlans"]] == ["4500", "4501"]
    assert warning_codes(response) == [Code.ROOM_SKIPPED]


def test_get_rooms_for_unknown_property(client):
    assert error_codes(call_exely(client, "get-rooms-and-rate-plans", {"hotelId": "999"})) == [Code.HOTEL_NOT_FOUND]
    assert error_codes(call_exely(client, "get-rooms-and-rate-plans", {"hotelid": "abc"})) == [Code.HOTEL_NOT_FOUND]


# update

UPDATE = {
    "hotelId": "123",
    "updates": [
        {"startDateYmd": "2026-07-23", "endDateYmd": "2026-07-23", "roomTypeId": "4500", "quota": "6"},
        {
            "startDateYmd": "2026-07-23",
            "endDateYmd": "2026-07-25",
            "roomTypeId": "4500",
            "ratePlanId": "13500",
            "currencyCode": "EUR",
            "prices": [{"price": "15,500.00", "code": "4500_room"}],
            "closed": "false",
            "minLos": 2,
        },
    ],
}


def test_update_is_sent_as_one_ari_message(client, fake):
    fake.activate(123)

    response = call_exely(client, "update", UPDATE)

    assert response == {"success": True, "errors": [], "warnings": []}
    [message] = fake.ari_messages
    assert message["property_id"] == 123
    assert message["message_uid"].startswith("exely-123-")
    assert isinstance(message["revision"], int)
    assert message["availability"] == [
        {"room_id": 4500, "date_from": "2026-07-23", "date_to": "2026-07-23", "units": 6}
    ]
    assert message["rates"][0]["price"] == 15500.0
    assert message["restrictions"] == [
        {"rate_plan_id": 13500, "date_from": "2026-07-23", "date_to": "2026-07-25", "stop_sell": False, "min_stay": 2}
    ]


def test_update_revisions_increase(client, fake):
    fake.activate(123)

    call_exely(client, "update", UPDATE)
    call_exely(client, "update", UPDATE)

    first, second = fake.ari_messages
    assert second["revision"] > first["revision"]
    assert second["message_uid"] != first["message_uid"]


def test_update_is_split_into_several_messages(make_client, fake):
    fake.activate(123)
    client = make_client(ari_max_items_per_message=1)

    assert call_exely(client, "update", UPDATE)["success"] is True
    assert len(fake.ari_messages) == 3


def test_update_reports_failed_processing(client, fake):
    fake.activate(123)
    fake.ari_outcome = {
        "status": "failed",
        "errors": [
            {
                "code": "unknown_code",
                "message": "unknown rate plan",
                "details": [{"path": "rates[0].rate_plan_id", "reason": "unknown"}],
            }
        ],
    }

    response = call_exely(client, "update", UPDATE)

    assert response["success"] is False
    assert error_codes(response) == [Code.INVALID_RATE_PLAN]


def test_update_maps_synchronous_validation_errors(client, fake):
    fake.activate(123)
    fake.ari_error = (
        422,
        {"code": "currency_mismatch", "message": "currency differs", "details": [{"path": "rates[0].currency"}]},
        {},
    )

    assert error_codes(call_exely(client, "update", UPDATE)) == [Code.INVALID_CURRENCY]


def test_update_passes_rate_limiting_on_to_exely(client, fake):
    fake.activate(123)
    fake.ari_error = (429, {"code": "rate_limited", "message": "slow down"}, {"Retry-After": "30"})

    response = call_exely(client, "update", UPDATE)

    assert error_codes(response) == [Code.RATE_LIMITED]
    assert "30s" in response["errors"][0]["message"]


def test_update_succeeds_with_a_warning_while_still_processing(client, fake):
    fake.activate(123)
    fake.ari_outcome = {"status": "processing"}

    response = call_exely(client, "update", UPDATE)

    assert response["success"] is True
    assert warning_codes(response) == [Code.STILL_PROCESSING]


def test_update_for_a_hotel_that_is_not_connected(client, fake):
    fake.connections[123] = {"property_id": 123, "status": "pending"}

    assert error_codes(call_exely(client, "update", UPDATE)) == [Code.HOTEL_NOT_CONNECTED]
    assert fake.ari_messages == []


def test_update_drops_parts_the_connection_may_not_send(client, fake):
    fake.activate(123, availability=False)

    response = call_exely(client, "update", UPDATE)

    assert warning_codes(response) == [Code.NOT_PERMITTED]
    assert "availability" not in fake.ari_messages[0]


def test_update_rejects_invalid_values(client, fake):
    fake.activate(123)
    data = {
        "hotelId": "123",
        "updates": [{"startDateYmd": "2026-07-23", "endDateYmd": "2026-07-22", "roomTypeId": "4500", "quota": 1}],
    }

    assert error_codes(call_exely(client, "update", data)) == [Code.INVALID_REQUEST]


# Bookings


def test_webhook_requires_a_valid_signature(client):
    response = push_webhook(client, "reservation.created", make_reservation(), secret="wrong")

    assert response.status_code == 401


def test_booking_flow_through_the_webhook(make_client, fake, notifier):
    client = make_client(ut_pull_reservations=False)

    assert push_webhook(client, "reservation.created", make_reservation()).status_code == 200
    assert push_webhook(client, "reservation.created", make_reservation()).status_code == 200
    assert notifier.calls == 1  # the repeated delivery is not a new version

    [booking] = call_exely(client, "get-bookings")["data"]["bookings"]
    assert booking["number"] == "UT-9F3A21"

    assert confirm(client, booking)["success"] is True
    assert fake.acks == [("UT-9F3A21", None)]
    assert call_exely(client, "get-bookings")["data"]["bookings"] == []


def test_modified_booking_is_queued_again(make_client):
    client = make_client(ut_pull_reservations=False)
    push_webhook(client, "reservation.created", make_reservation())
    [original] = call_exely(client, "get-bookings")["data"]["bookings"]
    confirm(client, original)

    push_webhook(
        client, "reservation.cancelled", make_reservation(status="cancelled", modified_at="2026-07-02T08:00:00Z")
    )
    push_webhook(
        client, "reservation.modified", make_reservation(status="modified", modified_at="2026-07-01T12:00:00Z")
    )

    [cancelled] = call_exely(client, "get-bookings")["data"]["bookings"]
    assert cancelled["status"] == "cancelled"  # the older, out-of-order modification was ignored
    assert cancelled["echoToken"] != original["echoToken"]


def test_confirmation_of_an_older_version_keeps_the_new_one_queued(make_client):
    client = make_client(ut_pull_reservations=False)
    push_webhook(client, "reservation.created", make_reservation())
    [original] = call_exely(client, "get-bookings")["data"]["bookings"]
    push_webhook(
        client, "reservation.modified", make_reservation(status="modified", modified_at="2026-07-02T08:00:00Z")
    )

    confirm(client, original)

    [queued] = call_exely(client, "get-bookings")["data"]["bookings"]
    assert queued["status"] == "modified"


def test_bookings_for_a_hotel_include_confirmed_ones(make_client):
    client = make_client(ut_pull_reservations=False)
    push_webhook(client, "reservation.created", make_reservation())
    [booking] = call_exely(client, "get-bookings")["data"]["bookings"]
    confirm(client, booking)

    assert [b["number"] for b in call_exely(client, "get-bookings", {"hotelId": "123"})["data"]["bookings"]] == [
        "UT-9F3A21"
    ]
    assert call_exely(client, "get-bookings", {"hotelId": "456"})["data"]["bookings"] == []


def test_bookings_are_pulled_from_unit_travel(client, fake):
    fake.activate(123)
    fake.reservations[123] = [make_reservation()]

    [booking] = call_exely(client, "get-bookings")["data"]["bookings"]
    assert booking["number"] == "UT-9F3A21"

    confirm(client, booking)
    assert [rid for rid, _ in fake.acks] == ["UT-9F3A21"]
    assert call_exely(client, "get-bookings")["data"]["bookings"] == []


def test_card_data_is_not_stored(client, fake, tmp_path):
    fake.activate(123)
    fake.reservations[123] = [make_reservation()]

    call_exely(client, "get-bookings")

    assert b"4111111111111111" not in (tmp_path / "adapter.db").read_bytes()


def test_acknowledge_carries_exely_details_when_enabled(make_client, fake):
    client = make_client(ut_pull_reservations=False, ut_ack_with_details=True)
    push_webhook(client, "reservation.created", make_reservation())
    [booking] = call_exely(client, "get-bookings")["data"]["bookings"]

    confirm(client, booking)

    assert fake.acks == [
        ("UT-9F3A21", {"external_reservation_code": "20241111-111111-22548963", "status": "new", "result": "accepted"})
    ]


def test_booking_exely_did_not_create_is_reported_as_rejected(make_client, fake):
    client = make_client(ut_pull_reservations=False, ut_ack_with_details=True)
    push_webhook(client, "reservation.created", make_reservation())
    [booking] = call_exely(client, "get-bookings")["data"]["bookings"]

    confirm(client, booking, external_number=None)

    assert fake.acks == [("UT-9F3A21", {"external_reservation_code": None, "status": "new", "result": "rejected"})]
    assert call_exely(client, "get-bookings")["data"]["bookings"] == []


def test_confirmation_of_an_unknown_booking_is_a_warning(client):
    response = call_exely(
        client, "confirm-bookings", {"confirmBookings": [{"number": "nope", "externalNumber": "1", "status": "new"}]}
    )

    assert response["success"] is True
    assert warning_codes(response) == [Code.UNKNOWN_BOOKING]
