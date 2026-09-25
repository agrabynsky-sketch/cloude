-- Сверка двух способов сборки: PHP-воркер (hotels_search_stay) и SQL внутри ClickHouse (hotels_search_stay_new, до шага 04).
-- Запускать после 03_full_load_from_mysql.sql + 03b_build_chunk.sql (все пачки), но ДО 04_full_load_swap.sql.
-- Все колонки, кроме ver, должны совпасть: обе строки результата должны показать 0.
-- EXCEPT DISTINCT, а не EXCEPT (= EXCEPT ALL): в ClickHouse 24.8 EXCEPT ALL в такой форме не находил расхождения.
SELECT 'only in PHP build' AS what, count() AS rows FROM (
    SELECT d, id_hotel, id_rate_room, id_room, id_rate, id_parent, id_country, id_region, id_city, stars, id_currency,
           id_board_type, id_cancel_policy, refundable, channel_mask, is_public, id_access_group, id_room_type, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.hotels_search_stay FINAL WHERE d >= today()
    EXCEPT DISTINCT
    SELECT d, id_hotel, id_rate_room, id_room, id_rate, id_parent, id_country, id_region, id_city, stars, id_currency,
           id_board_type, id_cancel_policy, refundable, channel_mask, is_public, id_access_group, id_room_type, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.hotels_search_stay_new
)
UNION ALL
SELECT 'only in SQL build', count() FROM (
    SELECT d, id_hotel, id_rate_room, id_room, id_rate, id_parent, id_country, id_region, id_city, stars, id_currency,
           id_board_type, id_cancel_policy, refundable, channel_mask, is_public, id_access_group, id_room_type, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.hotels_search_stay_new
    EXCEPT DISTINCT
    SELECT d, id_hotel, id_rate_room, id_room, id_rate, id_parent, id_country, id_region, id_city, stars, id_currency,
           id_board_type, id_cancel_policy, refundable, channel_mask, is_public, id_access_group, id_room_type, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv
    FROM unit_search.hotels_search_stay FINAL WHERE d >= today()
);

-- Вторая независимая проверка: хеш каждой строки, собранный в сумму по всей таблице (порядок не важен).
SELECT 'hash PHP build' AS what, sum(cityHash64(d, id_hotel, id_rate_room, id_room, id_rate, id_parent, id_country, id_region, id_city,
           stars, id_currency, id_board_type, id_cancel_policy, refundable, channel_mask, is_public, id_access_group, id_room_type, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv)) AS h, count() AS rows
FROM unit_search.hotels_search_stay FINAL WHERE d >= today()
UNION ALL
SELECT 'hash SQL build', sum(cityHash64(d, id_hotel, id_rate_room, id_room, id_rate, id_parent, id_country, id_region, id_city,
           stars, id_currency, id_board_type, id_cancel_policy, refundable, channel_mask, is_public, id_access_group, id_room_type, max_guests, gmask,
           c1, c2, c3, c4, c5, c6, c7, c8, k, avail, cta, ctd, min_los, max_los, min_adv, max_adv)), count()
FROM unit_search.hotels_search_stay_new;
