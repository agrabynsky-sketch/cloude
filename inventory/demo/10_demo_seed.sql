-- =====================================================================
--  Unit.Travel — ДЕМО-ДАННЫЕ (нагрузочный тест)
--  Файл 10: базовые сущности
--    1000 отелей × 5 room × 3 rate_plan = 15 000 тарифов
--  Использует sequence-движок MariaDB (seq_A_to_B) для быстрой генерации.
-- =====================================================================

-- Гео: 20 стран, 100 городов
INSERT INTO countries (iso2, name)
SELECT LPAD(seq,2,'0'), CONCAT('Country ', seq) FROM seq_1_to_20;

INSERT INTO cities (country_id, name, timezone)
SELECT ((seq-1)%20)+1, CONCAT('City ', seq), 'UTC' FROM seq_1_to_100;

-- 1000 отелей, равномерно по городам
INSERT INTO hotels (city_id, country_id, code, name, base_currency, timezone, status)
SELECT
   ((seq-1)%100)+1                        AS city_id,
   (((((seq-1)%100)+1)-1)%20)+1           AS country_id,
   CONCAT('H', seq), CONCAT('Hotel ', seq),
   'EUR', 'UTC', 'active'
FROM seq_1_to_1000;

-- 5 room на отель (id 1..5000)
INSERT INTO room (hotel_id, code, name, base_occupancy, max_occupancy,
                  max_adults, max_children, max_infants, total_rooms, active)
SELECT h.id, CONCAT('R', r.seq), CONCAT('Room ', r.seq),
       2, 4, 3, 2, 1, 5 + (h.id % 6), 1
FROM hotels h JOIN seq_1_to_5 r;

-- 3 rate_plan на room (id 1..15000). Питание/отменяемость варьируются.
INSERT INTO rate_plans (hotel_id, room_id, board_type_id, code, name, currency,
                        is_refundable, channel_mask, visibility, access_group_id,
                        pricing_model, active)
SELECT rm.hotel_id, rm.id,
       ((rm.id + rp.seq) % 5) + 1                    AS board_type_id,
       CONCAT('RP', rm.id, '_', rp.seq),
       CONCAT('Rate ', rp.seq),
       'EUR',
       IF((rm.id + rp.seq) % 3 = 0, 0, 1)            AS is_refundable,
       63, 'public', 0, 2, 1
FROM room rm JOIN seq_1_to_3 rp;

-- occupancy_options: базовые варианты по числу ВЗРОСЛЫХ (1/2/3)
INSERT INTO occupancy_options (room_id, adults, label)
SELECT rm.id, o.a, o.lbl
FROM room rm
JOIN (SELECT 1 a,'1 adult' lbl
      UNION ALL SELECT 2,'2 adults'
      UNION ALL SELECT 3,'3 adults') o;

SELECT
 (SELECT COUNT(*) FROM hotels)      AS hotels,
 (SELECT COUNT(*) FROM room)        AS rooms,
 (SELECT COUNT(*) FROM rate_plans)  AS rate_plans;
