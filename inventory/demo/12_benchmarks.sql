-- =====================================================================
--  Unit.Travel — ДЕМО: 3 тестовых поисковых запроса
--  Параметры: 2 взрослых (occupancy_id=2), заезд 2026-07-10, выезд 2026-07-13
--             (3 ночи), канал web (bit 1), публичный поиск.
-- =====================================================================

-- Q1. ПОИСК ПО 200 ОТЕЛЯМ -> одна мин. цена за период на отель
--     В БОЮ передавать ЛИТЕРАЛЬНЫЙ список: hotel_id IN (5,10,15,...).
--     Ниже IN (SELECT ...) — только для удобства демо; литерал вдвое+ быстрее.
SELECT hotel_id, MIN(total_price) AS min_total_price
FROM (
  SELECT sd.hotel_id, sd.rate_plan_id, SUM(sd.price) total_price, COUNT(*) nights,
         MAX(CASE WHEN sd.stay_date='2026-07-10' AND sd.cta=1 THEN 1 ELSE 0 END) cta_block,
         MAX(CASE WHEN sd.stay_date='2026-07-10' AND sd.min_stay>3 THEN 1 ELSE 0 END) minstay_block
  FROM search_daily sd
  WHERE sd.occupancy_id=2
    AND sd.stay_date>='2026-07-10' AND sd.stay_date<'2026-07-13'
    AND sd.hotel_id IN (SELECT seq*5 FROM seq_1_to_200)   -- 200 отелей, разбросаны
    AND sd.closed=0 AND sd.available>=1
    AND (sd.channel_mask & 1) AND sd.access_group_id=0
  GROUP BY sd.hotel_id, sd.rate_plan_id
  HAVING nights=3 AND cta_block=0 AND minstay_block=0
) t
GROUP BY hotel_id;

-- Q2. ПОИСК ПО ID ГОРОДА -> одна мин. цена за период на отель
SELECT hotel_id, MIN(total_price) AS min_total_price
FROM (
  SELECT sd.hotel_id, sd.rate_plan_id, SUM(sd.price) total_price, COUNT(*) nights,
         MAX(CASE WHEN sd.stay_date='2026-07-10' AND sd.cta=1 THEN 1 ELSE 0 END) cta_block,
         MAX(CASE WHEN sd.stay_date='2026-07-10' AND sd.min_stay>3 THEN 1 ELSE 0 END) minstay_block
  FROM search_daily sd
  WHERE sd.occupancy_id=2
    AND sd.stay_date>='2026-07-10' AND sd.stay_date<'2026-07-13'
    AND sd.city_id=7
    AND sd.closed=0 AND sd.available>=1
    AND (sd.channel_mask & 1) AND sd.access_group_id=0
  GROUP BY sd.hotel_id, sd.rate_plan_id
  HAVING nights=3 AND cta_block=0 AND minstay_block=0
) t
GROUP BY hotel_id
ORDER BY min_total_price ASC;

-- Q3. ОДИН ОТЕЛЬ -> ВСЕ румрейты с сырой суммой за период
SELECT sd.room_id, sd.rate_plan_id, sd.board_type_id, sd.is_refundable,
       SUM(sd.price) AS total_raw_price, MIN(sd.available) AS min_avail
FROM search_daily sd
WHERE sd.hotel_id=500
  AND sd.occupancy_id=2
  AND sd.stay_date>='2026-07-10' AND sd.stay_date<'2026-07-13'
  AND sd.closed=0 AND sd.available>=1
  AND (sd.channel_mask & 1) AND sd.access_group_id=0
GROUP BY sd.room_id, sd.rate_plan_id, sd.board_type_id, sd.is_refundable
HAVING COUNT(*)=3
ORDER BY total_raw_price ASC;
