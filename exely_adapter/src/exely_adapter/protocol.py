"""Exely Channel Manager protocol: request models, error codes and response envelopes.

Exely posts {"username", "password", "action", "data"} to the channel and
expects {"success", "data", "errors", "warnings"} back. On "success": false it
shows the errors to the property and retries the request later.
"""

from collections.abc import Sequence
from dataclasses import dataclass
from datetime import date
from decimal import Decimal
from enum import IntEnum
from typing import Annotated, Any

from pydantic import AliasChoices, BaseModel, BeforeValidator, ConfigDict, Field, model_validator
from pydantic.alias_generators import to_camel

from .values import parse_bool, parse_decimal, parse_int


class Code(IntEnum):
    # Error codes defined by the Exely specification.
    HOTEL_NOT_CONNECTED = 375
    HOTEL_NOT_FOUND = 361
    INVALID_ROOM_TYPE = 730
    INVALID_RATE_PLAN = 436
    INVALID_ACCOMMODATION = 368
    INVALID_CURRENCY = 360
    # Adapter error codes (the specification lets a channel define its own).
    INVALID_CREDENTIALS = 901
    INVALID_REQUEST = 902
    UNKNOWN_ACTION = 903
    RATE_LIMITED = 904
    UPSTREAM_ERROR = 905
    INTERNAL_ERROR = 906
    # Adapter warning codes.
    UNSUPPORTED_VALUE = 951
    STILL_PROCESSING = 952
    ROOM_SKIPPED = 953
    UNKNOWN_BOOKING = 954
    NOT_PERMITTED = 955


@dataclass(frozen=True)
class Issue:
    code: int
    message: str

    def to_json(self) -> dict[str, Any]:
        return {"code": int(self.code), "message": self.message}


class ExelyFailure(Exception):
    """Fails the whole request: Exely shows the errors and sends it again later."""

    def __init__(self, *errors: Issue, warnings: Sequence[Issue] = ()):
        super().__init__("; ".join(error.message for error in errors))
        self.errors = list(errors)
        self.warnings = list(warnings)


def success(data: Any = None, warnings: Sequence[Issue] = ()) -> dict[str, Any]:
    body: dict[str, Any] = {"success": True, "errors": [], "warnings": [w.to_json() for w in warnings]}
    if data is not None:
        body["data"] = data
    return body


def failure(errors: Sequence[Issue], warnings: Sequence[Issue] = ()) -> dict[str, Any]:
    return {
        "success": False,
        "errors": [e.to_json() for e in errors],
        "warnings": [w.to_json() for w in warnings],
    }


def _blank_to_none(value: object) -> object:
    return None if isinstance(value, str) and not value.strip() else value


def _number_to_str(value: object) -> object:
    return str(value) if isinstance(value, int) and not isinstance(value, bool) else value


def _date_part(value: object) -> object:
    # Exely types dates as dateTime but sends YYYY-MM-DD; tolerate a time part.
    return value[:10] if isinstance(value, str) else value


LooseStr = Annotated[str, BeforeValidator(_number_to_str)]
LooseInt = Annotated[int, BeforeValidator(parse_int)]
LooseBool = Annotated[bool, BeforeValidator(parse_bool)]
Money = Annotated[Decimal, BeforeValidator(parse_decimal)]
LooseDate = Annotated[date, BeforeValidator(_date_part)]

OptionalStr = Annotated[LooseStr | None, BeforeValidator(_blank_to_none)]
OptionalInt = Annotated[LooseInt | None, BeforeValidator(_blank_to_none)]
OptionalBool = Annotated[LooseBool | None, BeforeValidator(_blank_to_none)]


class ExelyModel(BaseModel):
    model_config = ConfigDict(alias_generator=to_camel, extra="ignore")


class Envelope(ExelyModel):
    username: str = ""
    password: str = ""
    action: str
    data: Annotated[dict[str, Any], BeforeValidator(lambda v: v or {})] = Field(default_factory=dict)


class HotelData(ExelyModel):
    # The specification spells the field both "hotelId" and "hotelid".
    hotel_id: OptionalStr = Field(None, validation_alias=AliasChoices("hotelId", "hotelid", "HotelId"))


class Price(ExelyModel):
    code: LooseStr
    price: Money


class Update(ExelyModel):
    start_date_ymd: LooseDate
    end_date_ymd: LooseDate
    room_type_id: LooseStr
    rate_plan_id: OptionalStr = None
    quota: OptionalInt = None
    currency_code: OptionalStr = None
    prices: list[Price] | None = None
    closed: OptionalBool = None
    closed_to_arrival: OptionalBool = None
    closed_to_departure: OptionalBool = None
    min_los: OptionalInt = None
    max_los: OptionalInt = None
    min_los_arrival_based: OptionalInt = None
    max_los_arrival_based: OptionalInt = None
    minimum_advance_booking: OptionalInt = None
    maximum_advance_booking: OptionalInt = None
    meal_plan_type: OptionalStr = None

    @model_validator(mode="after")
    def _check_period(self) -> "Update":
        if self.end_date_ymd < self.start_date_ymd:
            raise ValueError("endDateYmd is before startDateYmd")
        return self


class UpdateData(HotelData):
    updates: list[Update]


class BookingConfirmation(ExelyModel):
    number: LooseStr
    # The specification spells the field both "echoToken" and "echotToken".
    echo_token: OptionalStr = Field(None, validation_alias=AliasChoices("echoToken", "echotToken"))
    external_number: OptionalStr = None
    status: OptionalStr = None


class ConfirmData(ExelyModel):
    confirm_bookings: list[BookingConfirmation] = Field(default_factory=list)
