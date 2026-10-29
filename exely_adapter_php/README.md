# Адаптер Exely Channel Manager ↔ Unit.Travel (PHP 5 + Zend Framework 1)

PHP-версия адаптера из `../exely_adapter` (Python). Протокол, правила сопоставления и поведение у них одинаковые:
- со стороны Exely адаптер реализует протокол канала («Exely Channel Manager API» от 29.09.2025);
- со стороны Unit.Travel он клиент Partner Inventory API v1.9.0 (`openapi_3.json`) с одним `X-API-Key` на все отели.

Обе версии выдают одинаковые `echoToken` и работают с одной таблицей `exely_bookings` с одинаковыми колонками. Поэтому на той же MySQL-базе одну версию можно заменить другой: Exely не получит уже доставленные брони повторно.

Подробные таблицы сопоставления, список того, что сообщить Exely при сертификации, и предложения по доработке Partner API — в [`../exely_adapter/README.md`](../exely_adapter/README.md). Ниже — только то, что относится к PHP-версии.

## Требования

- **PHP 5.3.3+, 64-битная сборка** (деньги считаются в целых центах, `property_id` — int64). Проверено на PHP 5.3.10 и 5.5.9.
- Расширения: `json`, `curl`, `pdo` с `pdo_mysql` (продакшен) или `pdo_sqlite` (разработка).
- Zend Framework 1.11 или 1.12. Используются только `Zend_Application`, `Zend_Controller`, `Zend_Db`, `Zend_Http_Client` и `Zend_Log`, одинаковые в обеих ветках. Тесты прогнаны на ZF 1.11.11.
- MySQL 5.5.3+ (из-за `utf8mb4`).

PHP 5 давно не получает обновлений безопасности. Адаптер принимает запросы из интернета и хранит данные гостей, поэтому держите его за HTTPS reverse proxy и не открывайте наружу ничего, кроме `/exely` и `/webhooks/unit-travel`.

## Структура

```
application/
  Bootstrap.php            сборка сервиса из application.ini
  configs/application.ini.dist
  controllers/             Exely (POST /exely), Webhook, Health, Error
library/Exely/             логика адаптера (классы Exely_*, автозагрузка ZF1)
  Service.php              разбор action и вызовы Unit.Travel
  Ari.php, Catalog.php     update → /ari, номера и тарифы → Exely
  Bookings.php             бронь Unit.Travel → бронь Exely
  BookingStore.php         очередь броней (Zend_Db)
  UnitTravel/Client.php    клиент Partner API (Zend_Http_Client + cURL)
data/schema.mysql.sql      таблица exely_bookings
public/index.php           front controller
tests/                     PHPUnit
```

## Установка

1. Подключить ZF1 одним из способов:
   - `composer install` (ставит `zendframework/zendframework1` ~1.12 и PHPUnit);
   - либо ZF1 уже лежит в `include_path` сервера или его путь задан в переменной окружения `ZF1_PATH`.
2. Скопировать `application/configs/application.ini.dist` в `application.ini` и заполнить:
   - `exely.username` и `exely.password`;
   - `unitTravel.apiKey` и `unitTravel.webhookSecret`;
   - доступ к БД.

   Флаги в ini пишутся как `1`/`0`.
3. Создать таблицу:
   ```bash
   mysql exely_adapter < data/schema.mysql.sql
   ```
4. Указать веб-серверу корень `public/`:
   - Apache: включить `mod_rewrite`, правила уже есть в `.htaccess`;
   - nginx + php-fpm: `try_files $uri /index.php?$args;`.
5. Задать `APPLICATION_ENV`: `production` по умолчанию, `development` для разработки (база SQLite в `data/`).

Эндпоинты:
- `POST /exely` — канал для Exely;
- `POST /webhooks/unit-travel` — вебхук Unit.Travel, путь как в Python-версии (стандартный путь ZF1 `/webhook/unit-travel` тоже работает); выключен, если не задан `unitTravel.webhookSecret`;
- `GET /health`.

## Отличия от Python-версии

Логика та же, но в PHP каждый запрос — отдельный процесс:
- **Нет кэша между запросами.** Подключение отеля запрашивается в Unit.Travel при каждом `update`. Exely шлёт обновления по одному, так что это один лишний GET на обновление. События вебхука `connection.*` просто принимаются.
- **`revision`** — время в миллисекундах плюс счётчик внутри запроса. Exely шлёт обновления последовательно, поэтому порядок сохраняется и при нескольких процессах php-fpm.
- **Ожидание результата `/ari`** занимает до `ari.statusWaitSeconds` внутри запроса. `max_execution_time` в конфиге увеличен до 120 с.
- **Booking notification в Exely** отправляется синхронно из обработчика вебхука, с таймаутом 5 с. Если не дошло, ничего страшного: Exely сам опрашивает канал раз в 10 минут.
- **Защита от гонок.** Очередь броней обновляется с условием на прочитанную версию, без `SELECT ... FOR UPDATE`, поэтому одинаково работает на MySQL и SQLite.
- **TLS.** Запросы идут через cURL-адаптер ZF1 с явной проверкой сертификата и общим таймаутом. Сокеты PHP 5 по умолчанию сертификаты не проверяют, а сам адаптер ZF1 ограничивает только время соединения. Если на сервере устаревшее хранилище сертификатов, задайте свой CA-бандл в `unitTravel.caFile`.
- **Пароль.** При пустом `exely.password` адаптер отклоняет все запросы.

## Тесты

С Composer:

```bash
vendor/bin/phpunit -c tests/phpunit.xml.dist
```

Без Composer — с PHPUnit 3.7 или 4.8 и ZF1 по пути `ZF1_PATH`:

```bash
ZF1_PATH=/path/to/zf1/library phpunit -c tests/phpunit.xml.dist
```

92 теста покрывают: разбор значений Exely, каталог, `update` → ARI, преобразование броней, сквозные сценарии сервиса на фейке Partner API, а также приложение ZF1 целиком (Bootstrap, маршруты, контроллеры, подпись вебхука).

Прогоны:
- тесты: PHP 5.3.10 и PHP 5.5.9, ZF 1.11.11, PHPUnit 3.7.28;
- ручная проверка: HTTP-запросы через `php5-cgi` 5.5.9, проверка TLS-сертификатов на обеих версиях PHP.

ZF 1.12, PHP 5.4 и PHP 5.6 не проверялись: в окружении разработки их не было.
