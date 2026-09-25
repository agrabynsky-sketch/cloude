-- =====================================================================
-- ТОЛЬКО для стенда с демо-данными (mysql/02_demo_data.sql) и без UNIQUE-ключей из 00_recommended_indexes.sql.
-- Добавляет дубли строк в первые 100 демо-отелей — так бывает на проде (нет UNIQUE, ошибка пагинации bulk edit).
-- Правило кэша: из дублей побеждает строка с максимальным id. Проверка после вставки:
--   php scripts/search-sync.php hotels <first>-<first+99>
--   clickhouse/03 + 03b (все пачки) -> tests/compare_php_vs_sql_build.sql -> 04
--   php tests/verify_reference.php 200 1 <first>-<first+99>
-- Дубли удаляются вместе с демо-данными (mysql/03_demo_cleanup.sql).
-- =====================================================================
SET @h1 = (SELECT id_from FROM search_demo_registry WHERE entity = 'hotels');
SET @h2 = @h1 + 99;

-- цены: ещё одна (более новая) строка на ту же ночь — цена +7, четверть из них закрыта
INSERT INTO hotels_rates_prices (id_rate_room, id_room, id_rate, date, price, derive_type, derive_value,
                                 min_los, max_los, min_adv, max_adv, cta, ctd, active)
SELECT p.id_rate_room, p.id_room, p.id_rate, p.date, p.price + 7, p.derive_type, p.derive_value,
       p.min_los, p.max_los, p.min_adv, p.max_adv, p.cta, p.ctd, IF(CRC32(CONCAT('dp', p.id)) % 4 = 0, 0, 1)
FROM hotels_rates_prices p
JOIN hotels_rooms r ON r.id = p.id_room
WHERE r.id_hotel BETWEEN @h1 AND @h2 AND CRC32(CONCAT('dup', p.id)) % 100 < 3;

-- наличие: +1 бронь, пятая часть — стоп-продажа
INSERT INTO hotels_rooms_availability (id_room, date, allotment, net_booked, active)
SELECT a.id_room, a.date, a.allotment, a.net_booked + 1, IF(CRC32(CONCAT('da', a.id)) % 5 = 0, 0, 1)
FROM hotels_rooms_availability a
JOIN hotels_rooms r ON r.id = a.id_room
WHERE r.id_hotel BETWEEN @h1 AND @h2 AND CRC32(CONCAT('dup', a.id)) % 100 < 3;

-- надбавки за гостей: другой процент
INSERT INTO hotels_rates_occupancy (id_rate_room, id_room, id_rate, guests, amount, active)
SELECT o.id_rate_room, o.id_room, o.id_rate, o.guests, o.amount + 5, o.active
FROM hotels_rates_occupancy o
JOIN hotels_rooms r ON r.id = o.id_room
WHERE r.id_hotel BETWEEN @h1 AND @h2 AND CRC32(CONCAT('dup', o.id)) % 3 = 0;

-- дневные цены на гостей: другая цена, пятая часть — 0 ("не задано" перекрывает старую строку)
INSERT INTO hotels_rates_occupancy_daily (id_rate_room, id_room, id_rate, guests, date, price)
SELECT d.id_rate_room, d.id_room, d.id_rate, d.guests, d.date, IF(CRC32(CONCAT('dd', d.id)) % 5 = 0, 0, d.price + 11)
FROM hotels_rates_occupancy_daily d
JOIN hotels_rooms r ON r.id = d.id_room
WHERE r.id_hotel BETWEEN @h1 AND @h2 AND CRC32(CONCAT('dup', d.id)) % 10 < 3;

SELECT @h1 AS first_hotel, @h2 AS last_hotel;
