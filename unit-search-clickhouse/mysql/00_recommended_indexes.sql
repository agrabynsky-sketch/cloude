-- =====================================================================
-- Рекомендованные индексы MySQL (из анализа производительности, пункты 2 и 8).
-- Не обязательны для работы поискового слоя, но:
--   * сборщик кэша читает цены/наличие по (id_rate_room|id_room) + диапазон дат — составной индекс
--     читает ровно нужные строки, а не всю историю рум-рейта;
--   * UNIQUE не даёт появиться дублям (id_rate_room, date), из-за которых цена в календаре и в кэше
--     зависит от порядка строк.
-- ВНИМАНИЕ: перед ALTER проверьте дубли (запросы ниже должны вернуть 0 строк), на проде используйте
-- pt-online-schema-change / gh-ost. На 7 млн строк ALTER идёт ~1 мин.
-- =====================================================================

-- проверка дублей
SELECT id_rate_room, date, COUNT(*) FROM hotels_rates_prices GROUP BY id_rate_room, date HAVING COUNT(*) > 1 LIMIT 10;
SELECT id_room, date, COUNT(*) FROM hotels_rooms_availability GROUP BY id_room, date HAVING COUNT(*) > 1 LIMIT 10;
SELECT id_rate, id_room, COUNT(*) FROM hotels_rates_rooms GROUP BY id_rate, id_room HAVING COUNT(*) > 1 LIMIT 10;
SELECT id_rate_room, guests, COUNT(*) FROM hotels_rates_occupancy GROUP BY id_rate_room, guests HAVING COUNT(*) > 1 LIMIT 10;

ALTER TABLE hotels_rates_prices MODIFY `date` DATE NOT NULL,
  ADD UNIQUE KEY uq_rr_date (id_rate_room, `date`), DROP KEY id_rate_room;
ALTER TABLE hotels_rooms_availability MODIFY `date` DATE NOT NULL,
  ADD UNIQUE KEY uq_room_date (id_room, `date`), DROP KEY id_room;
ALTER TABLE hotels_rates_rooms ADD UNIQUE KEY uq_rate_room (id_rate, id_room);
ALTER TABLE hotels_rates_occupancy ADD UNIQUE KEY uq_rr_guests (id_rate_room, guests);
