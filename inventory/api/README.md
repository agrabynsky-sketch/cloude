# Unit.Travel Partner Inventory API — Integration Guide

Версия: **1.0.0** · Формальная спецификация: [`openapi.yaml`](./openapi.yaml)
(загружается в Swagger UI / Redoc). Аудитория: **PMS Servio**, **Channel
Manager YieldPlanet** и другие PMS/CM.

Модель интеграции — **ARI** (Availability, Rates, Inventory):

```
PMS / Channel Manager  ──push ARI──►  Unit.Travel        (наличие, цены, ограничения)
Unit.Travel            ──reservations──►  PMS / CM        (pull или webhook)
```

---

## 1. Аутентификация

- **Bearer-токен** (API-ключ) выдаётся Unit.Travel на **каждое подключение**
  (пара «провайдер × объект размещения»).
- Заголовок: `Authorization: Bearer <token>`.
- Опционально — IP-allowlist на стороне Unit.Travel.
- Все запросы только по HTTPS. Проверка токена — `GET /ping`.

## 2. Окружения

| Окружение | Base URL |
|-----------|----------|
| Sandbox   | `https://api.sandbox.unit.travel/partner/v1` |
| Production| `https://api.unit.travel/partner/v1` |

Интеграция принимается в production после прогона сценариев в sandbox
(маппинг → ARI → бронирование → отмена).

## 3. Онбординг: маппинг кодов (делается один раз)

Партнёр работает СВОИМИ кодами номеров/тарифов. Перед первым ARI нужно
сопоставить их нашим id:

1. `GET /properties/{id}/rooms` и `GET /properties/{id}/rate-plans` — забрать
   наш каталог категорий и тарифов объекта.
2. `PUT /properties/{id}/mappings` — прислать соответствия:
   ```json
   [
     { "entity_type": "room",      "external_code": "SRV-ROOM-01", "internal_id": 4500 },
     { "entity_type": "rate_plan", "external_code": "STD-BB-FLEX", "internal_id": 13500 }
   ]
   ```
Элементы ARI с **немаппленным** кодом отклоняются (`422 unknown_*_code`).

## 4. Поток ARI (партнёр → Unit.Travel)

Одно сообщение `POST /ari` может содержать любые из массивов `availability`,
`rates`, `restrictions` (хотя бы один непустой). Пример — см. `openapi.yaml`
(`examples.AriFull`).

- **availability** — «rooms to sell» на категорию (`room_code`) за диапазон
  дат. Хранится как аллотмент; доступность = `units` − наши брони.
- **rates** — цена за ночь базового размещения по **взрослым** (`occupancy` =
  число взрослых; нетто, без налогов). Детские цены в ARI **не передаются** —
  это отдельный конфиг Child rates.
- **restrictions** — `min_stay`/`max_stay`, `closed_to_arrival`/`_departure`,
  `stop_sell`, окна `min/max_advance_days`, `release_days`.
- Диапазон дат — `date_from..date_to` **включительно**, опционально
  `days_of_week` (иначе все дни).

### 4.1 Идемпотентность
`message_uid` уникален в рамках подключения. Повторная отправка того же
`message_uid` **не применяется заново** — возвращается прежний результат
(`200` вместо `202`). Используйте это при ретраях по таймауту.

### 4.2 Порядок и last-write-wins
У сообщения есть `revision` (монотонный счётчик или unix-таймстамп источника).
Обновление применяется, только если `revision` **не старше** сохранённого для
затронутых элементов. Так внеочередная доставка не перезатрёт свежие данные.

### 4.3 Асинхронная обработка
`POST /ari` отвечает `202 Accepted` и `Location` на статус. Приём никогда не
блокирует систему; применение идёт асинхронно. Статус:
`GET /ari/messages/{message_uid}` → `received | processing | applied |
failed | skipped` + `errors[]` (частичные ошибки по элементам).

Рекомендация: батчить (диапазонами дат и днями недели), не слать по одной
ночи; после `202` опросить статус 1–2 раза с паузой.

## 5. Бронирования (Unit.Travel → партнёр)

Два способа (можно оба):

- **Pull:** `GET /reservations?property_id=&since=&status=&cursor=` —
  постранично по курсору. После загрузки в PMS/CM —
  `POST /reservations/{id}/acknowledge`, чтобы бронь не повторялась.
- **Push (webhook):** Unit.Travel шлёт `POST` на ваш endpoint с телом
  `ReservationEvent` (`reservation.created|modified|cancelled`). Подпись —
  заголовок `X-UnitTravel-Signature: sha256=<hex>` (HMAC-SHA256 тела на
  секрете подключения). Ответ `200` = подтверждение; иначе — ретраи с
  экспоненциальной задержкой.

Бронь несёт **возраст детей** (`rooms[].occupancy.children_ages`) и `infants` —
для корректного расчёта на стороне PMS/CM. Коды номера/тарифа в броне уже
в ВАШИХ кодах (обратный маппинг).

## 6. Ошибки

JSON: `{ "code": "...", "message": "...", "details": [ { "path", "reason" } ] }`.

| HTTP | Когда |
|------|-------|
| 400 | Некорректная структура/валидация запроса |
| 401 | Нет/неверный токен |
| 404 | Объект/сообщение не найдены |
| 422 | Семантика: немаппленный код, некорректный диапазон, устаревший `revision` |
| 429 | Превышен лимит запросов (см. `Retry-After`) |
| 5xx | Временная ошибка — безопасно повторить (идемпотентность защищает) |

## 7. Лимиты и объёмы

- Лимит запросов: ориентировочно **50 req/s** на подключение (`429` +
  `Retry-After` при превышении) — финализируется при выдаче ключа.
- Размер сообщения: до **5 000 элементов** ARI суммарно на один `POST /ari`.
- Горизонт дат: до **500 дней** вперёд.

## 8. Форматы

- Даты — `YYYY-MM-DD`; дата-время — ISO 8601 UTC (`2026-07-01T10:00:00Z`).
- Деньги — десятичное число; валюта — ISO 4217 (`EUR`, `UAH`, `USD`).
- Кодировка — UTF-8, `Content-Type: application/json`.

## 9. Чек-лист подключения

1. Получить sandbox-токен и `property_id`.
2. `GET /ping` — проверить авторизацию.
3. Забрать каталог (`/rooms`, `/rate-plans`), задать `/mappings`.
4. Прогнать `POST /ari` (наличие + цены + ограничения), проверить статус.
5. Смоделировать бронь → получить её через pull/webhook → `acknowledge`.
6. Согласовать лимиты и перейти на production-токен.

---

_Замечания по полям и сценариям — на стороне Unit.Travel:
integrations@unit.travel._
