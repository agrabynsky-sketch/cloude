-- =====================================================================
-- Полная сборка поискового кэша внутри ClickHouse, шаг 1 из 3: staging.
-- ClickHouse сам читает MySQL (named collection unit_mysql, см. config.d/unit_mysql.xml) и готовит:
--   stg_*          — копии нужных таблиц MySQL (только активные записи и даты горизонта);
--   hotels_search_stg_rates_rooms_ext     — статические атрибуты рум-рейта (отель, тариф, номер, вместимость, правила цены
--                                           на 1..8 гостей, детская политика отеля);
--   hotels_search_stay_new — пустая таблица под новую сборку.
-- Шаг 2: 03b_build_chunk.sql (сборка пачками отелей), шаг 3: 04_full_load_swap.sql (атомарная подмена).
-- Все три шага запускает clickhouse/full_load.sh под той же блокировкой, что и PHP-воркер.
-- Правила расчёта — те же, что в Search_Sync_Builder (library/Search/Sync/Builder.php).
-- Горизонт: [today(); today() + 365] по часовому поясу сервера ClickHouse — он должен совпадать с PHP.
-- =====================================================================

SET external_table_functions_use_nulls = 1;
SET mysql_datatypes_support_level = 'decimal';   -- DECIMAL из MySQL как Decimal, а не String
SET join_use_nulls = 1;
SET max_memory_usage = 8000000000;
-- дедупликация (GROUP BY по ~всем строкам цен) сбрасывается на диск, если не помещается в память
SET max_bytes_before_external_group_by = 1000000000;

-- ---------- 1. staging: копии нужных таблиц MySQL (только активные записи и только даты горизонта)
DROP TABLE IF EXISTS unit_search.hotels_search_stg_hotels;
CREATE TABLE unit_search.hotels_search_stg_hotels ENGINE = Memory AS
SELECT id, stars, id_country, id_region, id_city, id_currency, allow_children, children_min_age
FROM mysql(unit_mysql, table = 'hotels') WHERE active = 1;

DROP TABLE IF EXISTS unit_search.hotels_search_stg_rooms;
CREATE TABLE unit_search.hotels_search_stg_rooms ENGINE = Memory AS
SELECT id, id_hotel, id_type, allotment, base_occupancy, max_occupancy, max_adults, max_children, max_infants, is_without_infants,
       extra_beds, cots, cots_and_extra_beds
FROM mysql(unit_mysql, table = 'hotels_rooms') WHERE active = 1;

DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates;
CREATE TABLE unit_search.hotels_search_stg_rates ENGINE = Memory AS
SELECT id, id_hotel, id_parent, id_board_type, id_cancel_policy, min_los, min_adv, derive_value, channel_mask, visibility, access_group_id
FROM mysql(unit_mysql, table = 'hotels_rates') WHERE active = 1;

-- рум-рейты, у которых активны номер, тариф и отель (и номер с тарифом из одного отеля)
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates_rooms;
CREATE TABLE unit_search.hotels_search_stg_rates_rooms ENGINE = Memory AS
SELECT rr.id AS id, rr.id_rate AS id_rate, rr.id_room AS id_room, rr.base_occupancy AS base_occupancy
FROM mysql(unit_mysql, table = 'hotels_rates_rooms') AS rr
INNER JOIN unit_search.hotels_search_stg_rooms AS ro ON ro.id = rr.id_room
INNER JOIN unit_search.hotels_search_stg_rates AS t ON t.id = rr.id_rate
INNER JOIN unit_search.hotels_search_stg_hotels AS h ON h.id = ro.id_hotel
WHERE t.id_hotel = ro.id_hotel;

-- при дублях (в MySQL нет UNIQUE) берётся строка с максимальным id — целиком, через argMax по кортежу
-- (argMax по отдельной Nullable-колонке пропустил бы NULL и взял значение из другой строки)
DROP TABLE IF EXISTS unit_search.hotels_search_stg_prices;
CREATE TABLE unit_search.hotels_search_stg_prices ENGINE = MergeTree ORDER BY (id_rate_room, date) AS
SELECT id_rate_room, date,
       t.1 AS price, t.2 AS derive_type, t.3 AS derive_value, t.4 AS min_los, t.5 AS max_los,
       t.6 AS min_adv, t.7 AS max_adv, t.8 AS cta, t.9 AS ctd, t.10 AS active
FROM
(
    SELECT id_rate_room, assumeNotNull(date) AS date,
           argMax((price, derive_type, derive_value, min_los, max_los, min_adv, max_adv, cta, ctd, active), id) AS t
    FROM mysql(unit_mysql, table = 'hotels_rates_prices')
    WHERE date >= toString(today()) AND date <= toString(today() + 365)
    GROUP BY id_rate_room, date
);

DROP TABLE IF EXISTS unit_search.hotels_search_stg_availability;
CREATE TABLE unit_search.hotels_search_stg_availability ENGINE = MergeTree ORDER BY (id_room, date) AS
SELECT id_room, date, t.1 AS allotment, t.2 AS net_booked, t.3 AS active
FROM
(
    SELECT id_room, assumeNotNull(date) AS date, argMax((allotment, net_booked, active), id) AS t
    FROM mysql(unit_mysql, table = 'hotels_rooms_availability')
    WHERE date >= toString(today()) AND date <= toString(today() + 365)
    GROUP BY id_room, date
);

-- окно «цены для другого числа гостей»: по рум-рейту массивы гостей, active, типа (0 %, 1 сумма) и значения × 100
-- (процент -> базисные пункты, сумма -> копейки); дубли (rate_room, guests): максимальный id
DROP TABLE IF EXISTS unit_search.hotels_search_stg_occupancy;
CREATE TABLE unit_search.hotels_search_stg_occupancy ENGINE = Memory AS
SELECT id_rate_room,
       groupArray(guests) AS g_list,
       groupArray(t.1) AS g_active,
       groupArray(toUInt8(t.2 = 1)) AS g_type,
       groupArray(toInt64(round(t.3 * 100))) AS g_val
FROM
(
    SELECT id_rate_room, guests, argMax((active, amount_type, amount), id) AS t
    FROM mysql(unit_mysql, table = 'hotels_rates_occupancy')
    GROUP BY id_rate_room, guests
)
GROUP BY id_rate_room;

-- дневные цены на число гостей: одна строка на (рум-рейт, дата), массивы гостей и цен в копейках;
-- при дублях (rate_room, date, guests) берётся строка с максимальным id; price = 0 оставляем — это "не задано"
DROP TABLE IF EXISTS unit_search.hotels_search_stg_occupancy_daily;
CREATE TABLE unit_search.hotels_search_stg_occupancy_daily ENGINE = MergeTree ORDER BY (id_rate_room, date) AS
SELECT id_rate_room, date, groupArray(guests) AS dg, groupArray(price_minor) AS dp
FROM
(
    SELECT id_rate_room, assumeNotNull(date) AS date, toUInt8(guests) AS guests,
           argMax(toInt64(price * 100), id) AS price_minor
    FROM mysql(unit_mysql, table = 'hotels_rates_occupancy_daily')
    WHERE date >= toString(today()) AND date <= toString(today() + 365)
    GROUP BY id_rate_room, date, guests
)
GROUP BY id_rate_room, date;

-- детская политика отеля: активные группы по порядку (age_from, id) -> номер группы j; value × 100 (фикс — копейки, процент — б.п.)
DROP TABLE IF EXISTS unit_search.hotels_search_stg_hotel_children;
CREATE TABLE unit_search.hotels_search_stg_hotel_children ENGINE = Memory AS
SELECT id_hotel, arraySort(x -> (x.2, x.1), groupArray((g_id, g_from, g_to, g_type, g_value))) AS grp
FROM
(
    SELECT toUInt32(id) AS g_id, toUInt32(id_hotel) AS id_hotel, toUInt8(age_from) AS g_from, toUInt8(age_to) AS g_to,
           toUInt8(if(price_type BETWEEN 1 AND 5, price_type, 1)) AS g_type,
           toUInt32(least(4294967295, greatest(0, toInt64(price_value * 100)))) AS g_value
    FROM mysql(unit_mysql, table = 'hotels_children_groups') WHERE active = 1
)
GROUP BY id_hotel;

-- ---------- 2. статические атрибуты рум-рейта (одна строка на рум-рейт)
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates_rooms_ext;
CREATE TABLE unit_search.hotels_search_stg_rates_rooms_ext ENGINE = Memory AS
SELECT
    rr.id AS id_rate_room, rr.id_room AS id_room, rr.id_rate AS id_rate,
    toUInt32(ifNull(t.id_parent, 0)) AS id_parent,
    toUInt32(ifNull(prr.id, 0)) AS id_parent_rate_room,
    toUInt32(ro.id_hotel) AS id_hotel, h.id_country AS id_country, h.id_region AS id_region, h.id_city AS id_city,
    h.stars AS stars, h.id_currency AS id_currency,
    toUInt8(ifNull(t.id_board_type, 0)) AS id_board_type, toUInt32(ifNull(t.id_cancel_policy, 0)) AS id_cancel_policy,
    toUInt8(ifNull(t.id_cancel_policy, 0) != 0) AS refundable,
    toUInt32(ifNull(t.channel_mask, 0)) AS channel_mask, toUInt8(ifNull(t.visibility, '') = 'public') AS is_public,
    toUInt32(ifNull(t.access_group_id, 0)) AS id_access_group,
    toInt64(ifNull(t.min_los, 0)) AS rate_min_los, toInt64(ifNull(t.min_adv, 0)) AS rate_min_adv,
    toInt64(ifNull(t.derive_value, 0)) AS rate_derive_value,
    toUInt32(ifNull(ro.id_type, 0)) AS id_room_type, toInt64(ifNull(ro.allotment, 0)) AS room_allotment,
    -- базовое размещение: своё у рум-рейта, иначе номера; у производного — как у родителя
    toInt64(greatest(1, if(id_parent > 0, ifNull(prr.base_occupancy, ifNull(ro.base_occupancy, 0)),
                                          ifNull(rr.base_occupancy, ifNull(ro.base_occupancy, 0))))) AS base_occ,
    toInt64(if(ifNull(ro.max_adults, 0) > 0, ro.max_adults, greatest(ifNull(ro.max_occupancy, 0), base_occ))) AS max_adults_eff,
    toInt64(if(ifNull(ro.max_occupancy, 0) > 0, ro.max_occupancy, max_adults_eff + ifNull(ro.max_children, 0))) AS max_occupancy_eff,
    least(8, max_adults_eff, max_occupancy_eff) AS guests_limit,
    -- правило цены за g гостей (g = 1..8): (режим, тип, значение); режим 0 — не продаётся, 1 — своя строка окна (нет строки у
    -- самостоятельного тарифа = база) от базовой цены этого рум-рейта, 2 — производный без своей строки: как у родителя
    -- (тип и значение — строка окна родителя, нет строки = его база)
    arrayMap(gg -> multiIf(
        gg > guests_limit, (toUInt8(0), toUInt8(0), toInt64(0)),
        has(o.g_list, gg), if(o.g_active[indexOf(o.g_list, gg)] = 1,
            (toUInt8(1), o.g_type[indexOf(o.g_list, gg)], o.g_val[indexOf(o.g_list, gg)]), (toUInt8(0), toUInt8(0), toInt64(0))),
        id_parent > 0, if(has(po.g_list, gg),
            if(po.g_active[indexOf(po.g_list, gg)] = 1, (toUInt8(2), po.g_type[indexOf(po.g_list, gg)], po.g_val[indexOf(po.g_list, gg)]),
               (toUInt8(0), toUInt8(0), toInt64(0))),
            (toUInt8(2), toUInt8(0), toInt64(0))),
        (toUInt8(1), toUInt8(0), toInt64(0))), range(1, 9)) AS rules,
    toUInt16(arraySum(arrayMap((r, gg) -> if(r.1 > 0, bitShiftLeft(1, gg), 0), rules, range(1, 9)))) AS gmask,
    toUInt8(arrayMax(arrayMap((r, gg) -> if(r.1 > 0, gg, 0), rules, range(1, 9)))) AS max_guests,
    toUInt8(least(255, base_occ)) AS base_occupancy,
    toUInt8(least(255, greatest(0, ifNull(ro.max_children, 0)))) AS max_children,
    toUInt8(least(255, max_occupancy_eff)) AS max_occupancy,
    toUInt8(if(ro.max_infants IS NULL, 255, least(254, greatest(0, ro.max_infants)))) AS max_infants,
    toUInt8(ifNull(ro.is_without_infants, 0) != 0) AS infants_excluded,
    toUInt8(if(h.allow_children IS NOT NULL AND h.allow_children = 0, 255, least(254, greatest(0, ifNull(h.children_min_age, 0))))) AS children_min_age,
    -- детская политика отеля: возраст -> номер группы (пересечение: больший id), 0 = взрослый; тип и значение цены группы
    arrayMap(a -> toUInt8(indexOf(arrayMap(x -> x.1, hc.grp), arrayMax(arrayMap(x -> if(x.2 <= a AND a <= x.3, x.1, toUInt32(0)), hc.grp)))),
        range(18)) AS age_group,
    arrayMap(x -> x.4, hc.grp) AS child_type,
    arrayMap(x -> x.5, hc.grp) AS child_value,
    toUInt8(least(255, greatest(0, ifNull(ro.extra_beds, 0)))) AS extra_beds,
    toUInt8(least(255, greatest(0, ifNull(ro.cots, 0)))) AS cots,
    toUInt8(ifNull(ro.cots_and_extra_beds, 0) != 0) AS cots_and_extra_beds
FROM unit_search.hotels_search_stg_rates_rooms AS rr
INNER JOIN unit_search.hotels_search_stg_rooms AS ro ON ro.id = rr.id_room
INNER JOIN unit_search.hotels_search_stg_rates AS t ON t.id = rr.id_rate
INNER JOIN unit_search.hotels_search_stg_hotels AS h ON h.id = ro.id_hotel
LEFT JOIN unit_search.hotels_search_stg_rates_rooms AS prr ON prr.id_rate = t.id_parent AND prr.id_room = rr.id_room
LEFT JOIN unit_search.hotels_search_stg_occupancy AS o ON o.id_rate_room = rr.id
LEFT JOIN unit_search.hotels_search_stg_occupancy AS po ON po.id_rate_room = prr.id
LEFT JOIN unit_search.hotels_search_stg_hotel_children AS hc ON hc.id_hotel = toUInt32(ro.id_hotel);

-- ---------- 3. пустая таблица под новую сборку
DROP TABLE IF EXISTS unit_search.hotels_search_stay_new;
CREATE TABLE unit_search.hotels_search_stay_new AS unit_search.hotels_search_stay;

SELECT 'staged' AS status, (SELECT count() FROM unit_search.hotels_search_stg_rates_rooms_ext) AS rate_rooms,
       (SELECT count() FROM unit_search.hotels_search_stg_prices) AS price_rows, (SELECT count() FROM unit_search.hotels_search_stg_availability) AS avail_rows,
       (SELECT sum(length(dg)) FROM unit_search.hotels_search_stg_occupancy_daily) AS occupancy_daily_rows,
       (SELECT sum(length(grp)) FROM unit_search.hotels_search_stg_hotel_children) AS children_groups;
