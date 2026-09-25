-- =====================================================================
-- Полная сборка, шаг 2 из 3: строки hotels_search_stay_new для пачки отелей id_hotel % {chunks} = {chunk}.
-- Запускается из full_load.sh в цикле:
--   clickhouse-client --param_chunks=16 --param_chunk=0 --multiquery < 03b_build_chunk.sql
-- Пачки нужны, чтобы память не зависела от размера базы (правую сторону JOIN фильтруем по пачке).
-- =====================================================================
SET join_use_nulls = 1;
SET max_bytes_before_external_sort = 2000000000;

INSERT INTO unit_search.hotels_search_stay_new
SELECT
    d, id_hotel, id_rate_room, id_room, id_rate, id_parent,
    id_country, id_region, id_city, stars, id_currency,
    id_board_type, id_cancel_policy, refundable, channel_mask, is_public, id_access_group,
    id_room_type, max_guests, gmask,
    toUInt64(sum(if(sell, gp[1], 0)) OVER w) AS c1,
    toUInt64(sum(if(sell, gp[2], 0)) OVER w) AS c2,
    toUInt64(sum(if(sell, gp[3], 0)) OVER w) AS c3,
    toUInt64(sum(if(sell, gp[4], 0)) OVER w) AS c4,
    toUInt64(sum(if(sell, gp[5], 0)) OVER w) AS c5,
    toUInt64(sum(if(sell, gp[6], 0)) OVER w) AS c6,
    toUInt64(sum(if(sell, gp[7], 0)) OVER w) AS c7,
    toUInt64(sum(if(sell, gp[8], 0)) OVER w) AS c8,
    toUInt16(sum(toUInt32(sell)) OVER w) AS k,
    toUInt16(if(sell, least(free, 65535), 0)) AS avail,
    cta, ctd, min_los, max_los, min_adv, max_adv,
    toUInt64(toUnixTimestamp64Micro(now64(6))) AS ver,
    0 AS is_deleted
FROM
(
    SELECT
        g.d AS d, g.id_hotel AS id_hotel, g.id_rate_room AS id_rate_room, g.id_room AS id_room, g.id_rate AS id_rate,
        g.id_parent AS id_parent, g.id_country AS id_country, g.id_region AS id_region, g.id_city AS id_city,
        g.stars AS stars, g.id_currency AS id_currency, g.id_board_type AS id_board_type,
        g.id_cancel_policy AS id_cancel_policy, g.refundable AS refundable,
        g.channel_mask AS channel_mask, g.is_public AS is_public, g.id_access_group AS id_access_group,
        g.id_room_type AS id_room_type, g.max_guests AS max_guests, g.gmask AS gmask,
        -- базовая цена ночи в копейках (NULL = не продаётся)
        multiIf(
            g.id_parent = 0,
                if(p.id_rate_room IS NOT NULL AND p.active = 1 AND p.price IS NOT NULL, toInt64(p.price * 100), NULL),
            p.id_rate_room IS NOT NULL AND p.active = 0, NULL,
            p.id_rate_room IS NOT NULL AND p.price IS NOT NULL AND p.derive_type = 0, toInt64(p.price * 100),
            pp.id_rate_room IS NOT NULL AND pp.active = 1 AND pp.price IS NOT NULL,
                intDiv(toInt64(pp.price * 100) * (100 + if(p.id_rate_room IS NOT NULL AND p.derive_type = 4,
                    toInt64(p.derive_value), -g.rate_derive_value)) + 50, 100),
            NULL) AS base,
        toInt64(ifNull(a.allotment, g.room_allotment)) - toInt64(ifNull(a.net_booked, 0)) AS free,
        toUInt8(ifNull(ifNull(a.active, 1) = 1 AND free > 0 AND base IS NOT NULL AND base > 0, 0)) AS sell,
        -- цены на 1..8 гостей (gp[g]): дневная цена hotels_rates_occupancy_daily (pricing_model = 2, price > 0),
        -- иначе база × множитель надбавки
        arrayMap((m, gg) -> if(m = 0 OR base IS NULL, toInt64(0),
            if(g.occ_based = 1 AND has(od.dg, gg) AND od.dp[indexOf(od.dg, gg)] > 0, od.dp[indexOf(od.dg, gg)],
                if(m = 10000, assumeNotNull(base), greatest(toInt64(0), intDiv(assumeNotNull(base) * m + 5000, 10000))))),
            g.mult, arrayMap(x -> toUInt8(x), range(1, 9))) AS gp,
        -- ограничения даты: своя строка цены, иначе значения тарифа
        toUInt8(ifNull(p.cta, 0) > 0) AS cta,
        toUInt8(ifNull(p.ctd, 0) > 0) AS ctd,
        toUInt16(least(65535, greatest(1, if(p.id_rate_room IS NOT NULL AND p.min_los IS NOT NULL,
            toInt64(p.min_los), g.rate_min_los)))) AS min_los,
        toUInt16(if(ifNull(p.max_los, 0) > 0, least(65535, ifNull(p.max_los, 0)), 999)) AS max_los,
        toUInt16(least(65535, greatest(0, if(p.id_rate_room IS NOT NULL AND p.min_adv IS NOT NULL,
            toInt64(p.min_adv), g.rate_min_adv)))) AS min_adv,
        toUInt16(if(ifNull(p.max_adv, 0) > 0, least(65535, ifNull(p.max_adv, 0)), 9999)) AS max_adv
    FROM
    (
        SELECT *, today() + arrayJoin(range(366)) AS d
        FROM unit_search.hotels_search_stg_rates_rooms_ext
        WHERE id_hotel % {chunks:UInt32} = {chunk:UInt32}
    ) AS g
    LEFT JOIN
    (
        SELECT * FROM unit_search.hotels_search_stg_prices WHERE id_rate_room IN
            (SELECT id_rate_room FROM unit_search.hotels_search_stg_rates_rooms_ext WHERE id_hotel % {chunks:UInt32} = {chunk:UInt32})
    ) AS p ON p.id_rate_room = g.id_rate_room AND p.date = g.d
    LEFT JOIN
    (
        SELECT * FROM unit_search.hotels_search_stg_prices WHERE id_rate_room IN
            (SELECT id_parent_rate_room FROM unit_search.hotels_search_stg_rates_rooms_ext WHERE id_hotel % {chunks:UInt32} = {chunk:UInt32})
    ) AS pp ON pp.id_rate_room = g.id_parent_rate_room AND pp.date = g.d
    LEFT JOIN
    (
        SELECT * FROM unit_search.hotels_search_stg_availability WHERE id_room IN
            (SELECT id_room FROM unit_search.hotels_search_stg_rates_rooms_ext WHERE id_hotel % {chunks:UInt32} = {chunk:UInt32})
    ) AS a ON a.id_room = g.id_room AND a.date = g.d
    LEFT JOIN
    (
        SELECT * FROM unit_search.hotels_search_stg_occupancy_daily WHERE id_rate_room IN
            (SELECT id_rate_room FROM unit_search.hotels_search_stg_rates_rooms_ext WHERE id_hotel % {chunks:UInt32} = {chunk:UInt32} AND occ_based = 1)
    ) AS od ON od.id_rate_room = g.id_rate_room AND od.date = g.d
)
WINDOW w AS (PARTITION BY id_rate_room ORDER BY d ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING);
