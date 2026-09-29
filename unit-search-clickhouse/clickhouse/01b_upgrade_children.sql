-- Обновление hotels_search_stay, созданной до поддержки детей и доп. кроватей.
-- Колонки добавляются в те же места, что в 01_schema.sql (порядок важен для INSERT ... SELECT в 03b_build_chunk.sql).
-- После ALTER кэш нужно пересобрать целиком: full_load.sh или php scripts/search-sync.php full
-- (до пересборки старые строки имеют пустые массивы и поиск с детьми их не найдёт).
ALTER TABLE unit_search.hotels_search_stay
    ADD COLUMN IF NOT EXISTS places_regular   UInt8         AFTER gmask,
    ADD COLUMN IF NOT EXISTS extra_beds       UInt8         AFTER places_regular,
    ADD COLUMN IF NOT EXISTS share_slots      UInt8         AFTER extra_beds,
    ADD COLUMN IF NOT EXISTS max_children     UInt8         AFTER share_slots,
    ADD COLUMN IF NOT EXISTS max_occupancy    UInt8         AFTER max_children,
    ADD COLUMN IF NOT EXISTS infants_excluded UInt8         AFTER max_occupancy,
    ADD COLUMN IF NOT EXISTS children_min_age UInt8         AFTER infants_excluded,
    ADD COLUMN IF NOT EXISTS age_group        Array(UInt8)  AFTER children_min_age,
    ADD COLUMN IF NOT EXISTS k2               UInt16        AFTER k,
    ADD COLUMN IF NOT EXISTS k3               UInt16        AFTER k2,
    ADD COLUMN IF NOT EXISTS k4               UInt16        AFTER k3,
    ADD COLUMN IF NOT EXISTS cb               Array(UInt64) AFTER k4,
    ADD COLUMN IF NOT EXISTS ce               Array(UInt64) AFTER cb;
