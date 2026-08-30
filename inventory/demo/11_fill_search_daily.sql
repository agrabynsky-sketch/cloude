-- =====================================================================
--  Unit.Travel — ДЕМО-ДАННЫЕ
--  Файл 11: заполнение поискового кэша search_daily на 1 год
--    15 000 тарифов × 3 размещения × 365 дней ≈ 16.4 млн строк
--  Имитирует результат пересборки (Слой 1 -> Слой 2): цена уже финальная.
--  Пишется чанками по 30 дней, чтобы держать транзакции умеренными.
-- =====================================================================

SET SESSION unique_checks = 0;
SET SESSION foreign_key_checks = 0;

DROP PROCEDURE IF EXISTS fill_search_daily;
DELIMITER $$
CREATE PROCEDURE fill_search_daily(IN p_days INT)
BEGIN
  DECLARE lo INT DEFAULT 0;
  WHILE lo < p_days DO
    INSERT INTO search_daily
      (hotel_id, city_id, country_id, room_id, rate_plan_id, board_type_id,
       occupancy_id, adults, children, max_infants, is_refundable, stay_date,
       price, currency, available,
       min_stay, max_stay, cta, ctd, closed, min_advance, max_advance,
       channel_mask, visibility, access_group_id)
    SELECT
       rp.hotel_id, h.city_id, h.country_id, rp.room_id, rp.id, rp.board_type_id,
       occ.ok, occ.a, occ.c, rm.max_infants, rp.is_refundable,
       DATE_ADD('2026-01-01', INTERVAL (lo + d.seq) DAY)          AS stay_date,
       -- финальная цена за ночь (детерминированная, но с разбросом):
       50
       + (rp.hotel_id % 40) * 3                                    -- «звёздность» отеля
       + (rp.room_id % 5) * 12                                     -- категория
       + (rp.id % 3) * 8                                           -- тариф
       + occ.a * 25 + occ.c * 12                                   -- размещение
       + CASE WHEN MONTH(DATE_ADD('2026-01-01', INTERVAL (lo+d.seq) DAY)) IN (6,7,8) THEN 60
              WHEN MONTH(DATE_ADD('2026-01-01', INTERVAL (lo+d.seq) DAY)) IN (12,1) THEN 30
              ELSE 15 END                                          -- сезон
       + IF(WEEKDAY(DATE_ADD('2026-01-01', INTERVAL (lo+d.seq) DAY)) >= 5, 20, 0) -- выходные
                                                                   AS price,
       'EUR',
       2 + (rp.id + d.seq) % 8                                     AS available, -- 2..9
       1, 0, 0, 0, 0, 0, 0,
       rp.channel_mask, 0, rp.access_group_id
    FROM rate_plans rp
    JOIN room rm   ON rm.id = rp.room_id
    JOIN hotels h  ON h.id  = rp.hotel_id
    JOIN (SELECT 100 ok,1 a,0 c
          UNION ALL SELECT 200,2,0
          UNION ALL SELECT 201,2,1) occ
    JOIN seq_0_to_29 d
    WHERE (lo + d.seq) < p_days;
    SET lo = lo + 30;
  END WHILE;
END$$
DELIMITER ;

CALL fill_search_daily(365);

SET SESSION unique_checks = 1;
SET SESSION foreign_key_checks = 1;

SELECT COUNT(*) AS search_daily_rows,
       MIN(stay_date) AS first_date, MAX(stay_date) AS last_date
FROM search_daily;
