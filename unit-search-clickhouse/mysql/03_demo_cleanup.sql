-- =====================================================================
-- Удаление демо-данных, созданных 02_demo_data.sql.
-- Удаляет только то, что попадает в диапазоны id из hotels_search_demo_registry,
-- пачками по 10 000 строк (короткие транзакции для репликации/Galera).
-- После очистки не забудьте удалить демо-отели из ClickHouse:
--   php scripts/search-sync.php hotels <id_from>-<id_to>   (отели уже неактивны/удалены -> будут записаны tombstone-строки)
-- или целиком: TRUNCATE TABLE unit_search.hotels_search_stay (если там только демо).
-- =====================================================================

DROP PROCEDURE IF EXISTS hotels_search_demo_cleanup;

DELIMITER $$

CREATE PROCEDURE hotels_search_demo_cleanup()
BEGIN
  DECLARE v_h1, v_h2, v_r1, v_r2, v_t1, v_t2, v_rr1, v_rr2 BIGINT;
  DECLARE v_rows INT DEFAULT 1;

  IF (SELECT COUNT(*) FROM hotels_search_demo_registry) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No demo data registered';
  END IF;

  SELECT id_from, id_to INTO v_h1, v_h2   FROM hotels_search_demo_registry WHERE entity = 'hotels';
  SELECT id_from, id_to INTO v_r1, v_r2   FROM hotels_search_demo_registry WHERE entity = 'hotels_rooms';
  SELECT id_from, id_to INTO v_t1, v_t2   FROM hotels_search_demo_registry WHERE entity = 'hotels_rates';
  SELECT id_from, id_to INTO v_rr1, v_rr2 FROM hotels_search_demo_registry WHERE entity = 'hotels_rates_rooms';

  SET v_rows = 1;
  WHILE v_rows > 0 DO
    DELETE FROM hotels_rates_prices WHERE id_rate_room BETWEEN v_rr1 AND v_rr2 LIMIT 10000;
    SET v_rows = ROW_COUNT();
  END WHILE;

  SET v_rows = 1;
  WHILE v_rows > 0 DO
    DELETE FROM hotels_rooms_availability WHERE id_room BETWEEN v_r1 AND v_r2 LIMIT 10000;
    SET v_rows = ROW_COUNT();
  END WHILE;

  SET v_rows = 1;
  WHILE v_rows > 0 DO
    DELETE FROM hotels_rates_occupancy_daily WHERE id_rate_room BETWEEN v_rr1 AND v_rr2 LIMIT 10000;
    SET v_rows = ROW_COUNT();
  END WHILE;

  DELETE FROM hotels_rates_occupancy WHERE id_rate_room BETWEEN v_rr1 AND v_rr2;
  DELETE FROM hotels_rates_rooms     WHERE id BETWEEN v_rr1 AND v_rr2;
  DELETE FROM hotels_rates           WHERE id BETWEEN v_t1 AND v_t2;
  DELETE FROM hotels_rooms           WHERE id BETWEEN v_r1 AND v_r2;
  DELETE FROM hotels                 WHERE id BETWEEN v_h1 AND v_h2;
  DELETE FROM hotels_search_demo_registry;

  SELECT 'demo data removed' AS status, v_h1 AS id_hotel_first, v_h2 AS id_hotel_last;
END$$

DELIMITER ;

CALL hotels_search_demo_cleanup();
