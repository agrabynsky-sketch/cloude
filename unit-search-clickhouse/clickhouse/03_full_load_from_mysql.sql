-- =====================================================================
-- Полная сборка поискового кэша внутри ClickHouse, шаг 1 из 3: staging.
-- ClickHouse сам читает MySQL (named collection unit_mysql, см. config.d/unit_mysql.xml) и готовит:
--   stg_*          — копии нужных таблиц MySQL (только активные записи и даты горизонта);
--   hotels_search_stg_rates_rooms_ext     — статические атрибуты рум-рейта (отель, тариф, номер, места, множители цены на 1..8 взрослых,
--                                           детские группы тарифа);
--   hotels_search_stg_children_days       — исключения детских цен, развёрнутые по дням: (тариф, дата) -> правила групп;
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
SELECT id, stars, id_country, id_region, id_city, id_currency, allow_children, children_min_age, extra_bed_adult_price
FROM mysql(unit_mysql, table = 'hotels') WHERE active = 1;

DROP TABLE IF EXISTS unit_search.hotels_search_stg_rooms;
CREATE TABLE unit_search.hotels_search_stg_rooms ENGINE = Memory AS
SELECT id, id_hotel, id_type, allotment, base_occupancy, max_occupancy, max_adults, max_children, is_without_infants, pricing_model,
       places_regular, extra_beds, share_slots
FROM mysql(unit_mysql, table = 'hotels_rooms') WHERE active = 1;

DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates;
CREATE TABLE unit_search.hotels_search_stg_rates ENGINE = Memory AS
SELECT id, id_hotel, id_parent, id_board_type, id_cancel_policy, min_los, min_adv, derive_value, channel_mask, visibility, access_group_id
FROM mysql(unit_mysql, table = 'hotels_rates') WHERE active = 1;

-- рум-рейты, у которых активны номер, тариф и отель (и номер с тарифом из одного отеля)
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates_rooms;
CREATE TABLE unit_search.hotels_search_stg_rates_rooms ENGINE = Memory AS
SELECT rr.id AS id, rr.id_rate AS id_rate, rr.id_room AS id_room
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

-- надбавки за гостей, развёрнутые в колонки: m{g} = множитель в базисных пунктах (10000 = база), 0 = не продаётся
DROP TABLE IF EXISTS unit_search.hotels_search_stg_occupancy;
CREATE TABLE unit_search.hotels_search_stg_occupancy ENGINE = Memory AS
SELECT id_rate_room,
       groupArray(guests) AS g_list,
       groupArray(t.1) AS g_active,
       groupArray(toInt64(round(t.2 * 100))) AS g_bp
FROM
(
    SELECT id_rate_room, guests, argMax((active, amount), id) AS t      -- дубли (rate_room, guests): максимальный id
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

-- детская политика: активные группы (value × 100: фикс — копейки, процент — базисные пункты)
DROP TABLE IF EXISTS unit_search.hotels_search_stg_children_groups;
CREATE TABLE unit_search.hotels_search_stg_children_groups ENGINE = Memory AS
SELECT toUInt32(id) AS id, toUInt32(id_hotel) AS id_hotel, toUInt32(id_rate) AS id_rate, toUInt8(age_from) AS age_from, toUInt8(age_to) AS age_to,
       toUInt8(bed_type) AS bed_type, toInt64(bed_value * 100) AS bed_value, toUInt8(extra_type) AS extra_type, toInt64(extra_value * 100) AS extra_value
FROM mysql(unit_mysql, table = 'hotels_children_groups') WHERE active = 1;

DROP TABLE IF EXISTS unit_search.hotels_search_stg_children_prices;
CREATE TABLE unit_search.hotels_search_stg_children_prices ENGINE = Memory AS
SELECT toUInt32(id) AS id, toUInt32(id_hotel) AS id_hotel, toUInt32(id_group) AS id_group, toUInt32(id_rate) AS id_rate, date_from, date_to,
       toUInt8(bed_type) AS bed_type, toInt64(bed_value * 100) AS bed_value, toUInt8(extra_type) AS extra_type, toInt64(extra_value * 100) AS extra_value
FROM mysql(unit_mysql, table = 'hotels_children_prices') WHERE active = 1;

-- группы каждого тарифа: свои группы тарифа, если есть, иначе группы отеля; порядок (age_from, id) задаёт номер группы j
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rate_children;
CREATE TABLE unit_search.hotels_search_stg_rate_children ENGINE = Memory AS
SELECT id_rate, any(id_hotel) AS id_hotel,
       arraySort(x -> (x.2, x.1), groupArray((g_id, g_from, g_to, (bt, bv, et, ev)))) AS grp
FROM
(
    SELECT toUInt32(t.id) AS id_rate, toUInt32(t.id_hotel) AS id_hotel, g.id AS g_id, g.age_from AS g_from, g.age_to AS g_to,
           g.bed_type AS bt, g.bed_value AS bv, g.extra_type AS et, g.extra_value AS ev
    FROM unit_search.hotels_search_stg_rates AS t
    INNER JOIN unit_search.hotels_search_stg_children_groups AS g ON g.id_rate = toUInt32(t.id) AND g.id_hotel = toUInt32(t.id_hotel)
    UNION ALL
    SELECT toUInt32(t.id), toUInt32(t.id_hotel), g.id, g.age_from, g.age_to, g.bed_type, g.bed_value, g.extra_type, g.extra_value
    FROM unit_search.hotels_search_stg_rates AS t
    INNER JOIN unit_search.hotels_search_stg_children_groups AS g ON g.id_hotel = toUInt32(t.id_hotel) AND g.id_rate = 0
    WHERE (toUInt32(t.id), toUInt32(t.id_hotel)) NOT IN (SELECT id_rate, id_hotel FROM unit_search.hotels_search_stg_children_groups WHERE id_rate > 0)
)
GROUP BY id_rate;

-- исключения цен групп по дням горизонта: (тариф, дата) -> id групп и их правила (bed_type, bed_value, extra_type, extra_value);
-- на один день побеждает самое точное: тариф важнее «всех тарифов», с датами важнее «без дат», больший id
DROP TABLE IF EXISTS unit_search.hotels_search_stg_children_days;
CREATE TABLE unit_search.hotels_search_stg_children_days ENGINE = MergeTree ORDER BY (id_rate, d) AS
SELECT id_rate, d, groupArray(gid) AS gids, groupArray(rule) AS rules
FROM
(
    SELECT id_rate, gid, d, argMax(rule, prio) AS rule
    FROM
    (
        SELECT rg.id_rate AS id_rate, rg.gid AS gid,
               (p.bed_type, p.bed_value, p.extra_type, p.extra_value) AS rule,
               (toUInt8(p.id_rate = rg.id_rate), toUInt8(p.date_from IS NOT NULL OR p.date_to IS NOT NULL), p.id) AS prio,
               greatest(ifNull(p.date_from, today()), today()) AS dfrom,
               least(ifNull(p.date_to, today() + 365), today() + 365) AS dto,
               arrayJoin(arrayMap(x -> dfrom + x, range(toUInt32(greatest(0, toInt32(dto - dfrom) + 1))))) AS d
        FROM (SELECT id_rate, id_hotel, arrayJoin(grp).1 AS gid FROM unit_search.hotels_search_stg_rate_children) AS rg
        INNER JOIN unit_search.hotels_search_stg_children_prices AS p ON p.id_group = rg.gid
        WHERE (p.id_rate = 0 OR p.id_rate = rg.id_rate) AND p.id_hotel = rg.id_hotel
    )
    GROUP BY id_rate, gid, d
)
GROUP BY id_rate, d;

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
    toUInt8(ifNull(ro.pricing_model, 1) = 2) AS occ_based,
    -- места и лимиты (как в Search_Sync_Builder): R, E, S, лимит взрослых
    toInt64(greatest(1, ifNull(ro.base_occupancy, 0))) AS base_occ,
    toInt64(if(ifNull(ro.max_adults, 0) > 0, ro.max_adults, greatest(ifNull(ro.max_occupancy, 0), base_occ))) AS max_adults_eff,
    toInt64(if(ifNull(ro.places_regular, 0) > 0, ro.places_regular, greatest(base_occ, max_adults_eff))) AS places_regular_eff,
    toInt64(ifNull(ro.extra_beds, 0)) AS extra_beds_eff,
    toInt64(if(ifNull(ro.max_occupancy, 0) > 0, ro.max_occupancy, max_adults_eff + ifNull(ro.max_children, 0))) AS max_occupancy_eff,
    least(8, max_adults_eff, places_regular_eff + extra_beds_eff, max_occupancy_eff) AS adults_limit,
    -- per room: доплата за взрослого сверх base_occupancy (NULL = такие взрослые не принимаются)
    if(h.extra_bed_adult_price IS NULL, NULL, toInt64(h.extra_bed_adult_price * 100)) AS extra_adult,
    -- множитель цены на g взрослых в базисных пунктах: 0 = не продаётся, 10000 = базовая цена;
    -- per guest: строка hotels_rates_occupancy (active = 0 выключает g); per room: 10000, доплата добавляется при сборке
    arrayMap(gg -> if(gg > adults_limit, toInt64(0),
        if(ifNull(ro.pricing_model, 1) = 2,
            if(has(o.g_list, gg), if(o.g_active[indexOf(o.g_list, gg)] = 1, 10000 + o.g_bp[indexOf(o.g_list, gg)], toInt64(0)), toInt64(10000)),
            if(gg <= base_occ OR extra_adult IS NOT NULL, toInt64(10000), toInt64(0)))), range(1, 9)) AS mult,
    toUInt16(arraySum(arrayMap((m, gg) -> if(m > 0, bitShiftLeft(1, gg), 0), mult, range(1, 9)))) AS gmask,
    toUInt8(arrayMax(arrayMap((m, gg) -> if(m > 0, gg, 0), mult, range(1, 9)))) AS max_guests,
    toUInt8(least(255, places_regular_eff)) AS places_regular,
    toUInt8(least(255, extra_beds_eff)) AS extra_beds,
    toUInt8(least(255, ifNull(ro.share_slots, 0))) AS share_slots,
    toUInt8(least(255, greatest(0, ifNull(ro.max_children, 0)))) AS max_children,
    toUInt8(least(255, max_occupancy_eff)) AS max_occupancy,
    toUInt8(ifNull(ro.is_without_infants, 0) != 0) AS infants_excluded,
    toUInt8(if(h.allow_children IS NOT NULL AND h.allow_children = 0, 255, least(254, greatest(0, ifNull(h.children_min_age, 0))))) AS children_min_age,
    -- детские группы тарифа: id, правила по умолчанию; возраст -> номер группы (пересечение: больший id), 0 = взрослый
    arrayMap(x -> x.1, rc.grp) AS grp_ids,
    arrayMap(x -> x.4, rc.grp) AS grp_rules,
    arrayMap(a -> toUInt8(indexOf(grp_ids, arrayMax(arrayMap(x -> if(x.2 <= a AND a <= x.3, x.1, toUInt32(0)), rc.grp)))), range(18)) AS age_group
FROM unit_search.hotels_search_stg_rates_rooms AS rr
INNER JOIN unit_search.hotels_search_stg_rooms AS ro ON ro.id = rr.id_room
INNER JOIN unit_search.hotels_search_stg_rates AS t ON t.id = rr.id_rate
INNER JOIN unit_search.hotels_search_stg_hotels AS h ON h.id = ro.id_hotel
LEFT JOIN unit_search.hotels_search_stg_rates_rooms AS prr ON prr.id_rate = t.id_parent AND prr.id_room = rr.id_room
LEFT JOIN unit_search.hotels_search_stg_occupancy AS o ON o.id_rate_room = rr.id
LEFT JOIN unit_search.hotels_search_stg_rate_children AS rc ON rc.id_rate = toUInt32(rr.id_rate);

-- ---------- 3. пустая таблица под новую сборку
DROP TABLE IF EXISTS unit_search.hotels_search_stay_new;
CREATE TABLE unit_search.hotels_search_stay_new AS unit_search.hotels_search_stay;

SELECT 'staged' AS status, (SELECT count() FROM unit_search.hotels_search_stg_rates_rooms_ext) AS rate_rooms,
       (SELECT count() FROM unit_search.hotels_search_stg_prices) AS price_rows, (SELECT count() FROM unit_search.hotels_search_stg_availability) AS avail_rows,
       (SELECT sum(length(dg)) FROM unit_search.hotels_search_stg_occupancy_daily) AS occupancy_daily_rows,
       (SELECT count() FROM unit_search.hotels_search_stg_children_groups) AS children_groups,
       (SELECT count() FROM unit_search.hotels_search_stg_children_days) AS children_exception_days;
