-- =====================================================================
-- Пользователи ClickHouse для поискового слоя.
-- Выполнять под администратором с access_management = 1.
-- double_sha1_password нужен, чтобы пользователь мог входить и через MySQL-интерфейс (порт 9004,
-- PDO mysql / mysqli используют mysql_native_password). HTTP (8123) этот тип пароля тоже принимает.
-- Пароли замените на свои.
-- =====================================================================

-- синхронизация (CLI-воркер): читает и пишет
CREATE USER IF NOT EXISTS search_writer IDENTIFIED WITH double_sha1_password BY 'change_me_writer'
    SETTINGS max_insert_block_size = 1048576;
GRANT SELECT, INSERT, OPTIMIZE ON unit_search.* TO search_writer;
-- для полной пересборки силами ClickHouse (clickhouse/full_load.sh): staging-таблицы, EXCHANGE TABLES,
-- чтение MySQL через named collection unit_mysql (config.d/unit_mysql.xml)
GRANT CREATE TABLE, DROP TABLE, TRUNCATE, SHOW TABLES ON unit_search.* TO search_writer;
GRANT CREATE TEMPORARY TABLE, MYSQL ON *.* TO search_writer;
GRANT NAMED COLLECTION ON unit_mysql TO search_writer;

-- сайт / API: только чтение, жёсткие лимиты на запрос
CREATE USER IF NOT EXISTS search_reader IDENTIFIED WITH double_sha1_password BY 'change_me_reader'
    SETTINGS readonly = 2, max_execution_time = 3, max_memory_usage = 2000000000, max_result_rows = 200000;
GRANT SELECT ON unit_search.* TO search_reader;
