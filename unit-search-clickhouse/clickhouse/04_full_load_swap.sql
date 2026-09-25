-- Атомарная подмена: новая таблица становится рабочей, старая удаляется вместе со staging-таблицами.
EXCHANGE TABLES unit_search.search_stay AND unit_search.search_stay_new;
DROP TABLE IF EXISTS unit_search.search_stay_new;
DROP TABLE IF EXISTS unit_search.stg_hotels;
DROP TABLE IF EXISTS unit_search.stg_rooms;
DROP TABLE IF EXISTS unit_search.stg_rates;
DROP TABLE IF EXISTS unit_search.stg_rr;
DROP TABLE IF EXISTS unit_search.stg_prices;
DROP TABLE IF EXISTS unit_search.stg_avail;
DROP TABLE IF EXISTS unit_search.stg_occ;
DROP TABLE IF EXISTS unit_search.stg_rr_ext;
