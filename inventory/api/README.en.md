# Unit.Travel Partner Inventory API — Integration Guide

Version: **1.0.0** · Formal spec: [`openapi.en.yaml`](./openapi.en.yaml)
(loads into Swagger UI / Redoc). Audience: **PMS Servio**, **Channel Manager
YieldPlanet** and other PMS/CM systems.

Integration model — **ARI** (Availability, Rates, Inventory):

```
PMS / Channel Manager  ──push ARI──►  Unit.Travel        (availability, rates, restrictions)
Unit.Travel            ──reservations──►  PMS / CM        (pull or webhook)
```

---

## 1. Authentication

- A **bearer token** (API key) is issued by Unit.Travel per **connection**
  (provider × property).
- Header: `Authorization: Bearer <token>`.
- Optional — IP allowlist on the Unit.Travel side.
- HTTPS only. Validate the token with `GET /ping`.

## 2. Environments

| Environment | Base URL |
|-------------|----------|
| Sandbox     | `https://api.sandbox.unit.travel/partner/v1` |
| Production  | `https://api.unit.travel/partner/v1` |

An integration is promoted to production after passing the sandbox scenarios
(mapping → ARI → reservation → cancellation).

## 3. Onboarding: code mapping (done once)

The partner works with ITS OWN room/rate codes. Before the first ARI you must
map them to our ids:

1. `GET /properties/{id}/rooms` and `GET /properties/{id}/rate-plans` — fetch
   our catalog of the property's room categories and rate plans.
2. `PUT /properties/{id}/mappings` — submit the correspondence:
   ```json
   [
     { "entity_type": "room",      "external_code": "SRV-ROOM-01", "internal_id": 4500 },
     { "entity_type": "rate_plan", "external_code": "STD-BB-FLEX", "internal_id": 13500 }
   ]
   ```
ARI elements with an **unmapped** code are rejected (`422 unknown_*_code`).

## 4. ARI flow (partner → Unit.Travel)

A single `POST /ari` message may carry any of the `availability`, `rates`,
`restrictions` arrays (at least one non-empty). Example — see `openapi.en.yaml`
(`examples.AriFull`).

- **availability** — "rooms to sell" for a room category (`room_code`) over a
  date range. Stored as an allotment; availability = `units` − our bookings.
- **rates** — per-night price of the base **adult** occupancy (`occupancy` =
  number of adults; net, excluding taxes). Child prices are NOT sent over ARI —
  they are a separate Child rates config.
- **restrictions** — `min_stay`/`max_stay`, `closed_to_arrival`/`_departure`,
  `stop_sell`, booking windows `min/max_advance_days`, `release_days`.
- Date range — `date_from..date_to` **inclusive**, optional `days_of_week`
  (otherwise all days).

### 4.1 Idempotency
`message_uid` is unique within a connection. Resending the same `message_uid`
is **not applied again** — the previous result is returned (`200` instead of
`202`). Use this for timeout retries.

### 4.2 Ordering and last-write-wins
A message carries a `revision` (source-side monotonic counter or unix
timestamp). An update is applied only if its `revision` is **not older** than
the stored one for the affected elements, so out-of-order delivery never
overwrites fresher data.

### 4.3 Asynchronous processing
`POST /ari` replies `202 Accepted` with a `Location` to the status. Intake
never blocks the system; the apply runs asynchronously. Status:
`GET /ari/messages/{message_uid}` → `received | processing | applied |
failed | skipped` + `errors[]` (per-element partial errors).

Recommendation: batch by date range and days of week, don't send one night at
a time; after `202`, poll the status once or twice with a short delay.

## 5. Reservations (Unit.Travel → partner)

Two options (both may be used):

- **Pull:** `GET /reservations?property_id=&since=&status=&cursor=` —
  paginated by cursor. After loading into the PMS/CM,
  `POST /reservations/{id}/acknowledge` so it is not repeated.
- **Push (webhook):** Unit.Travel sends `POST` to your endpoint with a
  `ReservationEvent` body (`reservation.created|modified|cancelled`). Signature —
  the `X-UnitTravel-Signature: sha256=<hex>` header (HMAC-SHA256 of the body
  with the connection secret). A `200` reply is the acknowledgement; otherwise
  retries with exponential backoff.

A reservation carries **child ages** (`rooms[].occupancy.children_ages`) and
`infants` for correct pricing on the PMS/CM side. Room/rate codes in the
reservation are already in YOUR codes (reverse mapping).

## 6. Errors

JSON: `{ "code": "...", "message": "...", "details": [ { "path", "reason" } ] }`.

| HTTP | When |
|------|------|
| 400 | Malformed request structure/validation |
| 401 | Missing/invalid token |
| 404 | Object/message not found |
| 422 | Semantics: unmapped code, invalid range, stale `revision` |
| 429 | Request rate limit exceeded (see `Retry-After`) |
| 5xx | Transient error — safe to retry (idempotency protects you) |

## 7. Limits and volumes

- Request rate: about **50 req/s** per connection (`429` + `Retry-After` when
  exceeded) — finalized when the key is issued.
- Message size: up to **5,000 ARI elements** total per `POST /ari`.
- Date horizon: up to **500 days** ahead.

## 8. Formats

- Dates — `YYYY-MM-DD`; date-time — ISO 8601 UTC (`2026-07-01T10:00:00Z`).
- Money — decimal number; currency — ISO 4217 (`EUR`, `UAH`, `USD`).
- Encoding — UTF-8, `Content-Type: application/json`.

## 9. Connection checklist

1. Obtain a sandbox token and `property_id`.
2. `GET /ping` — verify authentication.
3. Fetch the catalog (`/rooms`, `/rate-plans`), set `/mappings`.
4. Run `POST /ari` (availability + rates + restrictions), check the status.
5. Simulate a reservation → receive it via pull/webhook → `acknowledge`.
6. Agree on limits and switch to the production token.

---

_Field- and scenario-level questions — on the Unit.Travel side:
integrations@unit.travel._
