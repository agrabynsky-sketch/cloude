# Unit.Travel — архитектура БД инвенторной системы бронирования отелей

MySQL 5.6/5.7 (InnoDB, utf8mb4) + PHP как слой бизнес-логики.
Цель: искать **минимальный тариф сразу по сотням отелей** под заданные
даты и количество гостей — за один запрос и без JOIN-ов на горячем пути.

## 1. Ключевая идея: два слоя данных

| Слой | Таблицы | Форма хранения | Кто пишет | Кто читает |
|------|---------|----------------|-----------|------------|
| **1. Конфигурация** (source of truth) | `rate_prices`, `rate_restrictions`, `allotment_contracts`, `room_availability`, тарифы/доступы, политики, extras | Компактно — **периодами** (`date_from`/`date_to` + `dow_mask`) | Менеджеры / PMS / загрузка | Пересборщик кэша |
| **2. Поисковый кэш** | `search_daily` (+ `search_best_nightly`) | Денормализованно — **посуточно**, одна строка = тариф × размещение × дата | Пересборщик (PHP/cron) | **Горячий поиск** |

Конфигурация удобна для редактирования и занимает мало места (периоды).
Поиск же идёт **только по слою 2**, где всё нужное уже «вплавлено» в строку:
цена, наличие, ограничения, канал. Никаких JOIN во время поиска.

## 2. Почему это быстро

- **Ноль JOIN на поиске.** Наличие, ограничения, обязательные per-night
  extras и гео (city_id/country_id) денормализованы прямо в `search_daily`.
  Поиск = один range-scan по индексу + `GROUP BY` + `HAVING`.
- **Покрывающий индекс** `idx_search (occupancy_id, stay_date, hotel_id, closed, price, available, …)`
  — сначала равенство по размещению, затем диапазон по дате. Движок отдаёт
  данные из индекса (index-only scan), не заглядывая в строки.
- **Партиционирование по месяцам** (`RANGE COLUMNS(stay_date)`): запрос на
  даты заезда трогает 1–2 партиции (partition pruning), очистка прошлого —
  мгновенный `DROP PARTITION` вместо `DELETE`.
- **Проверка «покрыты все ночи»** через `COUNT(*) = :nights` — корректно
  отбрасывает тарифы с дырами в наличии/ценах без под-JOIN.
- **Tier-3 предагрегат** `search_best_nightly` (мин. цена ночи по
  отель×дата×размещение) даёт мгновенный первый отбор кандидатов при поиске
  «весь город», после чего точный LOS-расчёт добивается только по ним.

## 3. Модель данных (слой 1)

```
countries → cities → hotels
                        │
                        └── room_types ──────► room_availability   (посуточное наличие категории)
                             │                 allotment_contracts (контракт на категорию)
                             │
                           rate_plans (1..N тарифов на категорию)
                                ├── board_types (питание)
                                ├── occupancy_options (2взр, 2взр+1реб …)
                                ├── rate_prices        (цена: тариф×размещение×период)
                                ├── rate_restrictions  (min/max stay, CTA/CTD, stop-sell, окна)
                                ├── cancellation_policies → cancellation_rules
                                ├── rate_plan_extras → extras
                                ├── channel_mask ──► sales_channels           (ось 1: аудитория)
                                └── visibility + access_group_id ──► access_groups   (ось 2: гейт)
                                                       ▲                  ▲
                                     account_access_groups          access_codes
                                            ▲                              (negotiated код)
                                        accounts (B2B / TO / Corp)
```

- **Категория (`room_types`)** = единица инвентаря И мерчендайзинга (как у
  Booking.com/Expedia). Наличие и аллотмент ключуются на неё; все тарифы
  категории делят её счётчик номеров.
- **Тариф (`rate_plans`)** = «как продаётся категория»: питание,
  отменяемость, политика отмены, валюта + **две оси видимости** (см. §3a).
- **Размещение (`occupancy_options`)** перечисляет продаваемые комбинации
  гостей `(adults, children)`. Цена задаётся на тариф × размещение, поэтому
  запрос под конкретное число гостей резолвится в один `occupancy_id`.
- **Наличие** считается посуточно на категории как
  `LEAST(allotment, total_rooms) − booked − blocked`. Бронь любого тарифа
  декрементит `room_availability` (атомарно, § запрос D) и триггерит
  пересборку кэша по всем тарифам категории.
- **Ограничения** — CTA/CTD на дату заезда/выезда, min/max stay, stop-sell,
  окна бронирования (min/max advance), release_days для аллотмента.

> **Почему без inventory_pool.** Unit.Travel — OTA-позиция: владелец
> инвентаря — отель (наш Extranet) или его CM/PMS, и пулинг общего физфонда
> между *разными* категориями решается выше нас. Поэтому носитель наличия —
> `room_type`. Если реально общий физфонд под 2+ категориями внутри нашего
> Extranet когда-нибудь понадобится — добавляется неломающе (`room_types.
> shared_bucket_id`), без переделки схемы.

### 3a. Каналы и public / private тарифы

Multi-channel продажи (B2C web, B2B, B2B2C, Corp, Mobile) моделируются
**двумя ортогональными осями** видимости на тарифе:

**Ось 1 — канал (`channel_mask`)** — широкая аудитория дистрибуции. Битовая
маска каналов из `sales_channels`. Тариф может быть виден в нескольких
каналах сразу. Поиск фильтрует `channel_mask & :channel`.

**Ось 2 — публичность (`visibility` + `access_group_id`)** — узкий гейт:
- `public` (`access_group_id = 0`) — виден всем в своих каналах.
- `private` — виден только тем, кто входит в `access_group` тарифа. В группу
  ведут два пути:
  - **аккаунт** (B2B-агент / туроператор / корпоративный клиент) —
    `accounts → account_access_groups → access_group`;
  - **negotiated / промо-код** — `access_codes.code → access_group` (гость
    или агент вводит код доступа).

Пример: `Corporate ACME` — приватный тариф в канале `corp`, привязан к
`access_group = CORP_ACME`. Его видят только аккаунты ACME (через членство)
или тот, кто ввёл negotiated-код контракта ACME.

**Резолв зрителя (PHP):** логин аккаунта → набор `access_group_id` (из
`account_access_groups`) + группа введённого кода (из `access_codes`) →
множество `:groups`. Публичный/анонимный поиск → `:groups` пуст.

**Фильтр в поиске (одна колонка, index-friendly):**
```sql
AND (channel_mask & :channel)                         -- ось 1
AND (access_group_id = 0 OR access_group_id IN (:groups))  -- ось 2
```
`access_group_id` денормализован в `search_daily`, поэтому приватность не
добавляет JOIN на горячем пути. Tier-3 предагрегат `search_best_nightly`
строится только по публичным тарифам (иначе утекут приватные цены);
приватный поиск идёт напрямую по `search_daily`.

## 4. Слой 2 — `search_daily`

Одна строка на `(rate_plan_id, occupancy_id, stay_date)`:

- **Цена** — уже в валюте отеля, включает обязательные per-night extras.
- **Наличие** — `available` на конкретную ночь.
- **Ограничения** — разрешённые на дату: `min_stay/max_stay/cta/ctd/closed/min_advance/max_advance`.
- **Видимость** — `channel_mask` (ось 1) + `visibility`/`access_group_id` (ось 2).
- **Гео** — `city_id/country_id` для поиска по городу/стране без JOIN.

## 5. Горячий поиск (один запрос)

```sql
SELECT hotel_id, MIN(total_price) AS min_total_price
FROM (
  SELECT hotel_id, rate_plan_id,
         SUM(price) total_price, COUNT(*) nights,
         MIN(available) min_avail, MAX(closed) any_closed,
         MAX(stay_date=:checkin AND cta=1)              cta_block,
         MAX(stay_date=:checkin AND min_stay > :nights) minstay_block
         /* + max_stay, min/max advance */
  FROM search_daily
  WHERE occupancy_id = :occ
    AND stay_date >= :checkin AND stay_date < :checkout
    AND hotel_id IN (…)
    AND closed = 0 AND available >= :rooms
    AND (channel_mask & :channel)                         -- канал
    AND (access_group_id = 0 OR access_group_id IN (:groups))  -- public/private
  GROUP BY hotel_id, rate_plan_id
  HAVING nights = :nights AND cta_block=0 AND minstay_block=0 /* … */
) t
GROUP BY hotel_id;
```

- Все ночи `[checkin, checkout)` берутся одним range-scan.
- `GROUP BY rate_plan_id` + `HAVING COUNT(*) = :nights` = тариф доступен на
  все ночи.
- `MIN(available)`, `MAX(closed)` и правила на ночь заезда фильтруют тариф.
- Внешний `GROUP BY hotel_id` даёт минимальный тариф по каждому отелю.
- **CTD** проверяется отдельным дешёвым добором по дате выезда (она не входит
  в ночи проживания).

Полные запросы: `database/03_rebuild_and_search.sql`.

## 6. Роль PHP (слой бизнес-логики)

1. **Резолв размещения**: `(adults, children[, ages])` → `occupancy_id`
   (с учётом правил размещения детей/доп. мест).
2. **Резолв доступа**: канал зрителя → бит `channel_mask`; логин аккаунта +
   введённый negotiated-код → множество `:groups` (`account_access_groups` +
   `access_codes`). Эти два параметра идут в фильтр поиска (§3a).
3. **Пересборка кэша**: при изменении цены/правила/аллотмента/доступа
   развернуть затронутые периоды в посуточные строки `search_daily`
   (batch INSERT). Инкрементально — только затронутый тариф × диапазон дат.
4. **Оформление**: опциональные extras, конвертация валют, налоги/сборы,
   расчёт штрафа отмены по `cancellation_rules` — вне горячего пути.
5. **Конвертация валют**: хранить в валюте отеля; для мультивалютного поиска
   либо конвертировать курс в PHP после агрегации, либо держать
   предрасчитанную колонку `price_eur` в кэше.

## 7. Интеграция с PMS и Channel Managers (ARI)

Аллотменты, цены, тарифы и ограничения могут обновляться из внешних систем
(PMS / Channel Manager) сообщениями **ARI** — Availability, Rates, Inventory.
См. `database/04_integration.sql`.

### Поток данных (inbound)

```
Внешняя система ──push/pull──► ari_inbox (сырьё, идемпотентно)
   │  (message_uid уникален → повторы отбрасываются)
   ▼
PHP-воркер ──► external_mappings (перевод внешних кодов в наши id)
   ▼
apply в Слой 1 (config), last-write-wins по external_rev
   ▼
cache_rebuild_queue ──► воркер ──► search_daily (Слой 2)
```

### Таблицы интеграции

| Таблица | Роль |
|---------|------|
| `integration_providers` | Типы систем (SiteMinder, TravelClick, Opera…), протокол, push/pull |
| `provider_connections` | Экземпляр интеграции отель↔провайдер; **что** ему разрешено менять (rates/avail/restrictions); `credentials_ref` — ссылка на секрет в vault, не сам ключ |
| `external_mappings` | Наш `room_type_id`/`rate_plan_id` ↔ внешний код провайдера |
| `ari_inbox` | Идемпотентный журнал входящих сообщений (сырьё + статус) |
| `ari_outbox` | Исходящие уведомления провайдеру (двусторонняя синхро) с ретраями |
| `cache_rebuild_queue` | Задачи на пересборку `search_daily` по затронутым диапазонам |
| `sync_log` | Наблюдаемость: что, откуда, сколько строк, ошибки |

Плюс на конфиг-таблицах (`rate_prices`, `rate_restrictions`,
`allotment_contracts`, `room_availability`) добавлены колонки происхождения:
`source`/`managed_by`, `connection_id`, `external_rev`, `updated_at`.

### Принципы, важные для инвенторной системы

- **Идемпотентность.** `ari_inbox.message_uid` уникален на подключение →
  `INSERT IGNORE` отбрасывает повторные доставки (частая ситуация у CM).
- **Порядок / last-write-wins.** Сообщения приходят не по порядку; применяем
  обновление, только если `external_rev` ≥ сохранённой (`ON DUPLICATE KEY
  UPDATE ... IF(:rev >= external_rev …)`). Так более старое сообщение не
  перезатрёт более свежее.
- **Изоляция от горячего пути.** Приём ARI пишет только в `ari_inbox` и
  Слой 1; поиск читает исключительно `search_daily`. Пик входящих обновлений
  не тормозит поиск, а согласованность даёт пересборка кэша через очередь.
- **Разграничение владения.** `provider_connections.manages_*` и
  `room_availability.managed_by` определяют, кто «владеет» ценой/наличием
  (например, наличием управляет CM в режиме free-sell, а ручная правка
  ставит `managed_by='internal'` и игнорирует последующие пуши — политика
  разрешения конфликтов реализуется в PHP).
- **Асинхронная пересборка.** Применение ARI не трогает `search_daily`
  напрямую — ставит задачу в `cache_rebuild_queue`; воркер схлопывает
  пересекающиеся диапазоны и пересобирает пачками.
- **Двусторонняя синхро.** Своя продажа уменьшает наличие → запись в
  `ari_outbox` → доставка обратно в CM, чтобы избежать овербукинга.

### Роль PHP в интеграции

1. Валидация подписи/токена вебхука, быстрая запись в `ari_inbox` (ACK).
2. Воркер: разбор payload → `external_mappings` → применение в Слой 1 с
   last-write-wins → постановка в `cache_rebuild_queue`.
3. Разрешение конфликтов «ручное vs внешнее» по `managed_by`/`manages_*`.
4. Дельта-pull по `last_pull_cursor` для провайдеров без push.
5. Ретраи `ari_outbox`, алерты по `sync_log`/`provider_connections.status`.

## 8. Масштабирование

- Кэш растёт как `отели × категории × тарифы × размещения × горизонт_дней`.
  Держать горизонт (напр. 500 дней), старые партиции дропать.
- Партиции по месяцам + покрывающие индексы держат горячий рабочий набор в
  buffer pool.
- Read-реплики для поиска; запись (наличие/пересборка) на мастер.
- Слой 2 можно вынести в отдельный шард/инстанс — он самодостаточен.

## 9. Файлы

| Файл | Содержимое |
|------|-----------|
| `database/00_reference_data.sql` | Справочники (питание, каналы) + календарь |
| `database/01_schema_core.sql` | Слой 1 — конфигурация (source of truth) |
| `database/02_schema_search_cache.sql` | Слой 2 — поисковый кэш + партиции |
| `database/03_rebuild_and_search.sql` | Пересборка кэша и запросы поиска |
| `database/04_integration.sql` | Интеграция PMS / Channel Manager (ARI) |

## 10. Заметки по MySQL 5

- Нет CTE и оконных функций — используются производные таблицы/подзапросы.
- `JSON`-тип есть только в 5.7; в схеме не используется как ключевой (гибкие
  атрибуты при необходимости — отдельными таблицами атрибутов).
- Всё InnoDB + utf8mb4; внешние ключи по желанию (на кэш-таблицы FK не
  вешаем — их перестраивает приложение).
- `DECIMAL(10,2)` для денег (не float).
