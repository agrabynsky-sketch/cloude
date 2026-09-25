-- =====================================================================
-- Полная сборка поискового кэша внутри ClickHouse, шаг 1 из 3: staging.
-- ClickHouse сам читает MySQL (named collection unit_mysql, см. config.d/unit_mysql.xml) и готовит:
--   stg_*          — копии нужных таблиц MySQL (только активные записи и даты горизонта);
--   stg_rr_ext     — статические атрибуты рум-рейта (отель, тариф, номер, множители цены на 1..8 гостей);
--   search_stay_new — пустая таблица под новую сборку.
-- Шаг 2: 03b_build_chunk.sql (сборка пачками отелей), шаг 3: 04_full_load_swap.sql (атомарная подмена).
-- Все три шага запускает clickhouse/full_load.sh под той же блокировкой, что и PHP-воркер.
-- Правила расчёта — те же, что в Search_Sync_Builder (library/Search/Sync/Builder.php).
-- Горизонт: [today(); today() + 365] по часовому поясу сервера ClickHouse — он должен совпадать с PHP.
-- =====================================================================

SET external_table_functions_use_nulls = 1;
SET mysql_datatypes_support_level = 'decimal';   -- DECIMAL из MySQL как Decimal, а не String
SET join_use_nulls = 1;
SET max_memory_usage = 8000000000;

-- ---------- 1. staging: копии нужных таблиц MySQL (только активные записи и только даты горизонта)
DROP TABLE IF EXISTS unit_search.stg_hotels;
CREATE TABLE unit_search.stg_hotels ENGINE = Memory AS
SELECT id, stars, id_country, id_region, id_city, id_currency
FROM mysql(unit_mysql, table = 'hotels') WHERE active = 1;

DROP TABLE IF EXISTS unit_search.stg_rooms;
CREATE TABLE unit_search.stg_rooms ENGINE = Memory AS
SELECT id, id_hotel, id_type, allotment, base_occupancy, max_occupancy, pricing_model
FROM mysql(unit_mysql, table = 'hotels_rooms') WHERE active = 1;

DROP TABLE IF EXISTS unit_search.stg_rates;
CREATE TABLE unit_search.stg_rates ENGINE = Memory AS
SELECT id, id_hotel, id_parent, id_board_type, id_cancel_policy, min_los, min_adv, derive_value, channel_mask, visibility, access_group_id
FROM mysql(unit_mysql, table = 'hotels_rates') WHERE active = 1;

-- рум-рейты, у которых активны номер, тариф и отель (и номер с тарифом из одного отеля)
DROP TABLE IF EXISTS unit_search.stg_rr;
CREATE TABLE unit_search.stg_rr ENGINE = Memory AS
SELECT rr.id AS id, rr.id_rate AS id_rate, rr.id_room AS id_room
FROM mysql(unit_mysql, table = 'hotels_rates_rooms') AS rr
INNER JOIN unit_search.stg_rooms AS ro ON ro.id = rr.id_room
INNER JOIN unit_search.stg_rates AS t ON t.id = rr.id_rate
INNER JOIN unit_search.stg_hotels AS h ON h.id = ro.id_hotel
WHERE t.id_hotel = ro.id_hotel;

DROP TABLE IF EXISTS unit_search.stg_prices;
CREATE TABLE unit_search.stg_prices ENGINE = MergeTree ORDER BY (id_rate_room, date) AS
SELECT id_rate_room, assumeNotNull(date) AS date, price, derive_type, derive_value, min_los, max_los, min_adv, max_adv, cta, ctd, active
FROM mysql(unit_mysql, table = 'hotels_rates_prices')
WHERE date >= toString(today()) AND date <= toString(today() + 365);

DROP TABLE IF EXISTS unit_search.stg_avail;
CREATE TABLE unit_search.stg_avail ENGINE = MergeTree ORDER BY (id_room, date) AS
SELECT id_room, assumeNotNull(date) AS date, allotment, net_booked, active
FROM mysql(unit_mysql, table = 'hotels_rooms_availability')
WHERE date >= toString(today()) AND date <= toString(today() + 365);

-- надбавки за гостей, развёрнутые в колонки: m{g} = множитель в базисных пунктах (10000 = база), 0 = не продаётся
DROP TABLE IF EXISTS unit_search.stg_occ;
CREATE TABLE unit_search.stg_occ ENGINE = Memory AS
SELECT id_rate_room,
       groupArray(guests) AS g_list,
       groupArray(active) AS g_active,
       groupArray(toInt64(round(amount * 100))) AS g_bp
FROM mysql(unit_mysql, table = 'hotels_rates_occupancy')
GROUP BY id_rate_room;

-- ---------- 2. статические атрибуты рум-рейта (одна строка на рум-рейт)
DROP TABLE IF EXISTS unit_search.stg_rr_ext;
CREATE TABLE unit_search.stg_rr_ext ENGINE = Memory AS
SELECT
    rr.id AS rate_room_id, rr.id_room AS room_id, rr.id_rate AS rate_id,
    toUInt32(ifNull(t.id_parent, 0)) AS parent_rate_id,
    toUInt32(ifNull(prr.id, 0)) AS parent_rr_id,
    toUInt32(ro.id_hotel) AS hotel_id, h.id_country AS country_id, h.id_region AS region_id, h.id_city AS city_id,
    h.stars AS stars, h.id_currency AS currency_id,
    toUInt8(ifNull(t.id_board_type, 0)) AS board_id, toUInt8(ifNull(t.id_cancel_policy, 0) != 0) AS refundable,
    toUInt32(ifNull(t.channel_mask, 0)) AS channel_mask, toUInt8(ifNull(t.visibility, '') = 'public') AS is_public,
    toUInt32(ifNull(t.access_group_id, 0)) AS access_group_id,
    toInt64(ifNull(t.min_los, 0)) AS rate_min_los, toInt64(ifNull(t.min_adv, 0)) AS rate_min_adv,
    toInt64(ifNull(t.derive_value, 0)) AS rate_derive_value,
    toUInt32(ifNull(ro.id_type, 0)) AS room_type_id, toInt64(ifNull(ro.allotment, 0)) AS room_allotment,
    toUInt8(least(8, greatest(ifNull(ro.max_occupancy, 0), ifNull(ro.base_occupancy, 0), 1))) AS max_guests,
    -- множитель цены на g гостей в базисных пунктах: 0 = не продаётся, 10000 = базовая цена;
    -- pricing_model = 2 -> строка hotels_rates_occupancy (active = 0 выключает это число гостей)
    arrayMap(gg -> if(gg > max_guests, toInt64(0),
        if(ifNull(ro.pricing_model, 1) = 2 AND has(o.g_list, gg),
            if(o.g_active[indexOf(o.g_list, gg)] = 1, 10000 + o.g_bp[indexOf(o.g_list, gg)], toInt64(0)),
            toInt64(10000))), range(1, 9)) AS mult,
    toUInt16(arraySum(arrayMap((m, gg) -> if(m > 0, bitShiftLeft(1, gg), 0), mult, range(1, 9)))) AS gmask
FROM unit_search.stg_rr AS rr
INNER JOIN unit_search.stg_rooms AS ro ON ro.id = rr.id_room
INNER JOIN unit_search.stg_rates AS t ON t.id = rr.id_rate
INNER JOIN unit_search.stg_hotels AS h ON h.id = ro.id_hotel
LEFT JOIN unit_search.stg_rr AS prr ON prr.id_rate = t.id_parent AND prr.id_room = rr.id_room
LEFT JOIN unit_search.stg_occ AS o ON o.id_rate_room = rr.id;

-- ---------- 3. пустая таблица под новую сборку
DROP TABLE IF EXISTS unit_search.search_stay_new;
CREATE TABLE unit_search.search_stay_new AS unit_search.search_stay;

SELECT 'staged' AS status, (SELECT count() FROM unit_search.stg_rr_ext) AS rate_rooms,
       (SELECT count() FROM unit_search.stg_prices) AS price_rows, (SELECT count() FROM unit_search.stg_avail) AS avail_rows;
