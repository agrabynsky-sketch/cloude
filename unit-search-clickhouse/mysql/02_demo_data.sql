-- =====================================================================
-- Unit.Travel: демо-данные для поискового слоя (MySQL 5.7, source of truth)
--
-- Что создаётся (по умолчанию 2000 отелей на 365 дней от сегодняшней даты):
--   hotels                      2000 отелей: 1000 в стране 927 (регион 243836, валюта 46688),
--                               1000 в стране 907 (регион 38220, валюта 18864)
--   hotels_rooms                3..5 номеров на отель (Double, Twin, Triple, Family, Suite)
--   hotels_rates                3..4 тарифа на отель:
--                                 BAR (flexible, RO), Non-refundable (производный от BAR, -10..-20%),
--                                 Bed & Breakfast (BB), у половины отелей ещё Half Board (HB, min_los=2, только B2C+B2B)
--   hotels_rates_rooms          каждый тариф привязан к каждому номеру (9..20 рум-рейтов на отель)
--   hotels_rates_prices         цены и ограничения на каждую ночь для непроизводных тарифов
--                               (+ ~3% собственных строк у производного тарифа: своя цена / закрытие / свой %)
--   hotels_rooms_availability   наличие на ~80% ночей (остальные ночи = allotment номера)
--   hotels_rates_occupancy      надбавки за 1/3/4 гостей для номеров с pricing_model = 2
--   hotels_rates_occupancy_daily цены за 1 и 3 гостей на ~8% ночей для номеров с pricing_model = 2
--                               (~2% строк с price = 0 — "не задано", и строки для выключенного числа гостей)
--
-- Безопасность для рабочей базы:
--   * id берутся от MAX(id) + 1000 каждой таблицы (без больших скачков AUTO_INCREMENT);
--   * вставки идут пачками по 50 отелей (короткие транзакции для репликации/Galera);
--   * диапазоны id записываются в search_demo_registry, удаление: mysql/03_demo_cleanup.sql;
--   * повторный запуск без очистки остановится с ошибкой.
--   Сначала запускайте на копии/стейдже.
--
-- Запуск:  mysql -u... -p... <db> < 02_demo_data.sql
--          (или в Navicat: выполнить файл целиком, DELIMITER поддерживается)
-- Время:   ~3-6 минут на 2000 отелей (≈5 млн строк цен, ≈2.3 млн строк наличия)
-- =====================================================================

CREATE TABLE IF NOT EXISTS search_demo_registry (
  entity     VARCHAR(32) NOT NULL,
  id_from    BIGINT      NOT NULL,
  id_to      BIGINT      NOT NULL,
  start_date DATE        NOT NULL,
  created    DATETIME    NOT NULL,
  PRIMARY KEY (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP PROCEDURE IF EXISTS search_demo_generate;

DELIMITER $$

CREATE PROCEDURE search_demo_generate(IN p_hotels INT, IN p_days INT)
BEGIN
  DECLARE v_hb, v_rb, v_tb, v_rrb BIGINT;
  DECLARE v_from INT DEFAULT 1;
  DECLARE v_chunk INT DEFAULT 50;
  DECLARE v_start DATE DEFAULT CURDATE();

  IF (SELECT COUNT(*) FROM search_demo_registry) > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Demo data already exists. Run 03_demo_cleanup.sql first.';
  END IF;
  IF p_hotels < 1 OR p_hotels > 9999 OR p_days < 1 OR p_days > 730 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p_hotels must be 1..9999, p_days 1..730';
  END IF;

  -- ---------- helper: sequence 0..9999
  DROP TABLE IF EXISTS search_demo_seq;
  CREATE TABLE search_demo_seq (n INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
  INSERT INTO search_demo_seq VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);
  INSERT INTO search_demo_seq SELECT a.n * 10 + b.n FROM search_demo_seq a, search_demo_seq b WHERE a.n * 10 + b.n >= 10;
  INSERT INTO search_demo_seq SELECT a.n * 100 + b.n FROM search_demo_seq a, search_demo_seq b
    WHERE a.n < 100 AND b.n < 100 AND a.n * 100 + b.n >= 100;

  -- ---------- id bases: gap of 1000 after current max, so live inserts do not collide
  SELECT IFNULL(MAX(id), 0) + 1000 INTO v_hb  FROM hotels;
  SELECT IFNULL(MAX(id), 0) + 1000 INTO v_rb  FROM hotels_rooms;
  SELECT IFNULL(MAX(id), 0) + 1000 INTO v_tb  FROM hotels_rates;
  SELECT IFNULL(MAX(id), 0) + 1000 INTO v_rrb FROM hotels_rates_rooms;

  -- registry first: a second run fails on PRIMARY KEY before touching data
  INSERT INTO search_demo_registry (entity, id_from, id_to, start_date, created) VALUES
    ('hotels',             v_hb + 1,  v_hb + p_hotels,       v_start, NOW()),
    ('hotels_rooms',       v_rb + 1,  v_rb + p_hotels * 5,   v_start, NOW()),
    ('hotels_rates',       v_tb + 1,  v_tb + p_hotels * 4,   v_start, NOW()),
    ('hotels_rates_rooms', v_rrb + 1, v_rrb + p_hotels * 20, v_start, NOW());

  -- ---------- hotels
  INSERT INTO hotels (id, title, stars, stars_title, id_country, id_region, id_city, id_type, id_currency,
                      lat, lng, timezone, check_in_from, check_out_to, allow_children, active, tax_included, fee_included, week_start_dow)
  SELECT v_hb + s.n,
         CONCAT('Demo ', ELT(1 + s.n % 10, 'Grand', 'Park', 'Royal', 'Sea View', 'Palace', 'Garden', 'City', 'Resort', 'Plaza', 'Boutique'),
                ' Hotel ', LPAD(s.n, 4, '0')),
         2 + s.n % 4, CONCAT(2 + s.n % 4, '*'),
         IF(s.n <= p_hotels / 2, 927, 907), IF(s.n <= p_hotels / 2, 243836, 38220), 0, 1,
         IF(s.n <= p_hotels / 2, 46688, 18864),
         IF(s.n <= p_hotels / 2, 48.36, 36.48) + (CRC32(CONCAT('lat', s.n)) % 2000) / 10000,
         IF(s.n <= p_hotels / 2, 24.39, 30.52) + (CRC32(CONCAT('lng', s.n)) % 2000) / 10000,
         IF(s.n <= p_hotels / 2, 'Europe/Kiev', 'Europe/Istanbul'), '14:00', '12:00', 1, 1, 1, 1, 1
  FROM search_demo_seq s WHERE s.n BETWEEN 1 AND p_hotels;

  -- ---------- rooms: 3..5 per hotel
  INSERT INTO hotels_rooms (id, title, internal_name, id_hotel, id_type, allotment, square, base_occupancy, max_occupancy,
                            max_adults, max_children, is_without_infants, pricing_model, floor, active, data)
  SELECT v_rb + (h.n - 1) * 5 + r.n,
         ELT(r.n, 'Standard Double Room', 'Standard Twin Room', 'Triple Room', 'Family Room', 'Junior Suite'),
         CONCAT('demo-', h.n, '-', r.n),
         v_hb + h.n,
         ELT(r.n, 4, 7, 13, 19, 31),
         2 + CRC32(CONCAT('alt', h.n, '-', r.n)) % 9,
         ELT(r.n, 20, 22, 28, 35, 45),
         2,
         ELT(r.n, 2, 2, 3, 4, 4),
         ELT(r.n, 2, 2, 3, 4, 3),
         ELT(r.n, 0, 0, 1, 2, 2),
         0,
         IF(r.n >= 3 AND h.n % 2 = 0, 2, 1),
         'high', 1,
         '{"beds":[{"type":"7","count":"1"}],"bathrooms":[{"private":"1","inside":"1"}]}'
  FROM search_demo_seq h JOIN search_demo_seq r
  WHERE h.n BETWEEN 1 AND p_hotels AND r.n BETWEEN 1 AND 3 + h.n % 3;

  -- ---------- rates: 3..4 per hotel
  INSERT INTO hotels_rates (id, id_hotel, id_parent, title, id_cancel_policy, id_board_type, min_los, min_adv,
                            derive_type, derive_value, channel_mask, visibility, access_group_id, access_country_type, is_package, active, data)
  SELECT v_tb + (h.n - 1) * 4 + t.n,
         v_hb + h.n,
         IF(t.n = 2, v_tb + (h.n - 1) * 4 + 1, 0),
         ELT(t.n, 'Best Available Rate', 'Non-refundable', 'Bed & Breakfast', 'Half Board'),
         IF(t.n = 2, 0, 438433),
         ELT(t.n, 1, 1, 4, 7),
         IF(t.n = 4, 2, 0),
         0,
         IF(t.n = 2, 4, 0),
         IF(t.n = 2, 10 + CRC32(CONCAT('dv', h.n)) % 11, 0),
         IF(t.n = 4, 3, 31),
         'public', 0, 0, 0, 1,
         ELT(t.n, '{"board":["RO"]}', '{"board":["RO"]}', '{"board":["BF"]}', '{"board":["BF","DN"]}')
  FROM search_demo_seq h JOIN search_demo_seq t
  WHERE h.n BETWEEN 1 AND p_hotels AND t.n BETWEEN 1 AND 3 + h.n % 2;

  -- ---------- rate-rooms: every rate x every room of the hotel
  INSERT INTO hotels_rates_rooms (id, id_rate, id_room)
  SELECT v_rrb + (h.n - 1) * 20 + (r.n - 1) * 4 + t.n, v_tb + (h.n - 1) * 4 + t.n, v_rb + (h.n - 1) * 5 + r.n
  FROM search_demo_seq h JOIN search_demo_seq r JOIN search_demo_seq t
  WHERE h.n BETWEEN 1 AND p_hotels AND r.n BETWEEN 1 AND 3 + h.n % 3 AND t.n BETWEEN 1 AND 3 + h.n % 2;

  -- ---------- helper: rate-room facts used by the price generator
  DROP TABLE IF EXISTS search_demo_rr;
  CREATE TABLE search_demo_rr (
    id INT NOT NULL PRIMARY KEY, h INT NOT NULL, r INT NOT NULL, t INT NOT NULL,
    room_id INT NOT NULL, rate_id INT NOT NULL, is_ua TINYINT NOT NULL, pf DECIMAL(12,4) NOT NULL,
    KEY (h)
  ) ENGINE=InnoDB;
  INSERT INTO search_demo_rr
  SELECT rr.id, h.n, r.n, t.n, rr.id_room, rr.id_rate, h.n <= p_hotels / 2,
         IF(h.n <= p_hotels / 2, 2000, 90)                                  -- currency level (UAH / EUR)
         * ELT(2 + h.n % 4 - 1, 0.6, 0.8, 1.0, 1.5)                          -- stars 2..5
         * ELT(r.n, 1.0, 1.0, 1.35, 1.6, 2.2)                                -- room type
         * ELT(t.n, 1.0, 1.0, 1.12, 1.3)                                     -- board
         * (0.8 + (CRC32(CONCAT('hv', h.n)) % 40) / 100)                     -- hotel variation
  FROM search_demo_seq h JOIN search_demo_seq r JOIN search_demo_seq t
  JOIN hotels_rates_rooms rr ON rr.id = v_rrb + (h.n - 1) * 20 + (r.n - 1) * 4 + t.n
  WHERE h.n BETWEEN 1 AND p_hotels AND r.n BETWEEN 1 AND 3 + h.n % 3 AND t.n BETWEEN 1 AND 3 + h.n % 2;

  -- ---------- helper: calendar days with season / weekend factors
  DROP TABLE IF EXISTS search_demo_day;
  CREATE TABLE search_demo_day (n INT NOT NULL PRIMARY KEY, d DATE NOT NULL, s_ua DECIMAL(4,2), s_tr DECIMAL(4,2), wk DECIMAL(4,2)) ENGINE=InnoDB;
  INSERT INTO search_demo_day
  SELECT s.n, v_start + INTERVAL s.n DAY,
         CASE WHEN MONTH(v_start + INTERVAL s.n DAY) IN (12, 1, 2, 3) THEN 1.4 WHEN MONTH(v_start + INTERVAL s.n DAY) IN (6, 7, 8) THEN 1.2 ELSE 1.0 END,
         CASE WHEN MONTH(v_start + INTERVAL s.n DAY) IN (6, 7, 8, 9) THEN 1.5 WHEN MONTH(v_start + INTERVAL s.n DAY) IN (5, 10) THEN 1.2 ELSE 0.8 END,
         IF(DAYOFWEEK(v_start + INTERVAL s.n DAY) IN (6, 7), 1.15, 1.0)
  FROM search_demo_seq s WHERE s.n < p_days;

  -- ---------- per-night data, in chunks of 50 hotels
  WHILE v_from <= p_hotels DO
    -- prices of independent rates (BAR, BB, HB): ~2% nights without price, ~1.5% closed,
    -- 8% hotel-nights with min_los 2..3, 2% CTA, 2% CTD
    INSERT INTO hotels_rates_prices (id_rate_room, id_room, id_rate, date, price, derive_type, derive_value,
                                     min_los, max_los, min_adv, max_adv, cta, ctd, active)
    SELECT rr.id, rr.room_id, rr.rate_id, dd.d,
           ROUND(rr.pf * IF(rr.is_ua, dd.s_ua, dd.s_tr) * dd.wk, 0), 0, 0,
           IF(CRC32(CONCAT('l', rr.h, '-', dd.n)) % 100 < 8, 2 + CRC32(CONCAT('L', rr.h, '-', dd.n)) % 2, NULL),
           NULL, NULL, NULL,
           IF(CRC32(CONCAT('a', rr.id, '-', dd.n)) % 100 < 2, 1, 0),
           IF(CRC32(CONCAT('b', rr.id, '-', dd.n)) % 100 < 2, 1, 0),
           IF(CRC32(CONCAT('x', rr.id, '-', dd.n)) % 1000 < 15, 0, 1)
    FROM search_demo_rr rr JOIN search_demo_day dd
    WHERE rr.h BETWEEN v_from AND v_from + v_chunk - 1 AND rr.t <> 2
      AND CRC32(CONCAT('p', rr.id, '-', dd.n)) % 100 >= 2;

    -- own rows of the derived rate (Non-refundable), ~3% of nights:
    --   kind 0: explicit price (overrides parent), kind 1: closed, kind 2: own derive -25%
    INSERT INTO hotels_rates_prices (id_rate_room, id_room, id_rate, date, price, derive_type, derive_value,
                                     min_los, max_los, min_adv, max_adv, cta, ctd, active)
    SELECT rr.id, rr.room_id, rr.rate_id, dd.d,
           IF(CRC32(CONCAT('k', rr.id, '-', dd.n)) % 3 = 0, ROUND(rr.pf * IF(rr.is_ua, dd.s_ua, dd.s_tr) * dd.wk * 0.75, 0), NULL),
           IF(CRC32(CONCAT('k', rr.id, '-', dd.n)) % 3 = 2, 4, 0),
           IF(CRC32(CONCAT('k', rr.id, '-', dd.n)) % 3 = 2, -25, 0),
           NULL, NULL, NULL, NULL, 0, 0,
           IF(CRC32(CONCAT('k', rr.id, '-', dd.n)) % 3 = 1, 0, 1)
    FROM search_demo_rr rr JOIN search_demo_day dd
    WHERE rr.h BETWEEN v_from AND v_from + v_chunk - 1 AND rr.t = 2
      AND CRC32(CONCAT('o', rr.id, '-', dd.n)) % 100 < 3;

    -- daily occupancy prices (rooms with pricing_model = 2): explicit price for 1 and 3 guests on ~8% of nights,
    --   ~2% of rows with price 0 (= not set, must be ignored)
    INSERT INTO hotels_rates_occupancy_daily (id_rate_room, id_room, id_rate, guests, date, price)
    SELECT rr.id, rr.room_id, rr.rate_id, g.n, dd.d,
           IF(CRC32(CONCAT('z', rr.id, '-', g.n, '-', dd.n)) % 50 = 0, 0,
              ROUND(rr.pf * IF(rr.is_ua, dd.s_ua, dd.s_tr) * dd.wk * ELT(g.n, 0.85, 1.0, 1.25, 1.45), 0))
    FROM search_demo_rr rr
    JOIN hotels_rooms ro ON ro.id = rr.room_id AND ro.pricing_model = 2
    JOIN search_demo_day dd
    JOIN search_demo_seq g ON g.n IN (1, 3) AND g.n <= ro.max_occupancy
    WHERE rr.h BETWEEN v_from AND v_from + v_chunk - 1
      AND CRC32(CONCAT('od', rr.id, '-', dd.n)) % 100 < 8;

    -- availability: rows on ~80% of room-nights, 0..3 booked, ~1.5% stop-sale
    INSERT INTO hotels_rooms_availability (id_room, date, allotment, net_booked, active)
    SELECT ro.id, dd.d,
           GREATEST(1, ro.allotment + CAST(CRC32(CONCAT('v', ro.id, '-', dd.n)) % 3 AS SIGNED) - 1),
           CRC32(CONCAT('nb', ro.id, '-', dd.n)) % 4,
           IF(CRC32(CONCAT('ss', ro.id, '-', dd.n)) % 1000 < 15, 0, 1)
    FROM hotels_rooms ro JOIN search_demo_day dd
    WHERE ro.id_hotel BETWEEN v_hb + v_from AND v_hb + v_from + v_chunk - 1
      AND CRC32(CONCAT('av', ro.id, '-', dd.n)) % 100 < 80;

    SET v_from = v_from + v_chunk;
  END WHILE;

  -- ---------- occupancy-based pricing (rooms with pricing_model = 2): 1 guest -10%, 3 guests +15%, 4 guests +30%
  --            ~10% of rate-rooms have 3 guests switched off (active = 0 -> this guest count is not sold)
  INSERT INTO hotels_rates_occupancy (id_rate_room, id_room, id_rate, guests, amount, active)
  SELECT rr.id, rr.room_id, rr.rate_id, g.n, ELT(g.n, -10, 0, 15, 30),
         IF(g.n = 3 AND CRC32(CONCAT('g3', rr.id)) % 10 = 0, 0, 1)
  FROM search_demo_rr rr
  JOIN hotels_rooms ro ON ro.id = rr.room_id
  JOIN search_demo_seq g ON g.n IN (1, 3, 4) AND g.n <= ro.max_occupancy
  WHERE ro.pricing_model = 2;

  DROP TABLE search_demo_rr;
  DROP TABLE search_demo_day;
  DROP TABLE search_demo_seq;

  SELECT 'demo data generated' AS status, v_start AS start_date, p_hotels AS hotels, p_days AS days,
         v_hb + 1 AS first_hotel_id, v_hb + p_hotels AS last_hotel_id;
END$$

DELIMITER ;

CALL search_demo_generate(2000, 365);
