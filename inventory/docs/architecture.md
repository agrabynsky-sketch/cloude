# Unit.Travel — архитектура БД инвенторной системы бронирования отелей

MySQL 5.6/5.7 (InnoDB, utf8mb4) + PHP как слой бизнес-логики.
Цель: искать **минимальный тариф сразу по сотням отелей** под заданные
даты и количество гостей — за один запрос и без JOIN-ов на горячем пути.

## 1. Ключевая идея: два слоя данных

| Слой | Таблицы | Форма хранения | Кто пишет | Кто читает |
|------|---------|----------------|-----------|------------|
| **1. Конфигурация** (source of truth) | `rate_prices`, `rate_restrictions`, `allotment_contracts`, `room_availability`, политики, extras | Компактно — **периодами** (`date_from`/`date_to` + `dow_mask`) | Менеджеры / PMS / загрузка | Пересборщик кэша |
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
countries → cities → hotels → room_types → rate_plans
                                   │            ├── board_types (питание)
                                   │            ├── occupancy_options (2взр, 2взр+1реб …)
                                   │            ├── rate_prices        (цена: тариф×размещение×период)
                                   │            ├── rate_restrictions  (min/max stay, CTA/CTD, stop-sell, окна)
                                   │            ├── cancellation_policies → cancellation_rules
                                   │            └── rate_plan_extras → extras
                                   └── allotment_contracts → room_availability (посуточное наличие)
```

- **Тариф (`rate_plans`)** = «как продаётся категория»: питание,
  отменяемость, политика отмены, валюта, битовая маска каналов.
- **Размещение (`occupancy_options`)** перечисляет продаваемые комбинации
  гостей `(adults, children)`. Цена задаётся на тариф × размещение, поэтому
  запрос под конкретное число гостей резолвится в один `occupancy_id`.
- **Наличие** — общее на категорию (`room_type`), считается посуточно как
  `allotment − booked − blocked`. Бронирование декрементит `room_availability`
  и триггерит точечную пересборку строк кэша.
- **Ограничения** — CTA/CTD на дату заезда/выезда, min/max stay, stop-sell,
  окна бронирования (min/max advance), release_days для аллотмента.

## 4. Слой 2 — `search_daily`

Одна строка на `(rate_plan_id, occupancy_id, stay_date)`:

- **Цена** — уже в валюте отеля, включает обязательные per-night extras.
- **Наличие** — `available` на конкретную ночь.
- **Ограничения** — разрешённые на дату: `min_stay/max_stay/cta/ctd/closed/min_advance/max_advance`.
- **Каналы** — `channel_mask` (битовая маска).
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
    AND (channel_mask & :channel)
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
2. **Пересборка кэша**: при изменении цены/правила/аллотмента развернуть
   затронутые периоды в посуточные строки `search_daily` (batch INSERT).
   Инкрементально — только затронутый тариф × диапазон дат.
3. **Оформление**: опциональные extras, конвертация валют, налоги/сборы,
   расчёт штрафа отмены по `cancellation_rules` — вне горячего пути.
4. **Конвертация валют**: хранить в валюте отеля; для мультивалютного поиска
   либо конвертировать курс в PHP после агрегации, либо держать
   предрасчитанную колонку `price_eur` в кэше.

## 7. Масштабирование

- Кэш растёт как `отели × категории × тарифы × размещения × горизонт_дней`.
  Держать горизонт (напр. 500 дней), старые партиции дропать.
- Партиции по месяцам + покрывающие индексы держат горячий рабочий набор в
  buffer pool.
- Read-реплики для поиска; запись (наличие/пересборка) на мастер.
- Слой 2 можно вынести в отдельный шард/инстанс — он самодостаточен.

## 8. Файлы

| Файл | Содержимое |
|------|-----------|
| `database/00_reference_data.sql` | Справочники (питание, каналы) + календарь |
| `database/01_schema_core.sql` | Слой 1 — конфигурация (source of truth) |
| `database/02_schema_search_cache.sql` | Слой 2 — поисковый кэш + партиции |
| `database/03_rebuild_and_search.sql` | Пересборка кэша и запросы поиска |

## 9. Заметки по MySQL 5

- Нет CTE и оконных функций — используются производные таблицы/подзапросы.
- `JSON`-тип есть только в 5.7; в схеме не используется как ключевой (гибкие
  атрибуты при необходимости — отдельными таблицами атрибутов).
- Всё InnoDB + utf8mb4; внешние ключи по желанию (на кэш-таблицы FK не
  вешаем — их перестраивает приложение).
- `DECIMAL(10,2)` для денег (не float).
