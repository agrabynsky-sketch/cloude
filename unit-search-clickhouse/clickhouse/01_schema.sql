-- =====================================================================
-- Unit.Travel search cache — ClickHouse schema (24.x)
--
-- Одна строка = один рум-рейт (hotels_rates_rooms.id) на одну дату d горизонта [сегодня; сегодня + 365].
-- Строки есть на КАЖДУЮ дату горизонта, даже если ночь не продаётся (нужно для нарастающих сумм и CTD).
--
-- Главная идея — нарастающие суммы:
--   c{g}(d) = сумма цен номера за g гостей по продаваемым ночам ДО даты d (в копейках/центах),
--   k(d)    = количество продаваемых ночей ДО даты d.
-- Для проживания [checkin; checkout):
--   цена номера  = c{g}(checkout) - c{g}(checkin)
--   все ночи ок  ⇔ k(checkout) - k(checkin) = nights
-- Поэтому поиск читает ровно 2 строки на рум-рейт при любой длине проживания.
-- Всё, что зависит от даты и тарифа (цены по числу гостей, дневные цены, наследование производного тарифа), считает сборщик.
-- Цену детей поиск считает из цены номера за проживание и детской политики отеля (статические колонки ниже).
--
-- Обновление: отель всегда пересобирается целиком (все его рум-рейты × 366 дат) с одной версией ver.
-- ReplacingMergeTree(ver, is_deleted) оставляет последнюю версию; SELECT ... FINAL видит только её.
-- Удалённый рум-рейт = строки с is_deleted = 1 (tombstone) и более новым ver.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS unit_search;

CREATE TABLE IF NOT EXISTS unit_search.hotels_search_stay
(
    d                Date,
    id_hotel         UInt32,
    id_rate_room     UInt32,                -- hotels_rates_rooms.id
    id_room          UInt32,                -- hotels_rooms.id
    id_rate          UInt32,                -- hotels_rates.id
    id_parent        UInt32,                -- hotels_rates.id_parent (0 = самостоятельный тариф)

    -- поля отеля (денормализованы, чтобы фильтровать без JOIN)
    id_country       UInt32,
    id_region        UInt32,
    id_city          UInt32,
    stars            UInt8,
    id_currency      UInt32,

    -- поля тарифа
    id_board_type    UInt8,                 -- hotels_board_types.id
    id_cancel_policy UInt32,                -- hotels_rates.id_cancel_policy (0 = невозвратный)
    refundable       UInt8,                 -- 1 = есть политика отмены (id_cancel_policy <> 0)
    channel_mask     UInt32,                -- биты hotels_sales_channels.bit
    is_public        UInt8,                 -- visibility = 'public'
    id_access_group  UInt32,                -- hotels_rates.access_group_id

    -- поля номера
    id_room_type     UInt32,                -- hotels_rooms.id_type
    base_occupancy   UInt8,                 -- базовое размещение рум-рейта (цена в календаре — за столько гостей), для ответа API
    max_guests       UInt8,                 -- максимум гостей, на который продаётся (старший бит gmask)
    gmask            UInt16,                -- бит g (1..8) = продаётся на g гостей; сюда уже зашиты max_adults, max_occupancy
                                            -- и выключенные строки окна по числу гостей

    -- вместимость и детская политика (статические для рум-рейта)
    max_children     UInt8,                 -- вместимость — только существующие спальные места
    max_occupancy    UInt8,
    max_infants      UInt8,                 -- младенцев 0-2 года максимум, 255 = без лимита
    infants_excluded UInt8,                 -- hotels_rooms.is_without_infants: младенец не считается в max_children / max_occupancy
    children_min_age UInt8,                 -- 255 = отель не принимает детей
    age_group        Array(UInt8),          -- 18 элементов: возраст 0..17 -> номер группы j (1..), 0 = взрослый
    child_type       Array(UInt8),          -- по группам j: 1 бесплатно, 2 фикс за ночь, 3 % тарифа на номер, 4 % цены взрослого,
                                            -- 5 полная цена взрослого
    child_value      Array(UInt32),         -- по группам j: фикс — копейки за ночь, процент — базисные пункты (50% = 5000)
    extra_beds       UInt8,                 -- доп. кроватей / детских кроваток можно поставить — только для условий проживания,
    cots             UInt8,                 -- в расчёте цены и вместимости не участвуют
    cots_and_extra_beds UInt8,              -- 1 = кроватки и доп. кровати одновременно, 0 = что-то одно

    -- нарастающие суммы по ночам ДО даты d (цены в минимальных единицах валюты отеля)
    c1 UInt64, c2 UInt64, c3 UInt64, c4 UInt64, c5 UInt64, c6 UInt64, c7 UInt64, c8 UInt64,   -- цена номера за g гостей
                                            -- ночь, где на g гостей не продаётся (дневная цена 0), добавляет 2^40:
                                            -- разность >= 2^40 = на g гостей проживание не продаётся
    k                UInt16,                -- число продаваемых ночей до d
    k2 UInt16, k3 UInt16, k4 UInt16,        -- из них ночей, где свободно не меньше 2 / 3 / 4 номеров (несколько номеров в запросе)

    -- атрибуты самой ночи d
    avail            UInt16,                -- свободно номеров в ночь d (0 = ночь не продаётся)
    cta              UInt8,                 -- closed to arrival в дату d
    ctd              UInt8,                 -- closed to departure в дату d
    min_los          UInt16,                -- для заезда в дату d (1 = нет ограничения)
    max_los          UInt16,                -- 999 = нет ограничения
    min_adv          UInt16,                -- дней до заезда, минимум (0 = нет)
    max_adv          UInt16,                -- 9999 = нет ограничения

    ver              UInt64,                -- версия сборки отеля (микросекунды)
    is_deleted       UInt8 DEFAULT 0
)
ENGINE = ReplacingMergeTree(ver, is_deleted)
ORDER BY (d, id_hotel, id_rate_room)
TTL d + INTERVAL 1 DAY DELETE
SETTINGS index_granularity = 1024;

-- Обновление существующей таблицы до этой версии: clickhouse/01b_upgrade_occupancy.sql (или пересоздать таблицу
-- и перезалить кэш full_load.sh — кэш полностью восстанавливается из MySQL).
--
-- Важно: порядок сортировки начинается с d. Поиск всегда фильтрует d IN (checkin, checkout),
-- поэтому ClickHouse читает только гранулы двух дат. С ORDER BY (id_hotel, d, ...) запрос читал почти всю таблицу.
-- index_granularity = 1024 (вместо 8192): карточка отеля читает ~22 тыс. строк вместо ~180 тыс., поиск тоже чуть быстрее.
