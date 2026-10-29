"""HTTP entry points: the channel endpoint Exely calls and the Unit.Travel webhook.

Run with: uvicorn exely_adapter.app:create_app --factory
"""

import json
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Any

from fastapi import BackgroundTasks, FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import ValidationError
from starlette.concurrency import run_in_threadpool

from . import __version__
from .config import Settings
from .notify import SIGNATURE_HEADER, ExelyNotifier, verify_signature
from .service import ExelyService
from .store import BookingStore
from .unit_travel import UnitTravelClient


def create_app(
    settings: Settings | None = None,
    *,
    unit_travel: UnitTravelClient | None = None,
    store: BookingStore | None = None,
    notifier: ExelyNotifier | None = None,
) -> FastAPI:
    settings = settings or Settings()  # type: ignore[call-arg]
    unit_travel = unit_travel or UnitTravelClient(
        settings.ut_base_url, settings.ut_api_key, timeout=settings.ut_timeout_seconds
    )
    store = store or BookingStore(settings.database_url)
    notifier = notifier or ExelyNotifier(settings.exely_notify_url)
    service = ExelyService(settings, unit_travel, store)

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        yield
        unit_travel.close()
        notifier.close()

    app = FastAPI(title="Exely ↔ Unit.Travel adapter", version=__version__, lifespan=lifespan)

    @app.post(settings.exely_path)
    async def exely_channel(request: Request) -> JSONResponse:
        # Always answer in Exely's envelope, even for a body that is not JSON.
        try:
            payload: Any = json.loads(await request.body())
        except ValueError:
            payload = None
        return JSONResponse(await run_in_threadpool(service.handle, payload))

    @app.post("/webhooks/unit-travel")
    async def unit_travel_webhook(request: Request, background: BackgroundTasks) -> dict[str, str]:
        if not settings.ut_webhook_secret:
            raise HTTPException(404)
        body = await request.body()
        if not verify_signature(settings.ut_webhook_secret, body, request.headers.get(SIGNATURE_HEADER)):
            raise HTTPException(401, "invalid signature")
        try:
            event = json.loads(body)
            kind = str(event.get("event") or "")
        except (ValueError, AttributeError):
            raise HTTPException(400, "invalid JSON") from None

        if kind.startswith("reservation."):
            try:
                changed = await run_in_threadpool(service.accept_reservation, event.get("reservation") or {})
            except ValidationError:
                raise HTTPException(422, "invalid reservation") from None
            if changed:
                background.add_task(notifier.notify)
        elif kind.startswith("connection."):
            service.forget_connection((event.get("connection") or {}).get("property_id"))
        return {"status": "ok"}

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    return app
