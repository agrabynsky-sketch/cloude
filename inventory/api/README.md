# Unit.Travel Partner Inventory API

Спецификация API интеграции с PMS и Channel Manager (Servio, YieldPlanet и др.).
API integration spec for PMS and Channel Managers (Servio, YieldPlanet, etc.).

| | 🇷🇺 Русский | 🇬🇧 English |
|---|-----------|-----------|
| Guide | [`README.ru.md`](./README.ru.md) | [`README.en.md`](./README.en.md) |
| OpenAPI 3.0 | [`openapi.ru.yaml`](./openapi.ru.yaml) | [`openapi.en.yaml`](./openapi.en.yaml) |

Обе версии идентичны по структуре (эндпоинты, схемы, коды) и отличаются только
языком описаний. / Both versions are structurally identical (endpoints,
schemas, codes) and differ only in description language.

Открыть спецификацию визуально: загрузите `openapi.*.yaml` в
[Swagger Editor](https://editor.swagger.io) или Redoc. /
To view visually: load `openapi.*.yaml` into Swagger Editor or Redoc.

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
