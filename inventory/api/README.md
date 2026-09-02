# Unit.Travel Hotel Partner Inventory API

Спецификация API интеграции с PMS и Channel Manager (Servio, YieldPlanet и др.).
API integration spec for PMS and Channel Managers (Servio, YieldPlanet, etc.).

| | 🇷🇺 Русский | 🇬🇧 English | 🇺🇦 Українська |
|---|-----------|-----------|--------------|
| Guide | [`README.ru.md`](./README.ru.md) | [`README.en.md`](./README.en.md) | — |
| OpenAPI 3.0 | [`openapi.ru.yaml`](./openapi.ru.yaml) | [`openapi.en.yaml`](./openapi.en.yaml) | [`openapi.uk.yaml`](./openapi.uk.yaml) |

Все версии идентичны по структуре (эндпоинты, схемы, коды) и отличаются только
языком описаний. / All versions are structurally identical and differ only in
description language.

## 📘 Готовый dev-portal (один HTML-файл) / Ready dev portal (single HTML)

**[`dist/index.html`](./dist/index.html)** — self-contained страница Redoc с
переключателем **RU / EN / UK**, Redoc встроен внутрь → работает **офлайн**,
без CDN. Это файл для пересылки партнёрам (Servio, YieldPlanet).

Пересобрать / rebuild: `python3 build_portal.py` (читает три `openapi.*.yaml`
и `vendor/redoc.standalone.js`). Также спеку можно открыть в
[Swagger Editor](https://editor.swagger.io).

## API methods / Методы API

| Method · Метод | Endpoint | Purpose · Назначение |
|---|---|---|
| `GET` | `/ping` | Health-check + проверка токена / health-check + token check |
| `GET` | `/properties/{id}/rooms` | Каталог категорий номеров (для маппинга) / room categories catalog |
| `GET` | `/properties/{id}/rate-plans` | Каталог тарифов (для маппинга) / rate plans catalog |
| `GET` | `/properties/{id}/mappings` | Текущие соответствия кодов / current code mappings |
| `PUT` | `/properties/{id}/mappings` | Задать соответствие кодов партнёра нашим id / set partner-code → our-id mapping |
| `POST` | `/ari` | Приём наличия/цен/ограничений (идемпотентно, async) / push availability/rates/restrictions |
| `GET` | `/ari/messages/{message_uid}` | Статус обработки ARI-сообщения / ARI message processing status |
| `GET` | `/reservations` | Выдача броней (pull, по курсору) / fetch reservations (pull, cursor) |
| `POST` | `/reservations/{id}/acknowledge` | Подтвердить приём брони / acknowledge reservation receipt |
| `webhook` | `ReservationEvent` | Push броней партнёру (HMAC-подпись) / push reservations to partner (HMAC-signed) |
