import json
import re
from copy import deepcopy
from datetime import date, timedelta
from typing import Any

import httpx
import pytest
from fastapi.testclient import TestClient

from exely_adapter.app import create_app
from exely_adapter.config import Settings
from exely_adapter.store import BookingStore
from exely_adapter.unit_travel import UnitTravelClient

UT_BASE_URL = "https://ut.test/partner/v1"
UT_API_KEY = "ut-key"
WEBHOOK_SECRET = "whsec"

ROOMS = [
    {
        "id": 4500,
        "code": "DLX",
        "name": "Deluxe Room",
        "base_occupancy": 2,
        "pricing_model": "per_room",
        "max_adults": 3,
    },
    {
        "id": 4501,
        "code": "STD",
        "name": "Standard Room",
        "base_occupancy": 2,
        "pricing_model": "per_guest",
        "max_adults": 3,
    },
    {"id": 4502, "code": "NEW", "name": "Room without rates", "pricing_model": "per_room"},
]
RATE_PLANS = [
    {"id": 13500, "code": "DLX-BB", "name": "Deluxe BB Flexible", "room_id": 4500, "board": "BB", "currency": "EUR"},
    {"id": 13510, "code": "STD-RO", "name": "Standard RO", "room_id": 4501, "board": "RO", "currency": "EUR"},
]


class FakeUnitTravel:
    """In-memory Partner API following openapi_3.json closely enough for the adapter."""

    def __init__(self) -> None:
        self.known_properties = {123, 456}
        self.connections: dict[int, dict[str, Any]] = {}
        self.rooms = {123: deepcopy(ROOMS)}
        self.rate_plans = {123: deepcopy(RATE_PLANS)}
        self.ari_messages: list[dict[str, Any]] = []
        self.ari_outcome: dict[str, Any] = {"status": "applied", "errors": []}
        self.ari_error: tuple[int, dict[str, Any], dict[str, str]] | None = None
        self.reservations: dict[int, list[dict[str, Any]]] = {}
        self.acks: list[tuple[str, Any]] = []
        self.calls: list[str] = []

    def activate(self, property_id: int, **scope: bool) -> None:
        self.connections[property_id] = {
            "connection_id": f"conn_{property_id}",
            "property_id": property_id,
            "status": "active",
            "scope": {"rates": True, "availability": True, "restrictions": True, **scope},
        }

    def __call__(self, request: httpx.Request) -> httpx.Response:
        if request.headers.get("X-API-Key") != UT_API_KEY:
            return _error(401, "unauthorized", "invalid API key")
        path = request.url.path.removeprefix("/partner/v1")
        body = json.loads(request.content) if request.content else None
        params = request.url.params
        self.calls.append(f"{request.method} {path}")

        if path == "/connections" and request.method == "POST":
            property_id = body["property_id"]
            if property_id not in self.known_properties:
                return _error(404, "not_found", f"property {property_id} not found")
            if property_id in self.connections:
                return httpx.Response(200, json=self.connections[property_id])
            self.connections[property_id] = {
                "connection_id": f"conn_{property_id}",
                "property_id": property_id,
                "status": "pending",
            }
            return httpx.Response(201, json=self.connections[property_id])
        if path == "/connections" and request.method == "GET":
            found = [
                c
                for c in self.connections.values()
                if ("status" not in params or c["status"] == params["status"])
                and ("property_id" not in params or c["property_id"] == int(params["property_id"]))
            ]
            return httpx.Response(200, json=found)

        if match := re.fullmatch(r"/properties/(\d+)/(rooms|rate-plans)", path):
            property_id = int(match.group(1))
            if self.connections.get(property_id, {}).get("status") != "active":
                return _error(409, "connection_not_active", f"connection for property {property_id} is not active")
            catalog = self.rooms if match.group(2) == "rooms" else self.rate_plans
            return httpx.Response(200, json=catalog.get(property_id, []))

        if path == "/ari" and request.method == "POST":
            assert request.headers["Idempotency-Key"] == body["message_uid"]
            if self.ari_error:
                status, error, headers = self.ari_error
                return httpx.Response(status, json=error, headers=headers)
            self.ari_messages.append(body)
            return httpx.Response(202, json={"message_uid": body["message_uid"], "status": "received"})
        if match := re.fullmatch(r"/ari/messages/(.+)", path):
            return httpx.Response(200, json={"message_uid": match.group(1), **self.ari_outcome})

        if path == "/reservations":
            acked = {reservation_id for reservation_id, _ in self.acks}
            items = [
                r for r in self.reservations.get(int(params["property_id"]), []) if r["reservation_id"] not in acked
            ]
            return httpx.Response(200, json={"items": items, "next_cursor": None})
        if match := re.fullmatch(r"/reservations/(.+)/acknowledge", path):
            self.acks.append((match.group(1), body))
            return httpx.Response(200, json={"status": "ok"})

        return _error(404, "not_found", path)


def _error(status: int, code: str, message: str) -> httpx.Response:
    return httpx.Response(status, json={"code": code, "message": message})


class FakeNotifier:
    def __init__(self) -> None:
        self.calls = 0

    def notify(self) -> None:
        self.calls += 1

    def close(self) -> None:
        pass


def make_reservation(
    reservation_id: str = "UT-9F3A21",
    *,
    status: str = "new",
    modified_at: str = "2026-07-01T10:00:00Z",
    checkin: date | None = None,
    nights: int = 3,
    **overrides: Any,
) -> dict[str, Any]:
    checkin = checkin or date.today() + timedelta(days=10)
    reservation = {
        "reservation_id": reservation_id,
        "property_id": 123,
        "status": status,
        "created_at": "2026-07-01T09:59:00Z",
        "modified_at": modified_at,
        "checkin": checkin.isoformat(),
        "checkout": (checkin + timedelta(days=nights)).isoformat(),
        "currency": "EUR",
        "total_price": 675,
        "rate_type": "net",
        "channel": "web",
        "special_request": "Late arrival ~23:00",
        "rooms": [
            {
                "room_code": "DLX",
                "room_id": 4500,
                "rate_plan_code": "DLX-BB",
                "rate_plan_id": 13500,
                "board": "BB",
                "is_refundable": True,
                "cancellation_policy": "Free cancellation until 18:00 the day before arrival",
                "occupancy": {"adults": 2, "children_ages": [6], "infants": 0},
                "price": 675,
            }
        ],
        "primary_guest": {
            "first_name": "Ivan",
            "last_name": "Petrenko",
            "email": "ivan@example.com",
            "phone": "+380671234567",
            "nationality": "UA",
        },
        "guests": [
            {"first_name": "Ivan", "last_name": "Petrenko", "type": "adult", "room_index": 0},
            {"first_name": "Olena", "last_name": "Petrenko", "type": "adult", "room_index": 0},
            {"first_name": "Taras", "last_name": "Petrenko", "type": "child", "room_index": 0},
        ],
        "payment": {
            "type": "guarantee",
            "method": "VCC",
            "card": {"holder": "IVAN PETRENKO", "number": "4111111111111111", "cvc": "123", "brand": "VISA"},
        },
    }
    reservation.update(overrides)
    return reservation


def make_settings(tmp_path: Any, **overrides: Any) -> Settings:
    values: dict[str, Any] = {
        "exely_username": "exely",
        "exely_password": "secret",
        "ut_base_url": UT_BASE_URL,
        "ut_api_key": UT_API_KEY,
        "ut_webhook_secret": WEBHOOK_SECRET,
        "database_url": f"sqlite:///{tmp_path / 'adapter.db'}",
        "ari_status_wait_seconds": 0.2,
        "ari_status_poll_interval": 0.01,
        **overrides,
    }
    return Settings(_env_file=None, **values)


@pytest.fixture
def fake() -> FakeUnitTravel:
    return FakeUnitTravel()


@pytest.fixture
def notifier() -> FakeNotifier:
    return FakeNotifier()


@pytest.fixture
def make_client(tmp_path, fake, notifier):
    clients = []

    def factory(**settings_overrides: Any) -> TestClient:
        settings = make_settings(tmp_path, **settings_overrides)
        unit_travel = UnitTravelClient(settings.ut_base_url, settings.ut_api_key, transport=httpx.MockTransport(fake))
        app = create_app(
            settings, unit_travel=unit_travel, store=BookingStore(settings.database_url), notifier=notifier
        )
        client = TestClient(app)
        client.__enter__()
        clients.append(client)
        return client

    yield factory
    for client in clients:
        client.__exit__(None, None, None)


@pytest.fixture
def client(make_client) -> TestClient:
    return make_client()


def call_exely(client: TestClient, action: str, data: dict[str, Any] | None = None, **envelope: Any) -> dict[str, Any]:
    body = {"username": "exely", "password": "secret", "action": action, "data": data or {}, **envelope}
    response = client.post("/exely", json=body)
    assert response.status_code == 200
    return response.json()


def error_codes(response: dict[str, Any]) -> list[int]:
    return [error["code"] for error in response["errors"]]


def warning_codes(response: dict[str, Any]) -> list[int]:
    return [warning["code"] for warning in response["warnings"]]
