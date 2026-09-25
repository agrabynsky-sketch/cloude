-- =====================================================================
-- Unit.Travel search cache — ClickHouse schema (24.x)
--
-- Одна строка = один рум-рейт (hotels_rates_rooms.id) на одну дату d горизонта [сегодня; сегодня + 365].
-- Строки есть на КАЖДУЮ дату горизонта, даже если ночь не продаётся (нужно для нарастающих сумм и CTD).
--
-- Главная идея — нарастающие суммы:
--   c{g}(d) = сумма цен продаваемых ночей ДО даты d для g гостей (в копейках/центах),
--   k(d)    = количество продаваемых ночей ДО даты d.
-- Для проживания [checkin; checkout):
--   цена         = c{g}(checkout) - c{g}(checkin)
--   все ночи ок  ⇔ k(checkout) - k(checkin) = nights
-- Поэтому поиск читает ровно 2 строки на рум-рейт при любой длине проживания.
--
-- Обновление: отель всегда пересобирается целиком (все его рум-рейты × 366 дат) с одной версией ver.
-- ReplacingMergeTree(ver, is_deleted) оставляет последнюю версию; SELECT ... FINAL видит только её.
-- Удалённый рум-рейт = строки с is_deleted = 1 (tombstone) и более новым ver.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS unit_search;

CREATE TABLE IF NOT EXISTS unit_search.search_stay
(
    d               Date,
    hotel_id        UInt32,
    rate_room_id    UInt32,                 -- hotels_rates_rooms.id
    room_id         UInt32,                 -- hotels_rooms.id
    rate_id         UInt32,                 -- hotels_rates.id
    parent_rate_id  UInt32,                 -- hotels_rates.id_parent (0 = самостоятельный тариф)

    -- поля отеля (денормализованы, чтобы фильтровать без JOIN)
    country_id      UInt32,
    region_id       UInt32,
    city_id         UInt32,
    stars           UInt8,
    currency_id     UInt32,

    -- поля тарифа
    board_id        UInt8,                  -- hotels_board_types.id
    refundable      UInt8,                  -- 1 = есть политика отмены (id_cancel_policy <> 0)
    channel_mask    UInt32,                 -- биты hotels_sales_channels.bit
    is_public       UInt8,                  -- visibility = 'public'
    access_group_id UInt32,

    -- поля номера
    room_type_id    UInt32,
    max_guests      UInt8,
    gmask           UInt16,                 -- бит g (1..8) = продаётся на g гостей

    -- нарастающие суммы по ночам ДО даты d (цены в минимальных единицах валюты отеля)
    c1 UInt64, c2 UInt64, c3 UInt64, c4 UInt64, c5 UInt64, c6 UInt64, c7 UInt64, c8 UInt64,
    k               UInt16,                 -- число продаваемых ночей до d

    -- атрибуты самой ночи d
    avail           UInt16,                 -- свободно номеров в ночь d (0 = ночь не продаётся)
    cta             UInt8,                  -- closed to arrival в дату d
    ctd             UInt8,                  -- closed to departure в дату d
    min_los         UInt16,                 -- для заезда в дату d (1 = нет ограничения)
    max_los         UInt16,                 -- 999 = нет ограничения
    min_adv         UInt16,                 -- дней до заезда, минимум (0 = нет)
    max_adv         UInt16,                 -- 9999 = нет ограничения

    ver             UInt64,                 -- версия сборки отеля (микросекунды)
    is_deleted      UInt8 DEFAULT 0
)
ENGINE = ReplacingMergeTree(ver, is_deleted)
ORDER BY (d, hotel_id, rate_room_id)
TTL d + INTERVAL 1 DAY DELETE
SETTINGS index_granularity = 1024;

-- Важно: порядок сортировки начинается с d. Поиск всегда фильтрует d IN (checkin, checkout),
-- поэтому ClickHouse читает только гранулы двух дат. С ORDER BY (hotel_id, d, ...) запрос читал почти всю таблицу.
-- index_granularity = 1024 (вместо 8192): карточка отеля читает ~22 тыс. строк вместо ~180 тыс., поиск тоже чуть быстрее.
