-- =====================================================================
--  Unit.Travel — Инвенторная система
--  Файл 03: ЗАПРОСЫ — пересборка кэша и горячий поиск
--  СУБД: MySQL 5.6 / 5.7 (без CTE и оконных функций!)
-- =====================================================================

-- ---------------------------------------------------------------------
--  A. ПЕРЕСБОРКА ПОСУТОЧНОГО КЭША (запускает PHP/cron при изменениях)
-- ---------------------------------------------------------------------
--  Логика (реализуется в PHP, здесь — суть SQL-шагов):
--   1) Для затронутого тарифа и диапазона дат удалить строки кэша.
--   2) Развернуть периоды цен/ограничений/аллотмента в посуточные строки,
--      применяя dow_mask (день недели) и приоритеты периодов.
--   3) Слить с room_availability (наличие) и обязательными per-night extras.
--   4) INSERT ... в search_daily одной пачкой (batch).
--
--  Пример «плоского» разворота одного тарифа за диапазон дат через
--  вспомогательный календарь дат (таблица calendar(d DATE PRIMARY KEY)):

-- Удаляем старое
DELETE FROM search_daily
 WHERE rate_plan_id = :rate_plan_id
   AND stay_date BETWEEN :from AND :to;

-- Разворачиваем и вставляем (наличие берём из room_availability,
-- обязательные per-night extras прибавляем к цене в PHP или подзапросом)
INSERT INTO search_daily
  (hotel_id, city_id, country_id, room_type_id, rate_plan_id, board_type_id,
   occupancy_id, adults, children, stay_date, price, currency, available,
   min_stay, max_stay, cta, ctd, closed, min_advance, max_advance, channel_mask)
SELECT
   rp.hotel_id, h.city_id, h.country_id, rp.room_type_id, rp.id, rp.board_type_id,
   o.id, o.adults, o.children, cal.d,
   pr.price + IFNULL(mext.per_night_sum,0)      AS price,
   rp.currency,
   GREATEST(CAST(av.allotment AS SIGNED) - av.booked - av.blocked, 0) AS available,
   IFNULL(rs.min_stay,1), IFNULL(rs.max_stay,0),
   IFNULL(rs.closed_to_arrival,0), IFNULL(rs.closed_to_departure,0),
   IFNULL(rs.stop_sell,0), IFNULL(rs.min_advance_days,0), IFNULL(rs.max_advance_days,0),
   rp.channel_mask
FROM calendar cal
JOIN rate_plans rp        ON rp.id = :rate_plan_id AND rp.active = 1
JOIN hotels h             ON h.id = rp.hotel_id
JOIN occupancy_options o  ON o.room_type_id = rp.room_type_id
JOIN rate_prices pr       ON pr.rate_plan_id = rp.id
                        AND pr.occupancy_id = o.id
                        AND cal.d BETWEEN pr.date_from AND pr.date_to
                        AND (pr.dow_mask & (1 << WEEKDAY(cal.d)))
LEFT JOIN rate_restrictions rs ON rs.rate_plan_id = rp.id
                        AND cal.d BETWEEN rs.date_from AND rs.date_to
                        AND (rs.dow_mask & (1 << WEEKDAY(cal.d)))
LEFT JOIN room_availability av ON av.room_type_id = rp.room_type_id
                        AND av.stay_date = cal.d
LEFT JOIN (
     -- сумма обязательных per-night extras на тариф
     SELECT rpe.rate_plan_id, SUM(e.price) AS per_night_sum
       FROM rate_plan_extras rpe
       JOIN extras e ON e.id = rpe.extra_id
      WHERE e.is_mandatory = 1 AND e.charge_type = 'per_night'
      GROUP BY rpe.rate_plan_id
) mext ON mext.rate_plan_id = rp.id
WHERE cal.d BETWEEN :from AND :to;

-- Примечание: при пересечении периодов цен выбор нужной строки (по
-- приоритету/самому узкому периоду) делает PHP перед вставкой, либо
-- периоды хранятся непересекающимися по контракту загрузки.


-- ---------------------------------------------------------------------
--  B. ГОРЯЧИЙ ПОИСК — минимальный тариф по сотням отелей за один запрос
-- ---------------------------------------------------------------------
--  Вход из PHP:
--    :checkin  — дата заезда
--    :checkout — дата выезда  (ночей = DATEDIFF(:checkout,:checkin) = :nights)
--    :occ      — occupancy_id, разрешённый из (adults, children)
--    :rooms    — сколько номеров нужно (обычно 1)
--    :channel  — бит канала (напр. 1)
--    :hotels   — список hotel_id (или используйте city_id вариант)
--    :today    — текущая дата (для окон бронирования)
--
--  Идея: берём ВСЕ ночи диапазона [checkin, checkout) одним range-scan,
--  группируем по тарифу; тариф проходит только если:
--    * покрыты ВСЕ ночи         -> COUNT(*) = :nights
--    * наличие на каждую ночь    -> MIN(available) >= :rooms
--    * нет stop-sell             -> MAX(closed) = 0
--    * min/max stay на заезде    -> проверка строки заезда
--    * CTA на дату заезда        -> нет
--    * окна бронирования         -> min/max advance
--  Затем берём минимальный total_price на отель.

SELECT hotel_id, MIN(total_price) AS min_total_price
FROM (
    SELECT
        sd.hotel_id,
        sd.rate_plan_id,
        SUM(sd.price)                                  AS total_price,
        COUNT(*)                                       AS nights,
        MIN(sd.available)                              AS min_avail,
        MAX(sd.closed)                                 AS any_closed,
        -- правила, действующие на ночь ЗАЕЗДА:
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.cta = 1 THEN 1 ELSE 0 END)                    AS cta_block,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.min_stay > :nights THEN 1 ELSE 0 END)         AS minstay_block,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.max_stay > 0
                  AND sd.max_stay < :nights THEN 1 ELSE 0 END)                                     AS maxstay_block,
        MAX(CASE WHEN sd.stay_date = :checkin
                  AND sd.min_advance > DATEDIFF(:checkin, :today) THEN 1 ELSE 0 END)               AS minadv_block,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.max_advance > 0
                  AND sd.max_advance < DATEDIFF(:checkin, :today) THEN 1 ELSE 0 END)               AS maxadv_block
    FROM search_daily sd
    WHERE sd.occupancy_id = :occ
      AND sd.stay_date >= :checkin
      AND sd.stay_date <  :checkout
      AND sd.hotel_id IN ( /* :hotels */ )
      AND sd.closed = 0
      AND sd.available >= :rooms
      AND (sd.channel_mask & :channel)
    GROUP BY sd.hotel_id, sd.rate_plan_id
    HAVING nights = :nights
       AND cta_block = 0
       AND minstay_block = 0
       AND maxstay_block = 0
       AND minadv_block = 0
       AND maxadv_block = 0
) AS ok_rates
GROUP BY hotel_id;

-- CTD (closed-to-departure) проверяется по строке ДАТЫ ВЫЕЗДА отдельно
-- (она не входит в ночи проживания). PHP делает быстрый добор:
--   SELECT rate_plan_id FROM search_daily
--    WHERE occupancy_id=:occ AND stay_date=:checkout AND ctd=1
--      AND rate_plan_id IN (<кандидаты>);
-- и исключает такие тарифы. В большинстве продаж CTD включают редко,
-- поэтому добор дешевле, чем таскать выездную ночь в основном скане.


-- ---------------------------------------------------------------------
--  C. Быстрый ПЕРВЫЙ ОТБОР по городу через Tier-3 (search_best_nightly)
--     Мгновенно отсекает отели, где даже минимальная ночь не влезает в
--     бюджет/нет наличия; далее точный расчёт (B) только по кандидатам.
-- ---------------------------------------------------------------------

SELECT hotel_id, SUM(min_price) AS approx_total
FROM search_best_nightly
WHERE occupancy_id = :occ
  AND city_id = :city
  AND stay_date >= :checkin
  AND stay_date <  :checkout
  AND max_available >= :rooms
GROUP BY hotel_id
HAVING COUNT(*) = :nights
ORDER BY approx_total ASC
LIMIT 300;   -- список кандидатов -> в запрос (B)
