"""Reservations received from Unit.Travel and their delivery state in Exely.

Exely expects a queue on the channel side: get-bookings without hotelId
returns every unconfirmed booking of every property, and get-bookings with a
hotelId returns all relevant bookings, confirmed ones included. Unit.Travel's
/reservations works per property and drops acknowledged reservations, so the
adapter keeps its own copy. Card data is stripped before a reservation is stored.
"""

import json
from datetime import UTC, date, datetime, timedelta
from enum import Enum
from typing import Any

from sqlalchemy import BigInteger, Date, String, Text, create_engine, or_, select, update
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, sessionmaker

from .bookings import booking_version, strip_card
from .unit_travel import Reservation


class Base(DeclarativeBase):
    pass


class BookingRecord(Base):
    __tablename__ = "bookings"

    reservation_id: Mapped[str] = mapped_column(String(120), primary_key=True)
    property_id: Mapped[int] = mapped_column(BigInteger, index=True)
    status: Mapped[str] = mapped_column(String(16))
    # echoToken of the current version, handed to Exely.
    version: Mapped[str] = mapped_column(String(36))
    modified_at: Mapped[datetime | None]
    checkout: Mapped[date] = mapped_column(Date, index=True)
    payload: Mapped[str] = mapped_column(Text)
    received_at: Mapped[datetime]
    # Version Exely last confirmed, and what it answered.
    confirmed_version: Mapped[str | None] = mapped_column(String(36))
    exely_number: Mapped[str | None] = mapped_column(String(120))
    exely_status: Mapped[str | None] = mapped_column(String(16))
    confirmed_at: Mapped[datetime | None]
    rejected_by_exely: Mapped[bool] = mapped_column(default=False)
    # Version last acknowledged to Unit.Travel.
    acked_version: Mapped[str | None] = mapped_column(String(36))


class ConfirmOutcome(Enum):
    CONFIRMED = "confirmed"
    # Exely confirmed delivery without its own booking number: it did not create the booking.
    REJECTED = "rejected"
    # Exely confirmed an older version; the current one stays queued.
    STALE = "stale"
    UNKNOWN = "unknown"


class BookingStore:
    def __init__(self, database_url: str):
        connect_args = {"check_same_thread": False} if database_url.startswith("sqlite") else {}
        self._engine = create_engine(database_url, connect_args=connect_args)
        Base.metadata.create_all(self._engine)
        self._sessions = sessionmaker(self._engine, expire_on_commit=False)

    def upsert(self, reservation: dict[str, Any]) -> bool:
        """Store a reservation; True when it is new or a newer version of a stored one."""
        reservation = strip_card(reservation)
        parsed = Reservation.model_validate(reservation)
        version = booking_version(reservation)
        modified_at = _utc_naive(parsed.modified_at or parsed.created_at)
        values = {
            "property_id": parsed.property_id,
            "status": parsed.status,
            "version": version,
            "modified_at": modified_at,
            "checkout": parsed.checkout,
            "payload": json.dumps(reservation, ensure_ascii=False),
            "received_at": _now(),
        }
        # A concurrent insert of the same reservation fails the first attempt; the retry updates it.
        for attempt in range(2):
            try:
                with self._sessions.begin() as session:
                    record = session.get(BookingRecord, parsed.reservation_id)
                    if record is None:
                        session.add(BookingRecord(reservation_id=parsed.reservation_id, **values))
                        return True
                    if record.version == version:
                        return False
                    if modified_at and record.modified_at and modified_at < record.modified_at:
                        return False  # an older version delivered out of order
                    for name, value in values.items():
                        setattr(record, name, value)
                    record.rejected_by_exely = False
                    return True
            except IntegrityError:
                if attempt:
                    raise
        return False

    def pending(self, today: date, relevance_days: int) -> list[BookingRecord]:
        """Relevant bookings of all properties whose current version Exely has not confirmed."""
        stmt = (
            select(BookingRecord)
            .where(
                BookingRecord.checkout >= today - timedelta(days=relevance_days),
                or_(
                    BookingRecord.confirmed_version.is_(None),
                    BookingRecord.confirmed_version != BookingRecord.version,
                ),
            )
            .order_by(BookingRecord.received_at)
        )
        with self._sessions() as session:
            return list(session.scalars(stmt))

    def for_property(self, property_id: int, today: date, relevance_days: int) -> list[BookingRecord]:
        """Relevant bookings of one property, confirmed or not."""
        stmt = (
            select(BookingRecord)
            .where(
                BookingRecord.property_id == property_id,
                BookingRecord.checkout >= today - timedelta(days=relevance_days),
            )
            .order_by(BookingRecord.received_at)
        )
        with self._sessions() as session:
            return list(session.scalars(stmt))

    def confirm(
        self, reservation_id: str, *, echo_token: str | None, status: str | None, exely_number: str | None
    ) -> ConfirmOutcome:
        with self._sessions.begin() as session:
            record = session.get(BookingRecord, reservation_id)
            if record is None:
                return ConfirmOutcome.UNKNOWN
            # echoToken is optional in Exely's confirmation; fall back to the status then.
            current = echo_token == record.version if echo_token else status == record.status
            if exely_number:
                record.exely_number = exely_number
            if not current:
                return ConfirmOutcome.STALE
            record.confirmed_version = record.version
            record.exely_status = status
            record.confirmed_at = _now()
            record.rejected_by_exely = not exely_number
            return ConfirmOutcome.REJECTED if record.rejected_by_exely else ConfirmOutcome.CONFIRMED

    def pending_acks(self) -> list[BookingRecord]:
        """Bookings Exely confirmed that are not yet acknowledged to Unit.Travel."""
        stmt = select(BookingRecord).where(
            BookingRecord.confirmed_version == BookingRecord.version,
            or_(BookingRecord.acked_version.is_(None), BookingRecord.acked_version != BookingRecord.version),
        )
        with self._sessions() as session:
            return list(session.scalars(stmt))

    def mark_acked(self, reservation_id: str, version: str) -> None:
        with self._sessions.begin() as session:
            session.execute(
                update(BookingRecord)
                .where(BookingRecord.reservation_id == reservation_id)
                .values(acked_version=version)
            )


def _now() -> datetime:
    return datetime.now(UTC).replace(tzinfo=None)


def _utc_naive(moment: datetime | None) -> datetime | None:
    if moment is None or moment.tzinfo is None:
        return moment
    return moment.astimezone(UTC).replace(tzinfo=None)
