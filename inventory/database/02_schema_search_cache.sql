-- =====================================================================
--  Unit.Travel — Инвенторная система
--  Файл 02: ПОИСКОВЫЙ СЛОЙ (денормализованный посуточный кэш)
--  СУБД: MySQL 5.6 / 5.7 (InnoDB)
-- ---------------------------------------------------------------------
--  Это ЕДИНСТВЕННАЯ таблица, по которой работает горячий поиск.
--  Одна строка = (тариф × размещение × дата) со ВСЕМ, что нужно для
--  расчёта минимального тарифа: цена, наличие, ограничения, канал.
--
--  Наличие и обязательные посуточные extras уже «вплавлены» в строку,
--  поэтому поиск сотен отелей = один индексный range-scan + агрегирование,
--  без единого JOIN.
--
--  Таблицу пересобирает/обновляет PHP (см. 03_rebuild_and_search.sql)
--  из конфигурационного слоя при любом изменении цен/правил/аллотмента.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE search_daily (
  -- измерения ------------------------------------------------------
  hotel_id       INT UNSIGNED     NOT NULL,
  city_id        INT UNSIGNED     NOT NULL,   -- для поиска «по городу» без JOIN
  country_id     SMALLINT UNSIGNED NOT NULL,
  room_type_id   INT UNSIGNED     NOT NULL,
  rate_plan_id   INT UNSIGNED     NOT NULL,
  board_type_id  TINYINT UNSIGNED NOT NULL,
  occupancy_id   SMALLINT UNSIGNED NOT NULL,
  adults         TINYINT UNSIGNED NOT NULL,   -- денормализовано для фильтра
  children       TINYINT UNSIGNED NOT NULL,
  stay_date      DATE             NOT NULL,

  -- цена (уже в валюте отеля, вкл. обязательные per-night extras) ---
  price          DECIMAL(10,2)    NOT NULL,
  currency       CHAR(3)          NOT NULL,

  -- наличие --------------------------------------------------------
  available      SMALLINT UNSIGNED NOT NULL,  -- allotment-booked-blocked на эту ночь

  -- ограничения (уже разрешённые на конкретную дату) ---------------
  min_stay       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  max_stay       TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = нет
  cta            TINYINT(1)       NOT NULL DEFAULT 0,    -- closed to arrival
  ctd            TINYINT(1)       NOT NULL DEFAULT 0,    -- closed to departure
  closed         TINYINT(1)       NOT NULL DEFAULT 0,    -- stop-sell / нет продаж
  min_advance    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_advance    SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  -- видимость: каналы + приватность --------------------------------
  channel_mask   INT UNSIGNED     NOT NULL DEFAULT 1,     -- ось 1: аудитория
  visibility     TINYINT UNSIGNED NOT NULL DEFAULT 0,     -- 0=public, 1=private
  access_group_id INT UNSIGNED    NOT NULL DEFAULT 0,     -- ось 2: гейт (0=public)

  PRIMARY KEY (rate_plan_id, occupancy_id, stay_date),

  -- ГЛАВНЫЙ покрывающий индекс поиска.
  -- Порядок колонок: сначала равенство (occupancy_id), затем диапазон
  -- по дате, затем фильтр отелей и цена — чтобы range-scan был плотным,
  -- а движок мог отдавать данные из индекса (index-only).
  KEY idx_search (occupancy_id, stay_date, hotel_id, closed, access_group_id,
                  price, available, rate_plan_id, min_stay, max_stay,
                  cta, ctd, channel_mask),

  -- поиск по городу
  KEY idx_search_city (occupancy_id, stay_date, city_id, closed, access_group_id, price),

  -- обслуживание/чистка по датам
  KEY idx_stay_date (stay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- ---------------------------------------------------------------------
-- ПАРТИЦИОНИРОВАНИЕ ПО МЕСЯЦАМ (RANGE по stay_date).
-- Даёт partition pruning: поиск дат заезда трогает лишь 1-2 партиции,
-- а очистка прошлого = DROP PARTITION (мгновенно, без DELETE).
-- PRIMARY KEY включает stay_date, поэтому партиционирование допустимо.
-- ---------------------------------------------------------------------
PARTITION BY RANGE COLUMNS(stay_date) (
  PARTITION p2026_01 VALUES LESS THAN ('2026-02-01'),
  PARTITION p2026_02 VALUES LESS THAN ('2026-03-01'),
  PARTITION p2026_03 VALUES LESS THAN ('2026-04-01'),
  PARTITION p2026_04 VALUES LESS THAN ('2026-05-01'),
  PARTITION p2026_05 VALUES LESS THAN ('2026-06-01'),
  PARTITION p2026_06 VALUES LESS THAN ('2026-07-01'),
  PARTITION p2026_07 VALUES LESS THAN ('2026-08-01'),
  PARTITION p2026_08 VALUES LESS THAN ('2026-09-01'),
  PARTITION p2026_09 VALUES LESS THAN ('2026-10-01'),
  PARTITION p2026_10 VALUES LESS THAN ('2026-11-01'),
  PARTITION p2026_11 VALUES LESS THAN ('2026-12-01'),
  PARTITION p2026_12 VALUES LESS THAN ('2027-01-01'),
  PARTITION pmax     VALUES LESS THAN (MAXVALUE)
);

-- =====================================================================
--  ОПЦИОНАЛЬНЫЙ ТЮНИНГ-СЛОЙ (Tier-3): «лучшая цена за ночь»
--  Предагрегат: минимальная цена ночи по (отель × дата × размещение)
--  среди всех тарифов без stop-sell и с наличием. Даёт мгновенный
--  ПЕРВЫЙ отбор кандидатов при поиске «300 отелей города», после чего
--  точный расчёт с LOS-правилами добивается только по кандидатам.
--  ВНИМАНИЕ: этого слоя недостаточно для финальной цены, т.к. min/max
--  stay и CTA/CTD проверяются только по search_daily.
--  Строится ТОЛЬКО по публичным тарифам (visibility=public), иначе
--  утекут приватные цены. Приватный поиск идёт напрямую по search_daily
--  с фильтром access_group_id.
-- =====================================================================

CREATE TABLE search_best_nightly (
  hotel_id      INT UNSIGNED     NOT NULL,
  city_id       INT UNSIGNED     NOT NULL,
  occupancy_id  SMALLINT UNSIGNED NOT NULL,
  stay_date     DATE             NOT NULL,
  min_price     DECIMAL(10,2)    NOT NULL,
  max_available SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (occupancy_id, stay_date, hotel_id),
  KEY idx_bn_city (occupancy_id, stay_date, city_id, min_price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
