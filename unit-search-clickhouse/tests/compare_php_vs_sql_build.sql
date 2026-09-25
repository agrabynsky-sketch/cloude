-- Сверка двух способов сборки: PHP-воркер (search_stay) и SQL внутри ClickHouse (search_stay_new, до шага 04).
-- Запускать после 03_full_load_from_mysql.sql + 03b_build_chunk.sql (все пачки), но ДО 04_full_load_swap.sql.
-- Все колонки, кроме ver, должны совпасть: обе строки результата должны показать 0.
-- EXCEPT DISTINCT, а не EXCEPT (= EXCEPT ALL): в ClickHouse 24.8 EXCEPT ALL в такой форме не находил расхождения.
SELECT 'only in PHP build' AS what, count() AS rows FROM (
    SELECT d, hotel_id, rate_room_id, room_id, rate_id, parent_rate_id, country_id, region_id, city_id, stars, currency_id,
           board_id, refundable, channel_mask, is_public, access_group_id, room_type_id, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.search_stay FINAL WHERE d >= today()
    EXCEPT DISTINCT
    SELECT d, hotel_id, rate_room_id, room_id, rate_id, parent_rate_id, country_id, region_id, city_id, stars, currency_id,
           board_id, refundable, channel_mask, is_public, access_group_id, room_type_id, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.search_stay_new
)
UNION ALL
SELECT 'only in SQL build', count() FROM (
    SELECT d, hotel_id, rate_room_id, room_id, rate_id, parent_rate_id, country_id, region_id, city_id, stars, currency_id,
           board_id, refundable, channel_mask, is_public, access_group_id, room_type_id, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.search_stay_new
    EXCEPT DISTINCT
    SELECT d, hotel_id, rate_room_id, room_id, rate_id, parent_rate_id, country_id, region_id, city_id, stars, currency_id,
           board_id, refundable, channel_mask, is_public, access_group_id, room_type_id, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.search_stay FINAL WHERE d >= today()
);

-- Вторая независимая проверка: хеш каждой строки, собранный в сумму по всей таблице (порядок не важен).
SELECT 'hash PHP build' AS what, sum(cityHash64(d, hotel_id, rate_room_id, room_id, rate_id, parent_rate_id, country_id, region_id, city_id,
           stars, currency_id, board_id, refundable, channel_mask, is_public, access_group_id, room_type_id, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv)) AS h, count() AS rows
FROM unit_search.search_stay FINAL WHERE d >= today()
UNION ALL
SELECT 'hash SQL build', sum(cityHash64(d, hotel_id, rate_room_id, room_id, rate_id, parent_rate_id, country_id, region_id, city_id,
           stars, currency_id, board_id, refundable, channel_mask, is_public, access_group_id, room_type_id, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv)), count()
FROM unit_search.search_stay_new;
