# Unit.Travel Hotel Partner Inventory API — Integration Guide

Версия: **1.8.0** · Формальная спецификация: [`openapi.ru.yaml`](./openapi.ru.yaml)
(загружается в Swagger UI / Redoc). Аудитория: **PMS и Channel Manager**.

Модель интеграции — **ARI** (Availability, Rates, Inventory):

```
PMS / Channel Manager  ──push ARI──►  Unit.Travel        (наличие, цены, ограничения)
Unit.Travel            ──reservations──►  PMS / CM        (pull или webhook)
```

---

## 1. Аутентификация

- **Один общий API-ключ на провайдера** (весь PMS/CM), НЕ на каждый отель.
- Заголовок: `X-API-Key: <key>`.
- Каждый запрос по отелю несёт `property_id` и работает только при активном
  подключении отеля (см. §3).
- Опционально — IP-allowlist на стороне Unit.Travel.
- Все запросы только по HTTPS. Проверка ключа — `GET /ping`.

## 2. Окружения

| Окружение | Base URL |
|-----------|----------|
| Sandbox   | `https://api.test.unit.travel/partner/v1` |
| Production| `https://api.unit.travel/partner/v1` |

Интеграция принимается в production после прогона сценариев в sandbox
(маппинг → ARI → бронирование → отмена).

## 3. Онбординг

### 3.0 Инициация подключения отеля (с подтверждением в Extranet)

1. Отель копирует свой **`property_id`** в Extranet Unit.Travel.
2. В разделе «Каналы/Интеграции» PMS/CM отель вставляет `property_id` и
   запускает подключение → PMS/CM вызывает наш **`POST /connections`** (общим
   API-ключом провайдера). Создаётся подключение в статусе `pending`.
3. Отель **подтверждает** (или отклоняет) подключение в нашем Extranet.
4. Мы уведомляем PMS/CM: webhook **`connection.activated`** (или PMS опрашивает
   **`GET /connections/{id}`** до `status = active`).
5. Только после `active` открываются справочники, маппинг и ARI. До этого любой
   вызов по отелю → `409` (`connection_not_active`).

Отключение — `DELETE /connections/{id}`.

### 3.1 Маппинг кодов (после активации, один раз)

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
`rates`, `restrictions` (хотя бы один непустой). Для **нескольких объектов** в
одном сообщении используйте массив верхнего уровня `items[]` — каждый элемент
несёт свой `property_id` со своими `availability`/`rates`/`restrictions`.
Примеры — см. `openapi.ru.yaml` (`examples.AriPerRoom`, `AriPerGuest`,
`AriMultiProperty`).

- **availability** — «rooms to sell» на категорию (`room_code`) за диапазон
  дат. Хранится как аллотмент; доступность = `units` − наши брони.
- **rates** — цена за ночь, привязана к категории номера
  (`room_code`/`room_id`) и опционально к конкретному тарифу
  (`rate_plan_code`/`rate_plan_id`). Нетто, без налогов. Две модели цены:
  - `per_room` — одна `price` за ночь независимо от размещения.
  - `per_guest` — `occupancy_prices[]` с **абсолютной** ценой для каждого
    взрослого размещения (напр. `{ "occupancy": 1, "price": 80 }`,
    `{ "occupancy": 2, "price": 100 }`), а не множитель на человека.

  Детские цены в ARI **не передаются** — это отдельный конфиг Child rates.
- **restrictions** — `min_stay`/`max_stay`, `closed_to_arrival`/`_departure`,
  `stop_sell`, окна `min/max_advance_days`, `release_days`.
- Диапазон дат — `date_from..date_to` **включительно**, опционально
  `days_of_week` (иначе все дни).

### 4.1 Защита от повторов (Идемпотентность)
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
в ВАШИХ кодах (обратный маппинг); каждый номер также дублирует наши
`room_id`/`rate_plan_id`, `board` (питание), `is_refundable` и
`cancellation_policy`.

Дополнительные поля брони:
- **primary_guest** — основной гость: `first_name`/`last_name`, `email`,
  `phone`, `nationality`.
- **guests[]** — все гости, каждый привязан к номеру через `room_index`
  (индекс в `rooms[]`). В одной броне может быть несколько гостей на несколько
  номеров.
- **special_request** — свободный текст пожелания гостя.
- **rate_type** — `gross` или `net`; при `gross` поле `commission` описывает
  комиссию, которую удерживает Unit.Travel.
- **payment** — `type` (напр. `pay_at_hotel`, `prepaid`), `method` и
  **опциональный** объект `card` (номер, держатель, срок, CVC). Данные карты
  присутствуют только для сценариев гарантии картой/виртуальной карты; это
  PCI-чувствительные данные.

## 6. Ошибки

JSON: `{ "code": "...", "message": "...", "details": [ { "path", "reason" } ] }`.

| HTTP | Когда |
|------|-------|
| 400 | Некорректная структура/валидация запроса |
| 401 | Нет/неверный API-ключ |
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

1. Получить тестовый API-ключ и `property_id`.
2. `GET /ping` — проверить авторизацию.
3. Забрать каталог (`/rooms`, `/rate-plans`), задать `/mappings`.
4. Прогнать `POST /ari` (наличие + цены + ограничения), проверить статус.
5. Смоделировать бронь → получить её через pull/webhook → `acknowledge`.
6. Согласовать лимиты и перейти на боевой API-ключ.

---

_Замечания по полям и сценариям — на стороне Unit.Travel:
dev@unit.travel._
