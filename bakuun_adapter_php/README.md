# Адаптер Bakuun ↔ Unit.Travel (PHP 5 + Zend Framework 1)

Адаптер подключает Unit.Travel к Bakuun как покупателя (**Buyer API (Push)**, https://docs.bakuun.com/buyer-api-push). Со стороны Unit.Travel он работает как провайдер Partner Inventory API v1.9.0 (`openapi_3.json`, совпадает с https://b2b.unit.travel/api/partner.html).

- **Bakuun → адаптер → Unit.Travel:** ARI (наличие, ограничения, цены) и запросы маппинга `getRoom` / `getRoomRatePlan`.
- **Unit.Travel → адаптер → Bakuun:** бронирования (новая, изменение, отмена).
- **Онбординг и маппинг:** связь отелей, сопоставление номеров и тарифов, активация в обеих системах.

```
          ARI push, getRoom            ARI (POST /ari), /mappings, /connections
 Bakuun ─────────────────────► адаптер ──────────────────────────────────────► Unit.Travel
        ◄───────────────────── (ZF1)   ◄────────────────────────────────────── 
   Pre-Booking + Ack, Modify,     MySQL      вебхук reservation.* / GET /reservations
   Cancel, Read Booking, Push Property
```

Требования те же, что у Exely-адаптера: PHP 5.3.3+ (64-битный), расширения `json`, `curl`, `pdo_mysql` (или `pdo_sqlite` для разработки), ZF 1.11/1.12, MySQL 5.5.3+.

## Онбординг и маппинг

Bakuun допускает два способа маппинга, адаптер поддерживает оба. Источник истины — таблица `bakuun_mappings`: код Bakuun → `room_id` / `rate_plan_id` Unit.Travel.

### Способ A: коды Bakuun, маппим мы (CLI)

```bash
php scripts/bakuun.php listing
```

```bash
php scripts/bakuun.php link HTL1.1 123
```

```bash
php scripts/bakuun.php automap HTL1.1
```

```bash
php scripts/bakuun.php automap HTL1.1 --save
```

```bash
php scripts/bakuun.php map HTL1.1 rate_plan RX.1.1.2 13501
```

```bash
php scripts/bakuun.php mappings HTL1.1
```

```bash
php scripts/bakuun.php activate HTL1.1
```

1. **`listing`** показывает отели партнёрского аккаунта в Bakuun (GetListing) рядом с нашими связями.
2. **`link`** берёт статические данные отеля в Bakuun (GetPropertyInfo) и запрашивает подключение в Unit.Travel (`POST /connections`, в `external_property_code` уходит код Bakuun). Отель подтверждает подключение в Extranet Unit.Travel.
3. **`automap`** предлагает пары, `--save` их сохраняет. Пара находится только при однозначном совпадении:
   - номер — по названию (без учёта регистра, пунктуации и алфавита, кириллица поддерживается) или по коду;
   - тариф — внутри сопоставленного номера: по названию или коду, затем по питанию плюс возвратности, затем «единственный тариф с обеих сторон».

   Сопоставления, заданные вручную, автомат не трогает.
4. **`map` / `unmap`** — ручная правка. Адаптер проверяет, что id существует и что тариф Unit.Travel принадлежит номеру, с которым сопоставлен номер Bakuun.
5. **`mappings`** показывает таблицу и то, что ещё не сопоставлено.
6. **`activate`**:
   - записывает коды Bakuun в `PUT /properties/{id}/mappings`, после чего брони из Unit.Travel приходят с кодами Bakuun в `room_code` / `rate_plan_code`;
   - отправляет в Bakuun `pushPropertyInfo` с `mappingStatus: "Active"`;
   - включает ARI и брони.

   По умолчанию все номера и тарифы Bakuun должны быть сопоставлены, `--partial` снимает это требование, `--no-push` не уведомляет Bakuun.

Пауза и отключение:

```bash
php scripts/bakuun.php suspend HTL1.1
```

```bash
php scripts/bakuun.php disconnect HTL1.1 --unit-travel
```

Обе команды сообщают статус Bakuun (`Suspended` или `Disconnected`). С флагом `--unit-travel` отключение закрывает и подключение в Unit.Travel.

### Способ B: Bakuun маппит наши коды

Bakuun вызывает `getRoom` и `getRoomRatePlan` с нашим кодом отеля (`property_id`). На первый запрос адаптер сам запрашивает подключение в Unit.Travel и отвечает ошибкой 453, пока отель не подтвердит его в Extranet. После подтверждения адаптер:
- отдаёт номера и тарифы (`cancelType`, `authPerson`, питание по `board`);
- записывает тождественный маппинг: код = наш id;
- включает отель.

Дальше Bakuun присылает ARI с нашими id. CLI для этого способа не нужен.

## ARI: что приходит от Bakuun и что уходит в Unit.Travel

Все действия Bakuun приходят на один эндпоинт `POST /bakuun`, принимается и любой путь под ним (Bakuun у каждого действия указывает свой URL). Действие определяется полем `action`. Ключи с лишними пробелами (`"roomCode "`, `"HotelRef "` — так в документации Bakuun) нормализуются.

| Bakuun `action` | Unit.Travel |
|---|---|
| `InvCountUpdate` | `availability[].units` (`N/A` → 0 номеров, с предупреждением) |
| `MasterClosedRQ` / `masterCondClosed` | `stop_sell` |
| `ArrivalClosedRQ` / `arrivalCondClosed` | `closed_to_arrival` |
| `DepartureClosedRQ` / `departureCondClosed` | `closed_to_departure` |
| `SetLosRQ` / `SetLosCondRQ` (`SetMinLOS`, `SetMaxLOS`) | `min_stay` / `max_stay` |
| `SetAdvBookingCondRQ` (`MinAdvBooking`, `MaxAdvBooking`) | `min_advance_days` / `max_advance_days` |
| `priceUpdate`, `hurdlesPriceUpdate` | `rates[]`: `price`; для номера с ценой за гостя одна цена на все варианты размещения |
| `occPriceUpdate` | `rates[].occupancy_prices` по `adultCount`; для номера с ценой за номер берётся цена при базовом числе гостей |
| `SetArrivalLosRQ`, `SetArrivalLosCondRQ`, `sellThresholdUpdate`, `getCancellation`, `losPriceUpdate` | не поддерживаются Unit.Travel: успешный ответ с предупреждением 901 |

**Уровни ограничений.** В Bakuun ограничения уровня номера и уровня тарифа независимы, в Unit.Travel есть только уровень тарифа. Адаптер хранит оба слоя по датам (`bakuun_restrictions`) и для каждого тарифа номера отправляет их комбинацию:
- стоп-продажа, CTA и CTD действуют, если включены на любом из уровней;
- MinLOS и минимальный срок до заезда — берётся большее значение;
- MaxLOS и максимальный срок до заезда — берётся более строгий из заданных лимитов.

Поэтому «открыть номер» не откроет тариф, закрытый отдельно. `N/A` снимает значение своего уровня. Подряд идущие даты с одинаковым значением сворачиваются в диапазоны. Отправляются только затронутые поля: Unit.Travel не меняет поля, которых нет в запросе.

**Цены.** По умолчанию берётся `amountBeforeTax` (ARI Unit.Travel — нетто без налогов), настройка `ari.priceField`. Цены BAR можно отклонять ошибкой 475 (`ari.rejectBarRates`). Удалить цену в Unit.Travel нельзя, поэтому цена `N/A` пропускается с предупреждением. Цены на детей, младенцев и дополнительных взрослых в ARI Unit.Travel не передаются (предупреждение 901).

**Маппинг в ARI.** Несопоставленный код номера или тарифа пропускается с предупреждением 451/452, остальные элементы применяются. Тариф, привязанный к другому номеру, чем указанный `roomCode`, даёт ошибку 452.

**Ответы.**
- Успех: `{"success": true, "warnings": [...], "errors": []}`, HTTP 200.
- Ошибка: коды Bakuun. HTTP 401 для кода 410, 5xx для кодов 500/502/503/504 (Bakuun может повторить), 400 для остальных.
- Ошибки Unit.Travel переводятся в коды Bakuun:

| Unit.Travel | Bakuun |
|---|---|
| 409 | 453 |
| 404 | 401 |
| 429 | 503 |
| неизвестный номер | 451 |
| неизвестный тариф | 452 |
| размещение | 478 |
| Unit.Travel недоступен | 502 |

- ARI Unit.Travel применяет асинхронно. Адаптер ждёт результат до `ari.statusWaitSeconds`; если не дождался — отвечает успехом с предупреждением 902.

## Бронирования

Брони приходят из Unit.Travel вебхуком (`POST /webhooks/unit-travel`) или забираются по cron (`scripts/bakuun.php sync`: `GET /reservations` всех активных отелей).

| Unit.Travel | Bakuun |
|---|---|
| `new` | Pre-Booking (`getBooking`, токен живёт 2 минуты) → Acknowledgement (`getBooking` + `BookingTokenCode` + `resID` = наш `reservation_id`) |
| `modified` | `pushModifyBooking` с `resID`. Если бронь до Bakuun не дошла — создаётся как новая |
| `cancelled` | `pushCancelBooking`. Если бронь до Bakuun не дошла — ничего не отправляется |

**Надёжность:**
- Отказ Bakuun (нет мест, закрыто и т. п.) окончателен для этой версии брони. Он пишется в лог с пометкой о риске овербукинга.
- Сбой Bakuun или сети повторяется: 2, 4, 8 … минут, не реже раза в час, до `bookings.maxAttempts` попыток.
- Перед повтором, изменением или отменой брони, которая не подтверждена как доставленная, адаптер проверяет её через Read Booking. Если Bakuun создал бронь, но ответ потерялся, дубля не будет. Ответ «Reservation ID already exists» (448) превращает создание в изменение.
- Unit.Travel получает acknowledge только после ответа Bakuun. С `unitTravel.ackWithDetails=1` в теле уходят номера Bakuun и результат — то же предложение по API, что и для Exely.
- Вебхук при временном сбое отвечает 503, чтобы Unit.Travel повторил его вместе с данными карты.

**Поля брони:**
- коды номеров и тарифов — из маппинга;
- цена номера делится по ночам (`bookingPerDayPrices`);
- gross → `BAR`, net → `NET`;
- комиссия переводится в проценты (Bakuun принимает 0–99);
- `paymentMethod`: `VCC`, `CC` при гарантии картой, `CASH` при оплате в отеле, иначе `BANK`; для B-MarketPlace задаётся принудительно через `bookings.paymentMethod = "BANK"`;
- дети и младенцы — с возрастами; время заезда и выезда — из настроек.

**Карты.** Данные карты уходят в Bakuun только для CC/VCC; CVC у Bakuun нет. В базе адаптера карта не хранится, остаётся только бренд. Повтор из сохранённой копии идёт без карты, а при включённом `pullReservations` свежая копия с картой приходит из Unit.Travel. Если Unit.Travel отдаёт карту маскированной, Bakuun её не примет.

## Установка и запуск

```bash
composer install
```

```bash
cp application/configs/application.ini.dist application/configs/application.ini
```

```bash
mysql bakuun_adapter < data/schema.mysql.sql
```

После копирования заполните в `application.ini`: логин и пароль для входящих запросов Bakuun (`bakuun.inbound.*`), аккаунт покупателя в Bakuun, URL от Bakuun, ключ Unit.Travel, секрет вебхука.

Без Composer ZF1 берётся из `include_path` или из переменной `ZF1_PATH`. Корень веб-сервера — `public/` (Apache с `.htaccess` или nginx + php-fpm с `try_files $uri /index.php?$args;`).

Cron, раз в несколько минут:

```bash
php scripts/bakuun.php sync
```

`sync` забирает брони, повторяет доставку и отправляет acknowledge.

Эндпоинты:
- `POST /bakuun` и `/bakuun/*` — ARI и маппинг от Bakuun;
- `POST /webhooks/unit-travel` — вебхук Unit.Travel;
- `GET /health`.

Наружу открывайте только эндпоинты для Bakuun и Unit.Travel, через HTTPS. PHP 5 не получает обновлений безопасности.

## Тесты

```bash
vendor/bin/phpunit -c tests/phpunit.xml.dist
```

49 тестов на фейках Partner API и Bakuun:
- ARI и слои ограничений;
- маппинг `getRoom` / `getRoomRatePlan`;
- онбординг: link, automap, map, activate, suspend;
- брони, включая потерянный ответ и повторы;
- приложение ZF1 и CLI.

Проверено на PHP 5.3.10 и 5.5.9 с ZF 1.11.11 и PHPUnit 3.7.28. Дополнительно точки входа прогнаны вручную через `php5-cgi` и CLI. С настоящими Bakuun и Unit.Travel адаптер не запускался.

## Что уточнить у Bakuun

1. **Какие коды приходят в ARI:** Bakuun или наши. Поддержаны оба способа, но нужно понимать, какой у нашего аккаунта.
2. **`resID` в Modify и Cancel:** наш номер брони или номер Bakuun. Сейчас отправляется наш — тот, что мы передали в Acknowledgement.
3. **Что ожидается в `pushPropertyInfo`.** Мы отправляем статические данные отеля из их же GetPropertyInfo с сопоставленными номерами и тарифами и статусом маппинга.
4. **Hurdle-цены:** они записываются как обычная цена.
5. **Скидки `offers`** (sales / last / easy): уже учтены в `amountBeforeTax` или нет.
6. **Налоги в брони:** Unit.Travel их не передаёт, адаптер подставляет значения из `bookings.tax*`.
7. **Повторы ARI:** повторяет ли Bakuun ARI при ответе с ошибкой, и какие HTTP-статусы он ожидает.
8. **`cancelType` — противоречие в документации:** в GetPropertyInfo 1 = возвратный, в getRoomRatePlan 1 = невозвратный. Адаптер следует каждой странице как написано.

## Что не поддерживает Unit.Travel API

- ограничения по дню заезда (MinLOS/MaxLOS Arrival);
- sell threshold;
- цены по длительности проживания (LOS);
- детские цены и цены на дополнительных взрослых в ARI;
- политики отмены и депозита в ARI.

Когда в Partner API появятся соответствующие поля, правки нужны в `Bakuun_Ari`.

Классы `UnitTravel_*` (клиент Partner API, отправка ARI, разбор значений) — общие с Exely-адаптером; их можно вынести в общую библиотеку.
