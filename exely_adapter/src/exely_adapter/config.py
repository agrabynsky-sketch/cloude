"""Adapter settings, read from environment variables (or a .env file)."""

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Credentials Exely Channel Manager sends in the body of every request.
    # The channel (Unit.Travel) issues them to Exely during certification.
    exely_username: str
    exely_password: str
    # Path of the endpoint Exely posts to.
    exely_path: str = "/exely"
    # Booking notification URL Exely assigns after certification
    # (https://cm.hopenapi.com/Api/Distribution/bookingnotif//<channel>).
    # Empty disables notifications; Exely still polls every 10 minutes.
    exely_notify_url: str = ""

    ut_base_url: str = "https://api.test.unit.travel/partner/v1"
    ut_api_key: str
    ut_timeout_seconds: float = 30.0
    # Secret for X-UnitTravel-Signature. Empty disables the webhook endpoint.
    ut_webhook_secret: str = ""
    # Pull /reservations of active connections on every get-bookings call,
    # on top of (or instead of) the reservation webhook.
    ut_pull_reservations: bool = True
    # Send Exely's confirmation (its booking number, status, accepted or
    # rejected) in the body of POST /reservations/{id}/acknowledge.
    # Partner API v1.9.0 defines no body there, so this is off by default.
    ut_ack_with_details: bool = False

    database_url: str = "sqlite:///./exely_adapter.db"

    # How long an `update` waits for Unit.Travel to apply the ARI message
    # before answering Exely (POST /ari is asynchronous).
    ari_status_wait_seconds: float = 10.0
    ari_status_poll_interval: float = 0.5
    ari_max_items_per_message: int = 1000

    # Exely treats bookings as relevant until two months after check-out.
    booking_relevance_days: int = 60
