-- Атомарная подмена: новая таблица становится рабочей, старая удаляется вместе со staging-таблицами.
EXCHANGE TABLES unit_search.hotels_search_stay AND unit_search.hotels_search_stay_new;
DROP TABLE IF EXISTS unit_search.hotels_search_stay_new;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_hotels;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rooms;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates_rooms;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_prices;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_availability;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_occupancy;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_occupancy_daily;
DROP TABLE IF EXISTS unit_search.hotels_search_stg_rates_rooms_ext;
