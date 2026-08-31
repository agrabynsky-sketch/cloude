-- =====================================================================
--  Unit.Travel — Собственная инвенторная система бронирования отелей
--  Файл 01: КОНФИГУРАЦИОННЫЙ СЛОЙ (source of truth)
--  СУБД:  MySQL 5.6 / 5.7  (InnoDB, utf8mb4)
-- ---------------------------------------------------------------------
--  Архитектура состоит из ДВУХ слоёв:
--    Слой 1 (этот файл) — компактное, редактируемое человеком/PMS
--                          хранилище: периоды, правила, аллотменты.
--    Слой 2 (файл 02)   — денормализованный посуточный поисковый кэш,
--                          который PHP пересобирает из Слоя 1.
--  Быстрый поиск идёт ТОЛЬКО по Слою 2. Слой 1 никогда не участвует
--  в горячем пути поиска.
-- =====================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------------------------------------------------------------------
-- 1. ГЕОГРАФИЯ И ОТЕЛИ (мастер-данные)
-- ---------------------------------------------------------------------

CREATE TABLE countries (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  iso2         CHAR(2)      NOT NULL,
  name         VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_country_iso (iso2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cities (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  country_id   SMALLINT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL,
  lat          DECIMAL(9,6) NULL,
  lng          DECIMAL(9,6) NULL,
  timezone     VARCHAR(40)  NOT NULL DEFAULT 'UTC',
  PRIMARY KEY (id),
  KEY idx_city_country (country_id),
  KEY idx_city_geo (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hotels (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  city_id        INT UNSIGNED NOT NULL,
  country_id     SMALLINT UNSIGNED NOT NULL,   -- денормализовано для поиска по стране
  code           VARCHAR(32)  NOT NULL,        -- внешний/партнёрский код
  name           VARCHAR(200) NOT NULL,
  stars          TINYINT UNSIGNED NULL,
  lat            DECIMAL(9,6) NULL,
  lng            DECIMAL(9,6) NULL,
  base_currency  CHAR(3)      NOT NULL DEFAULT 'EUR',
  timezone       VARCHAR(40)  NOT NULL DEFAULT 'UTC',
  checkin_time   TIME         NULL,
  checkout_time  TIME         NULL,
  -- налоги и сборы: ссылки на переиспользуемые политики + флаги «в цене» --
  vat_policy_id  INT UNSIGNED NULL,           -- политика НДS (tax_policies)
  vat_included   TINYINT(1)   NOT NULL DEFAULT 1,  -- НДС уже в цене тарифа?
  fee_policy_id  INT UNSIGNED NULL,           -- политика сборов (fee_policies)
  fee_included   TINYINT(1)   NOT NULL DEFAULT 0,  -- сборы уже в цене тарифа?
  -- ДЕТСКАЯ ПОЛИТИКА уровня отеля (как Booking «Child policies») ------
  allow_children    TINYINT(1) NOT NULL DEFAULT 1,  -- принимает ли детей вообще
  children_min_age  TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- допускаются с этого возраста (0=Any)
  status         ENUM('active','inactive','draft') NOT NULL DEFAULT 'draft',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hotel_code (code),
  KEY idx_hotel_city (city_id),
  KEY idx_hotel_country (country_id),
  KEY idx_hotel_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Переиспользуемые ПОЛИТИКИ налогов и сборов (справочники).
-- Отель ссылается на них по id (hotels.vat_policy_id / fee_policy_id).
-- В горячий поиск НЕ входят — PHP применяет их к финальной цене.
-- ---------------------------------------------------------------------

-- Политика НДС (обычно процент).
CREATE TABLE tax_policies (
  id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name     VARCHAR(120) NOT NULL,          -- «НДС 20%», «VAT 7%»
  percent  DECIMAL(6,4) NOT NULL,          -- 20.0000 = 20%
  active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Политика сборов (городской/курортный сбор и т.п.).
CREATE TABLE fee_policies (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,        -- «City tax», «Resort fee»
  calc_type  ENUM('per_night_per_person','per_night_per_room','per_stay_per_person','percent','fixed') NOT NULL,
  value      DECIMAL(10,4) NOT NULL,       -- percent: 2.0000=2%; иначе сумма
  currency   CHAR(3) NULL,                 -- для суммовых типов
  active     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. НОМЕРА (категории) и типы питания
-- ---------------------------------------------------------------------

-- room = единица инвентаря И мерчендайзинга (как у Booking.com/Expedia).
-- Наличие и аллотмент ключуются на room; все тарифы категории делят
-- её счётчик номеров. Пулинг общего физфонда между РАЗНЫМИ категориями
-- (если вдруг понадобится) остаётся на стороне отеля/CM/PMS, а при
-- необходимости добавляется неломающе полем room.shared_bucket_id.
CREATE TABLE room (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id       INT UNSIGNED NOT NULL,
  code           VARCHAR(32)  NOT NULL,
  name           VARCHAR(160) NOT NULL,
  -- базовая вместимость --------------------------------------------
  base_occupancy TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- «стандартное» размещение
  max_occupancy  TINYINT UNSIGNED NOT NULL DEFAULT 2,  -- всего гостей (взр.+дети[+младенцы])
  max_adults     TINYINT UNSIGNED NOT NULL DEFAULT 2,
  max_children   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_infants    TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- макс. младенцев
  -- если 1 — младенцы НЕ учитываются в max_occupancy (спят с родителями)
  exclude_infants_occupancy TINYINT(1) NOT NULL DEFAULT 1,
  total_rooms    SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- физический фонд (потолок аллотмента)
  -- физические атрибуты (описание + вторичные фильтры) --------------
  qty_bedrooms   TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- спален
  qty_livingrooms TINYINT UNSIGNED NOT NULL DEFAULT 0, -- гостиных
  qty_bathrooms  TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- ванных
  size           SMALLINT UNSIGNED NULL,               -- площадь, кв. м (NULL = неизвестно)
  smoking        TINYINT(1) NOT NULL DEFAULT 0,        -- 0 = no smoking, 1 = smoking
  floor          TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 unknown, 1 ground, 2 high
  room_view      TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- см. справочник room_views
  active         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_code (hotel_id, code),
  KEY idx_room_hotel (hotel_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Справочник видов из окна (значения room.room_view). Вынес в таблицу,
-- чтобы расширять без ALTER и переводить названия.
--   0 unknown, 1 city, 2 garden, 3 sea, 4 lake, 5 mountain (уточнить)
CREATE TABLE room_views (
  id    TINYINT UNSIGNED NOT NULL,
  code  VARCHAR(24)  NOT NULL,
  name  VARCHAR(80)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_view_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Глобальный справочник питания: RO/BB/HB/FB/AI и т.п.
CREATE TABLE board_types (
  id    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code  VARCHAR(8)   NOT NULL,          -- RO, BB, HB, FB, AI
  name  VARCHAR(80)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_board_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. ТАРИФНЫЕ ПЛАНЫ (rate plans)
--    Тариф = «как продаётся эта категория»: питание, отменяемость,
--    политика отмены, канал, валюта, публичность/приватность.
--    Две ОРТОГОНАЛЬНЫЕ оси видимости:
--      * channel_mask   — широкая аудитория (web / b2b / b2b2c / corp / mobile)
--      * visibility + access_group_id — узкий гейт приватного/negotiated
--        тарифа: 'public' виден всем в своих каналах; 'private' — только
--        аккаунтам/по коду доступа, входящим в access_group.
-- ---------------------------------------------------------------------

CREATE TABLE rate_plans (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id             INT UNSIGNED NOT NULL,   -- денормализовано для поиска/rebuild
  room_id              INT UNSIGNED NOT NULL,
  board_type_id        TINYINT UNSIGNED NOT NULL,
  code                 VARCHAR(40)  NOT NULL,
  name                 VARCHAR(160) NOT NULL,
  currency             CHAR(3)      NOT NULL DEFAULT 'EUR',
  is_refundable        TINYINT(1)   NOT NULL DEFAULT 1,
  cancellation_policy_id INT UNSIGNED NULL,
  -- модель ценообразования ------------------------------------------
  --   1 = price per room (цена за номер, размещение не влияет)
  --   2 = occupancy based (цена зависит от числа гостей — rate_prices по occupancy)
  pricing_model        TINYINT UNSIGNED NOT NULL DEFAULT 2,
  -- наследование от базового тарифа (derived rates) -----------------
  parent_rate_plan_id  INT UNSIGNED NULL,       -- родитель, если тариф производный
  --   0 independent, 1 parent+fixed, 2 parent-fixed,
  --   3 parent+percent, 4 parent-percent, 5 same as parent
  derive_type          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  --   Fixed: minor units (копейки/центы). Percent: basis points (1000 = 10.00%)
  derive_value         INT NOT NULL DEFAULT 0,
  -- ось 1: каналы дистрибуции (битовая маска sales_channels) ---------
  channel_mask         INT UNSIGNED NOT NULL DEFAULT 1,
  -- ось 2: публичность/приватность ----------------------------------
  visibility           ENUM('public','private') NOT NULL DEFAULT 'public',
  access_group_id      INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = публичный; иначе гейт доступа
  priority             SMALLINT NOT NULL DEFAULT 0,
  active               TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rp_code (hotel_id, code),
  KEY idx_rp_room (room_id, active),
  KEY idx_rp_hotel (hotel_id, active),
  KEY idx_rp_access (access_group_id),
  KEY idx_rp_parent (parent_rate_plan_id)       -- для каскадной пересборки производных
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Каналы продаж (web, b2b, b2b2c, corp, mobile, api...) — биты channel_mask
CREATE TABLE sales_channels (
  id    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bit   INT UNSIGNED NOT NULL,          -- 1,2,4,8...
  code  VARCHAR(32)  NOT NULL,
  name  VARCHAR(80)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3a. ДОСТУП К ПРИВАТНЫМ / NEGOTIATED ТАРИФАМ
--     access_group — именованный «гейт» приватного тарифа (контракт).
--     Тариф ссылается на ОДНУ группу (rate_plans.access_group_id); а
--     аккаунты и коды доступа — МНОЖЕСТВО членов этой группы. Так поиск
--     фильтруется одной колонкой access_group_id (см. 02/03).
-- ---------------------------------------------------------------------

-- B2B/Corp-клиенты: агентства, туроператоры, корпоративные аккаунты.
CREATE TABLE accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          ENUM('agency','tour_operator','corporate','affiliate') NOT NULL,
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(200) NOT NULL,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Именованный гейт доступа (= контракт/сегмент приватных тарифов).
CREATE TABLE access_groups (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)  NOT NULL,   -- 'CORP_ACME','TO_CORAL','PROMO_WINTER'
  name          VARCHAR(160) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accessgroup_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Членство аккаунта в группе(ах): логин аккаунта -> набор access_group_id.
CREATE TABLE account_access_groups (
  account_id      INT UNSIGNED NOT NULL,
  access_group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (account_id, access_group_id),
  KEY idx_aag_group (access_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Negotiated / промо-коды доступа: ввод кода -> access_group_id.
CREATE TABLE access_codes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(60) NOT NULL,          -- negotiated code, вводится гостем/агентом
  access_group_id INT UNSIGNED NOT NULL,
  valid_from      DATE NULL,
  valid_to        DATE NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_code (code),
  KEY idx_code_group (access_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. РАЗМЕЩЕНИЕ (occupancy) И ДЕТСКОЕ ЦЕНООБРАЗОВАНИЕ ПО ВОЗРАСТУ
--    Цена делится на ДВЕ части:
--      (1) База по ВЗРОСЛЫМ — occupancy_options + rate_prices, попадает в
--          search_daily (ключ occupancy_id = число взрослых). Быстрый скан.
--      (2) Детская доплата ПО ВОЗРАСТУ — конфиг УРОВНЯ ОТЕЛЯ (child_rates,
--          как Booking «Child rates»). В горячий скан НЕ входит; PHP считает
--          её на наборе кандидатов под точные возрасты детей.
--    Так возрасты остаются пер-отельными и точными, а кэш — лёгким.
-- ---------------------------------------------------------------------

-- Базовое размещение по числу ВЗРОСЛЫХ (single/double/extra-adult).
-- occupancy_id в кэше = adults. Дети в этот ключ НЕ входят.
CREATE TABLE occupancy_options (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id       INT UNSIGNED NOT NULL,
  adults        TINYINT UNSIGNED NOT NULL,
  label         VARCHAR(40) NULL,        -- «1 взр», «2 взр», «3 взр»
  PRIMARY KEY (id),
  UNIQUE KEY uq_occ (room_id, adults)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ДЕТСКИЕ СТАВКИ УРОВНЯ ОТЕЛЯ (как Booking «Child rates»).
-- Одна строка = один возрастной диапазон + его цена. Произвольное число
-- диапазонов в пределах 0-17 (непересекающихся; валидирует UI/PHP).
-- Применяются к номерам, где задан child occupancy (room.max_children > 0).
--   charge_type: free / percent (% от взрослой ночи) / fixed (сумма).
--   charge_unit: за ребёнка за ночь / за ребёнка за стей (для fixed).
-- Инфанты моделируются просто диапазоном 0-1 с charge_type='free'.
--
-- ПЕР-ОТЕЛЬНО по умолчанию (rate_plan_id = NULL). Опциональный override
-- на конкретный тариф/период (напр. детский завтрак дороже на BB) —
-- строка с rate_plan_id != NULL: она перекрывает отельную для этого тарифа.
CREATE TABLE child_rates (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id      INT UNSIGNED NOT NULL,
  rate_plan_id  INT UNSIGNED NULL,          -- NULL = на весь отель; иначе override тарифа
  age_from      TINYINT UNSIGNED NOT NULL,  -- включительно
  age_to        TINYINT UNSIGNED NOT NULL,  -- включительно
  charge_type   ENUM('free','percent','fixed') NOT NULL,
  amount        DECIMAL(10,2) NOT NULL DEFAULT 0,  -- percent: 50.00=50%; fixed: сумма
  charge_unit   ENUM('per_child_night','per_child_stay') NOT NULL DEFAULT 'per_child_night',
  -- опциональное окно действия (для сезонных детских цен); NULL = всегда
  date_from     DATE NULL,
  date_to       DATE NULL,
  sort          SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_childrate_hotel (hotel_id, rate_plan_id, age_from, age_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. ЦЕНЫ — заданы ПЕРИОДАМИ (компактно). PHP разворачивает в посуточный кэш.
--    Цена привязана к тарифу + варианту размещения + периоду.
-- ---------------------------------------------------------------------

CREATE TABLE rate_prices (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_plan_id  INT UNSIGNED NOT NULL,
  occupancy_id  SMALLINT UNSIGNED NOT NULL,
  date_from     DATE NOT NULL,
  date_to       DATE NOT NULL,          -- включительно
  dow_mask      TINYINT UNSIGNED NOT NULL DEFAULT 127, -- дни недели (бит 0=Пн..6=Вс)
  price         DECIMAL(10,2) NOT NULL, -- цена за ночь для этого размещения
  -- происхождение записи (см. 04_integration.sql) -------------------
  source        ENUM('manual','pms','channel_manager','import') NOT NULL DEFAULT 'manual',
  connection_id INT UNSIGNED NULL,      -- какое подключение прислало (integration)
  external_rev  BIGINT UNSIGNED NULL,   -- версия/seq источника для last-write-wins
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_price_lookup (rate_plan_id, occupancy_id, date_from, date_to),
  KEY idx_price_source (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. ОГРАНИЧЕНИЯ (restrictions) — тоже периодами.
--    min/max stay, closed-to-arrival/departure, stop-sale, окна брони.
-- ---------------------------------------------------------------------

CREATE TABLE rate_restrictions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_plan_id     INT UNSIGNED NOT NULL,
  date_from        DATE NOT NULL,
  date_to          DATE NOT NULL,
  dow_mask         TINYINT UNSIGNED NOT NULL DEFAULT 127,
  min_stay         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  max_stay         TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = без ограничения
  closed_to_arrival    TINYINT(1) NOT NULL DEFAULT 0,     -- CTA
  closed_to_departure  TINYINT(1) NOT NULL DEFAULT 0,     -- CTD
  stop_sell        TINYINT(1) NOT NULL DEFAULT 0,
  min_advance_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- не раньше чем за N дней
  max_advance_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- не позже чем за N дней (0=нет)
  release_days     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- срок реализации аллотмента
  -- происхождение записи --------------------------------------------
  source        ENUM('manual','pms','channel_manager','import') NOT NULL DEFAULT 'manual',
  connection_id INT UNSIGNED NULL,
  external_rev  BIGINT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_restr_lookup (rate_plan_id, date_from, date_to),
  KEY idx_restr_source (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. АЛЛОТМЕНТЫ / НАЛИЧИЕ (на уровне КАТЕГОРИИ room)
--    Контракт на количество номеров — периодами. Наличие считается
--    посуточно как allotment - booked - blocked. Хранится в отдельной
--    посуточной таблице, т.к. меняется при каждом бронировании.
--    Все тарифы категории делят ОДИН счётчик наличия room.
-- ---------------------------------------------------------------------

-- Контракт аллотмента (периодами) — на категорию
CREATE TABLE allotment_contracts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id  INT UNSIGNED NOT NULL,   -- аллотмент общий на категорию
  date_from     DATE NOT NULL,
  date_to       DATE NOT NULL,
  dow_mask      TINYINT UNSIGNED NOT NULL DEFAULT 127,
  units         SMALLINT UNSIGNED NOT NULL,  -- продаваемых номеров (<= total_rooms)
  release_days  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- происхождение записи --------------------------------------------
  source        ENUM('manual','pms','channel_manager','import') NOT NULL DEFAULT 'manual',
  connection_id INT UNSIGNED NULL,
  external_rev  BIGINT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_allot_lookup (room_id, date_from, date_to),
  KEY idx_allot_source (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Посуточное фактическое наличие КАТЕГОРИИ (пересобирается/декрементится).
-- available = LEAST(allotment, total_rooms) - booked - blocked
-- Единственный авторитетный счётчик; бронь любого тарифа категории
-- инкрементит booked именно здесь (атомарно, в транзакции).
CREATE TABLE room_availability (
  room_id  INT UNSIGNED NOT NULL,
  stay_date     DATE NOT NULL,
  allotment     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- контракт (может присылать CM/PMS)
  booked        SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- подтверждённые брони по всем тарифам категории
  blocked       SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- ручной stop/овербукинг-буфер
  -- признак «наличием управляет внешняя система» (free-sell/managed) -
  managed_by    ENUM('internal','pms','channel_manager') NOT NULL DEFAULT 'internal',
  connection_id INT UNSIGNED NULL,
  external_rev  BIGINT UNSIGNED NULL,   -- seq/timestamp источника (last-write-wins)
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (room_id, stay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 8. ПОЛИТИКИ ОТМЕНЫ
-- ---------------------------------------------------------------------

CREATE TABLE cancellation_policies (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id      INT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  is_refundable TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_canc_hotel (hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cancellation_rules (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy_id     INT UNSIGNED NOT NULL,
  days_before   SMALLINT UNSIGNED NOT NULL,  -- за сколько дней до заезда
  charge_type   ENUM('percent','nights','fixed') NOT NULL,
  charge_value  DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_canc_rule (policy_id, days_before)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 9. EXTRAS / ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ И СБОРЫ
--    Обязательные per-night extras сворачиваются в цену кэша,
--    опциональные считаются PHP на этапе оформления.
-- ---------------------------------------------------------------------

CREATE TABLE extras (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id      INT UNSIGNED NOT NULL,
  code          VARCHAR(40) NOT NULL,
  name          VARCHAR(160) NOT NULL,
  charge_type   ENUM('per_stay','per_night','per_person','per_person_night') NOT NULL,
  price         DECIMAL(10,2) NOT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'EUR',
  is_mandatory  TINYINT(1) NOT NULL DEFAULT 0,
  scope         ENUM('hotel','room','rate_plan') NOT NULL DEFAULT 'hotel',
  PRIMARY KEY (id),
  KEY idx_extra_hotel (hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Привязка extras к конкретным тарифам (для scope='rate_plan')
CREATE TABLE rate_plan_extras (
  rate_plan_id  INT UNSIGNED NOT NULL,
  extra_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (rate_plan_id, extra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 11. ПИТАНИЕ КАК SUPPLEMENT (доплата за тип питания)
--     ОСНОВНОЙ путь — питание «вплавлено» в тариф (rate_plans.board_type_id)
--     и его цену: поиск остаётся быстрым (см. доку §6). Эта таблица — для
--     UPSELL: показать «RO + за завтрак +X» на странице отеля/оформлении.
--     В горячий поиск НЕ входит; PHP считает доплату на выбранных строках.
-- ---------------------------------------------------------------------
CREATE TABLE board_supplements (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id       INT UNSIGNED NOT NULL,           -- к какой категории
  board_type_id TINYINT UNSIGNED NOT NULL,       -- какой апгрейд питания
  date_from     DATE NOT NULL,
  date_to       DATE NOT NULL,
  charge_type   ENUM('per_person_night','per_night','per_person_stay') NOT NULL DEFAULT 'per_person_night',
  adult_price   DECIMAL(10,2) NOT NULL,
  child_price   DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency      CHAR(3) NOT NULL DEFAULT 'EUR',
  PRIMARY KEY (id),
  KEY idx_boardsup (room_id, board_type_id, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 12. КОНФИГУРАЦИЯ КОМНАТ И КРОВАТЕЙ (как у Booking.com)
--     room -> room_spaces (bedroom/livingroom/...) -> кровати в каждой.
--     Чисто описательные данные для карточки; в горячий поиск НЕ входят.
--     Вместимость по-прежнему считается по occupancy_options/room, но
--     сумма спальных мест здесь может её валидировать в PHP.
-- ---------------------------------------------------------------------
CREATE TABLE bed_types (
  id    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code  VARCHAR(24) NOT NULL,          -- single, double, king, queen, sofa, bunk, crib
  name  VARCHAR(80) NOT NULL,
  sleeps TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- сколько гостей вмещает
  PRIMARY KEY (id),
  UNIQUE KEY uq_bed_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Комнаты внутри номера (для многокомнатных: 1 bedroom + 1 livingroom)
CREATE TABLE room_spaces (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id   INT UNSIGNED NOT NULL,
  space_type ENUM('bedroom','livingroom','other') NOT NULL,
  idx       TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- Bedroom 1, Bedroom 2 ...
  name      VARCHAR(80) NULL,
  PRIMARY KEY (id),
  KEY idx_space_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кровати в конкретной комнате номера
CREATE TABLE room_space_beds (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  space_id    INT UNSIGNED NOT NULL,
  bed_type_id TINYINT UNSIGNED NOT NULL,
  qty         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_bed_space (space_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;
