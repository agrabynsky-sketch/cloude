# Адаптер Exely Channel Manager ↔ Unit.Travel

Отдельный сервис, через который Exely Channel Manager работает с Unit.Travel как с каналом продаж.

- Со стороны Exely адаптер работает как сервер и реализует протокол канала из документации «Exely Channel Manager API» от 29.09.2025.
- Со стороны Unit.Travel адаптер работает как клиент Partner Inventory API v1.9.0 (`openapi_3.json`). Это один провайдер с одним `X-API-Key` на все отели, подключённые через Exely.

```
             POST /exely                         Partner API (X-API-Key)
 Exely CM ───────────────────►  адаптер  ───────────────────────────────► Unit.Travel
          ◄─────────────────── (FastAPI) ◄─────────────────────────────── 
     GET bookingnotif          SQLite/PG     POST /webhooks/unit-travel
```

## Как сопоставляются методы

| Exely → адаптер | Что делает адаптер в Unit.Travel |
|---|---|
| `get-rooms-and-rate-plans` | `POST /connections` (идемпотентно), затем `GET /properties/{id}/rooms` и `/rate-plans`. Пока отель не подтвердил подключение в Extranet, отвечает ошибкой 375 |
| `update` | `POST /ari`, затем опрос `GET /ari/messages/{uid}` до `applied`/`failed`, но не дольше `ARI_STATUS_WAIT_SECONDS` |
| `get-bookings` без `hotelId` | Неподтверждённые Exely брони всех отелей из своей базы. При `UT_PULL_RESERVATIONS=true` сначала забирает `GET /reservations` по каждому активному подключению |
| `get-bookings` с `hotelId` | Все актуальные брони отеля, включая подтверждённые (выезд не раньше чем 60 дней назад) |
| `confirm-bookings` | Отмечает версию брони как доставленную и вызывает `POST /reservations/{id}/acknowledge` |
| вебхук `reservation.*` → адаптер | Сохраняет бронь и отправляет Exely booking notification (`EXELY_NOTIFY_URL`) |

**Идентификаторы.** Exely получает наши `room_id` и `rate_plan_id` как `roomTypeId` и `ratePlanId`. Поэтому `/mappings` не используется: отель сопоставляет их со своими номерами в личном кабинете Exely. В качестве `hotelId` используется наш `property_id`: его вводит наш менеджер, когда Exely присылает письмо с запросом на подключение.

**Варианты размещения** (`occupancies`):
- для номера с ценой за гостя (`per_guest`) это `<room_id>_<n>`, где n от 1 до `max_adults`; такие цены уходят в `occupancy_prices`;
- для номера с ценой за номер (`per_room`) это один вариант `<room_id>_room`.

Цены на доп. место и детские не предлагаются: `/ari` их не принимает.

**Ограничения:**

| Exely | Unit.Travel |
|---|---|
| `quota` | `availability[].units` |
| `closed` с `ratePlanId` | `restrictions[].stop_sell` |
| `closed` с `quota`, без `ratePlanId` | `availability[].closed` |
| `minLos` (0 = снять) | `min_stay` (0 → 1) |
| `maxLos` | `max_stay` (0 = без ограничения с обеих сторон) |
| `closedToArrival` / `closedToDeparture` | `closed_to_arrival` / `closed_to_departure` |
| `minimumAdvanceBooking` | `min_advance_days` |
| `maximumAdvanceBooking` (-1 = снять) | `max_advance_days` (-1 → 0). Значение 0 («только в день заезда») не поддерживается: предупреждение |
| `minLosArrivalBased` / `maxLosArrivalBased` | не поддерживаются: предупреждение |
| `mealPlanType` | игнорируется: питание задано в самом тарифе |

Exely не присылает поля, которые уже доставлены, а Unit.Travel оставляет отсутствующие поля без изменений. Поэтому адаптер передаёт только те поля, что пришли. Значения абсолютные, так что повторная отправка безвредна.

- `message_uid` — новый на каждое сообщение.
- `revision` — строго возрастающее время в миллисекундах: Exely шлёт обновления последовательно, и порядок прихода совпадает с порядком изменений.

**Брони:**
- `bookingPerDayPrices` получаем делением цены номера на число ночей; остаток от округления уходит в последнюю ночь.
- Комиссия для `gross` разбирается из текста: «15%» или «101.25 EUR».
- Тип оплаты переводится так: `prepaid`/`deposit` → PREPAY, `guarantee` → CREDIT, `pay_at_hotel` → CASH.
- Условия отмены, национальность гостя, тип тарифа и отметка о карте уходят в `additionalInfo`.
- Данные карты в Exely передать нельзя. Перед сохранением в базу адаптер их удаляет, оставляя только `brand`.

## Запуск

```bash
pip install .
```

```bash
cp .env.example .env   # заполнить EXELY_*, UT_API_KEY, UT_WEBHOOK_SECRET
```

```bash
uvicorn exely_adapter.app:create_app --factory --host 0.0.0.0 --port 8000
```

Или в Docker (база в томе `/data`):

```bash
docker build -t exely-adapter .
```

```bash
docker run --env-file .env -p 8000:8000 -v exely-data:/data exely-adapter
```

Запускать нужно **один процесс** (один worker): `revision` берётся из часов процесса. Для продакшена:
- в `DATABASE_URL` указать PostgreSQL или MySQL (`pip install .[postgres]` или `.[mysql]`);
- поставить сервис за HTTPS — Exely требует HTTPS для рабочей интеграции.

Эндпоинты:
- `POST /exely` — канал для Exely, путь задаётся в `EXELY_PATH`;
- `POST /webhooks/unit-travel` — вебхук Unit.Travel; выключен, если не задан `UT_WEBHOOK_SECRET`;
- `GET /health`.

Все настройки с описанием — в `.env.example` и `src/exely_adapter/config.py`.

## Тесты

```bash
pip install -e .[dev]
```

```bash
pytest
```

Тесты проверяют весь путь через HTTP на фейке Partner API, построенном по `openapi_3.json`.

## Что сообщить Exely при сертификации

- URL канала, `username` и `password`.
- Поддерживаемые ограничения: MinLOS, MaxLOS, CTA, CTD, MinAdvanceBooking, MaxAdvanceBooking. Ограничения по дню заезда (`*ArrivalBased`) не заявлять.
- Собственные коды адаптера:
  - ошибки: 901 — неверный логин или пароль; 902 — некорректный запрос; 903 — неизвестный action; 904 — лимит запросов Unit.Travel; 905 — Unit.Travel недоступен; 906 — внутренняя ошибка;
  - предупреждения: 951 — значение не поддерживается и проигнорировано; 952 — обновление принято, но ещё не применено; 953 — номер без тарифов пропущен; 954 — неизвестная бронь в подтверждении; 955 — тип обновления запрещён подключением.
- Попросить включить подтверждения и для броней, которые Exely не создал (подтверждение без `externalNumber`). Без этого адаптер не узнает, что бронь в Exely не попала.

## Что предлагается доработать в Partner API

1. **Тело запроса для `POST /reservations/{id}/acknowledge`**, чтобы передавать ответ Exely:
   ```json
   {"external_reservation_code": "20241111-111111-22548963", "status": "new", "result": "accepted"}
   ```
   При `result: "rejected"` Exely бронь не создал, например из-за отсутствия мест: это риск овербукинга, и отель нужно предупредить. Адаптер уже умеет отправлять это тело, включается флагом `UT_ACK_WITH_DETAILS=true`. Пока флаг выключен, адаптер пишет в лог ошибку `Exely did not create booking ...`. Её стоит завести в алерты.
2. **Ограничения по дню заезда** (MinLOS/MaxLOS arrival-based). Когда появятся поля в `RestrictionItem`, нужно сопоставить `minLosArrivalBased`/`maxLosArrivalBased` в `ari.py` (`_restriction_fields`) и заявить их Exely.
3. **Документация `AvailabilityItem`.** Сейчас там написано «units − наши подтверждённые брони», но фактически `units` — это остаток к продаже, как и `quota` в Exely. Описание стоит поправить.
