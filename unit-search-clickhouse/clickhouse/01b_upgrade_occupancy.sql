-- Обновление hotels_search_stay до версии «вместимость + детская политика отеля» (с версии без детей или с прошлой версии
-- прототипа, где были places_regular / share_slots / cb / ce). Колонки ставятся в те же места, что в 01_schema.sql.
-- После ALTER кэш нужно пересобрать целиком: full_load.sh или php scripts/search-sync.php full
-- (до пересборки старые строки имеют пустые массивы и нули в новых колонках).
-- Проще и надёжнее — пересоздать таблицу по 01_schema.sql и перезалить кэш: он полностью восстанавливается из MySQL.
ALTER TABLE unit_search.hotels_search_stay
    DROP COLUMN IF EXISTS places_regular,
    DROP COLUMN IF EXISTS share_slots,
    DROP COLUMN IF EXISTS cb,
    DROP COLUMN IF EXISTS ce;
ALTER TABLE unit_search.hotels_search_stay
    ADD COLUMN IF NOT EXISTS base_occupancy      UInt8         AFTER id_room_type,
    ADD COLUMN IF NOT EXISTS max_children        UInt8         AFTER gmask,
    ADD COLUMN IF NOT EXISTS max_occupancy       UInt8         AFTER max_children,
    ADD COLUMN IF NOT EXISTS max_infants         UInt8         AFTER max_occupancy,
    ADD COLUMN IF NOT EXISTS infants_excluded    UInt8         AFTER max_infants,
    ADD COLUMN IF NOT EXISTS children_min_age    UInt8         AFTER infants_excluded,
    ADD COLUMN IF NOT EXISTS age_group           Array(UInt8)  AFTER children_min_age,
    ADD COLUMN IF NOT EXISTS child_type          Array(UInt8)  AFTER age_group,
    ADD COLUMN IF NOT EXISTS child_value         Array(UInt32) AFTER child_type,
    ADD COLUMN IF NOT EXISTS extra_beds          UInt8         AFTER child_value,
    ADD COLUMN IF NOT EXISTS cots                UInt8         AFTER extra_beds,
    ADD COLUMN IF NOT EXISTS cots_and_extra_beds UInt8         AFTER cots,
    ADD COLUMN IF NOT EXISTS k2                  UInt16        AFTER k,
    ADD COLUMN IF NOT EXISTS k3                  UInt16        AFTER k2,
    ADD COLUMN IF NOT EXISTS k4                  UInt16        AFTER k3;
-- порядок колонок как в 01_schema.sql (в старой таблице extra_beds стояла сразу после gmask); меняются только метаданные
ALTER TABLE unit_search.hotels_search_stay
    MODIFY COLUMN max_children        UInt8         AFTER gmask,
    MODIFY COLUMN max_occupancy       UInt8         AFTER max_children,
    MODIFY COLUMN max_infants         UInt8         AFTER max_occupancy,
    MODIFY COLUMN infants_excluded    UInt8         AFTER max_infants,
    MODIFY COLUMN children_min_age    UInt8         AFTER infants_excluded,
    MODIFY COLUMN age_group           Array(UInt8)  AFTER children_min_age,
    MODIFY COLUMN child_type          Array(UInt8)  AFTER age_group,
    MODIFY COLUMN child_value         Array(UInt32) AFTER child_type,
    MODIFY COLUMN extra_beds          UInt8         AFTER child_value,
    MODIFY COLUMN cots                UInt8         AFTER extra_beds,
    MODIFY COLUMN cots_and_extra_beds UInt8         AFTER cots;
