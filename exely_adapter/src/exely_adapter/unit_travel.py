"""Client for the Unit.Travel Partner Inventory API (openapi_3.json, v1.9.0).

The adapter is one provider in Unit.Travel: a single X-API-Key covers every
property connected through Exely.
"""

from collections.abc import Iterator
from datetime import date, datetime
from decimal import Decimal
from typing import Any
from urllib.parse import quote

import httpx
from pydantic import BaseModel, ConfigDict, Field, model_validator

from . import __version__


class UnitTravelError(Exception):
    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        details: list[dict[str, Any]] | None = None,
        retry_after: int | None = None,
    ):
        super().__init__(f"{status} {code}: {message}")
        self.status = status
        self.code = code
        self.message = message
        self.details = details or []
        self.retry_after = retry_after

    @classmethod
    def from_response(cls, response: httpx.Response) -> "UnitTravelError":
        try:
            body = response.json()
        except ValueError:
            body = None
        if not isinstance(body, dict):
            body = {}
        retry_after = response.headers.get("Retry-After", "")
        return cls(
            response.status_code,
            str(body.get("code") or f"http_{response.status_code}"),
            str(body.get("message") or response.reason_phrase),
            body.get("details") or [],
            int(retry_after) if retry_after.isdigit() else None,
        )


class UTModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    @model_validator(mode="before")
    @classmethod
    def _drop_nulls(cls, data: Any) -> Any:
        # Treat explicit nulls as absent so optional fields keep their defaults.
        if isinstance(data, dict):
            return {key: value for key, value in data.items() if value is not None}
        return data


class ConnectionScope(UTModel):
    rates: bool | None = None
    availability: bool | None = None
    restrictions: bool | None = None


class Connection(UTModel):
    connection_id: str | None = None
    property_id: int
    status: str
    scope: ConnectionScope = Field(default_factory=ConnectionScope)
    requested_at: datetime | None = None


class Room(UTModel):
    id: int
    code: str | None = None
    name: str | None = None
    base_occupancy: int | None = None
    pricing_model: str = "per_room"
    max_adults: int | None = None
    max_occupancy: int | None = None


class RatePlan(UTModel):
    id: int
    code: str | None = None
    name: str | None = None
    room_id: int | None = None
    currency: str | None = None


class AriStatus(UTModel):
    message_uid: str | None = None
    status: str
    errors: list[dict[str, Any]] = Field(default_factory=list)


class Occupancy(UTModel):
    adults: int | None = None
    children_ages: list[int] = Field(default_factory=list)
    infants: int | None = None


class ReservedRoom(UTModel):
    room_id: int | None = None
    room_code: str | None = None
    rate_plan_id: int | None = None
    rate_plan_code: str | None = None
    is_refundable: bool | None = None
    cancellation_policy: str | None = None
    occupancy: Occupancy | None = None
    price: Decimal | None = None


class Guest(UTModel):
    first_name: str | None = None
    last_name: str | None = None
    type: str | None = None
    room_index: int | None = None


class PrimaryGuest(UTModel):
    first_name: str | None = None
    last_name: str | None = None
    email: str | None = None
    phone: str | None = None
    nationality: str | None = None


class Card(UTModel):
    brand: str | None = None


class Payment(UTModel):
    type: str | None = None
    method: str | None = None
    card: Card | None = None


class Reservation(UTModel):
    reservation_id: str
    property_id: int
    status: str
    created_at: datetime | None = None
    modified_at: datetime | None = None
    checkin: date
    checkout: date
    currency: str
    total_price: Decimal | None = None
    rate_type: str | None = None
    commission: str | None = None
    channel: str | None = None
    special_request: str | None = None
    rooms: list[ReservedRoom] = Field(default_factory=list)
    primary_guest: PrimaryGuest | None = None
    guests: list[Guest] = Field(default_factory=list)
    payment: Payment | None = None


class UnitTravelClient:
    def __init__(
        self,
        base_url: str,
        api_key: str,
        *,
        timeout: float = 30.0,
        transport: httpx.BaseTransport | None = None,
    ):
        self._http = httpx.Client(
            base_url=base_url,
            headers={
                "X-API-Key": api_key,
                "Accept": "application/json",
                "User-Agent": f"exely-adapter/{__version__}",
            },
            timeout=timeout,
            transport=transport or httpx.HTTPTransport(retries=2),
        )

    def close(self) -> None:
        self._http.close()

    def create_connection(self, property_id: int, note: str | None = None) -> Connection:
        """Idempotent: returns the existing connection or files a new request the hotel confirms in Extranet."""
        body: dict[str, Any] = {"property_id": property_id}
        if note:
            body["note"] = note
        return Connection.model_validate(self._request("POST", "/connections", json=body).json())

    def list_connections(self, *, status: str | None = None, property_id: int | None = None) -> list[Connection]:
        params = {k: v for k, v in {"status": status, "property_id": property_id}.items() if v is not None}
        response = self._request("GET", "/connections", params=params)
        return [Connection.model_validate(item) for item in response.json()]

    def get_rooms(self, property_id: int) -> list[Room]:
        response = self._request("GET", f"/properties/{property_id}/rooms")
        return [Room.model_validate(item) for item in response.json()]

    def get_rate_plans(self, property_id: int) -> list[RatePlan]:
        response = self._request("GET", f"/properties/{property_id}/rate-plans")
        return [RatePlan.model_validate(item) for item in response.json()]

    def post_ari(self, message: dict[str, Any]) -> AriStatus:
        """Returns the acceptance receipt (202) or, for an already seen message_uid, its status (200)."""
        response = self._request("POST", "/ari", json=message, headers={"Idempotency-Key": message["message_uid"]})
        return AriStatus.model_validate(response.json())

    def get_ari_status(self, message_uid: str) -> AriStatus:
        response = self._request("GET", f"/ari/messages/{quote(message_uid, safe='')}")
        return AriStatus.model_validate(response.json())

    def iter_reservations(self, property_id: int, *, page_size: int = 100) -> Iterator[dict[str, Any]]:
        """Unacknowledged reservations of a property, as raw JSON objects."""
        params: dict[str, Any] = {"property_id": property_id, "limit": page_size}
        while True:
            page = self._request("GET", "/reservations", params=params).json()
            yield from page.get("items") or []
            cursor = page.get("next_cursor")
            if not cursor:
                return
            params["cursor"] = cursor

    def acknowledge(self, reservation_id: str, details: dict[str, Any] | None = None) -> None:
        self._request("POST", f"/reservations/{quote(reservation_id, safe='')}/acknowledge", json=details)

    def _request(self, method: str, path: str, **kwargs: Any) -> httpx.Response:
        try:
            response = self._http.request(method, path, **kwargs)
        except httpx.HTTPError as exc:
            raise UnitTravelError(0, "network_error", str(exc) or type(exc).__name__) from exc
        if response.status_code >= 400:
            raise UnitTravelError.from_response(response)
        return response
