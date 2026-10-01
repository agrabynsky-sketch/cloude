-- =====================================================================
-- Вместимость номера, цены по числу гостей и детская политика отеля: новые поля и таблицы (MySQL 5.7).
-- Правила расчёта — README, раздел «Вместимость, дети и несколько номеров».
--
-- Скрипт можно запускать повторно: каждая колонка добавляется / удаляется только если её ещё нет / она есть.
-- Он же обновляет базу, на которой стояла прошлая версия прототипа (places_regular, share_slots,
-- hotels.extra_bed_adult_price, hotels_children_prices, группы по тарифам) — эти объекты удаляются.
-- Таблицы маленькие (отели, номера, рум-рейты, надбавки, детские группы), ALTER выполняется быстро и без pt-osc.
-- Запускать до 02_demo_data.sql.
-- =====================================================================

DROP PROCEDURE IF EXISTS hotels_search_ddl;

DELIMITER $$

-- выполнить p_sql, если у таблицы p_table колонка p_column есть (p_if_exists = 1) или её нет (p_if_exists = 0)
CREATE PROCEDURE hotels_search_ddl(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_if_exists TINYINT, IN p_sql TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column) = p_if_exists THEN
    SET @hotels_search_ddl_sql = p_sql;
    PREPARE hotels_search_ddl_stmt FROM @hotels_search_ddl_sql;
    EXECUTE hotels_search_ddl_stmt;
    DEALLOCATE PREPARE hotels_search_ddl_stmt;
  END IF;
END$$

DELIMITER ;

-- ---------- номер: вместимость и доп. кровати
-- «Макс. гостей / взрослых / детей» (max_occupancy / max_adults / max_children) — только существующие спальные места,
-- как у Booking.com. Доп. кровати и детские кроватки вместимость не увеличивают и в поиске не участвуют:
-- их количество и цены — только примечание в условиях проживания.
--   max_infants          — макс. младенцев (0-2 года) в номере: NULL — без отдельного лимита, 0 — младенцы не принимаются.
--                          При is_without_infants = 1 младенцы не считаются в max_occupancy / max_children, но этот лимит действует;
--   extra_beds, cots     — сколько доп. кроватей / детских кроваток можно поставить (условия проживания, в расчёте не участвуют);
--   cots_and_extra_beds  — 1 = кроватки и доп. кровати можно поставить одновременно («и»), 0 = что-то одно («или»).
-- hotels_rooms.pricing_model поиском больше не используется: модель цены следует из окна цен по числу гостей
-- (есть строки hotels_rates_occupancy — цены по числу гостей, нет — одна цена за номер для любого числа взрослых).
CALL hotels_search_ddl('hotels_rooms', 'max_infants', 0,
  'ALTER TABLE hotels_rooms ADD COLUMN max_infants TINYINT UNSIGNED NULL DEFAULT NULL
     COMMENT ''Макс. младенцев 0-2 года: NULL = без лимита, 0 = не принимаются''');
CALL hotels_search_ddl('hotels_rooms', 'extra_beds', 0,
  'ALTER TABLE hotels_rooms ADD COLUMN extra_beds TINYINT UNSIGNED NOT NULL DEFAULT 0
     COMMENT ''Доп. кроватей можно поставить (условия проживания, в поиске не участвует)''');
CALL hotels_search_ddl('hotels_rooms', 'cots', 0,
  'ALTER TABLE hotels_rooms ADD COLUMN cots TINYINT UNSIGNED NOT NULL DEFAULT 0
     COMMENT ''Детских кроваток можно поставить (условия проживания, в поиске не участвует)''');
CALL hotels_search_ddl('hotels_rooms', 'cots_and_extra_beds', 0,
  'ALTER TABLE hotels_rooms ADD COLUMN cots_and_extra_beds TINYINT(1) NOT NULL DEFAULT 0
     COMMENT ''1 = кроватки и доп. кровати одновременно («и»), 0 = что-то одно («или»)''');

-- ---------- рум-рейт: базовое размещение (у Booking.com — leading occupancy)
-- NULL — как у номера (hotels_rooms.base_occupancy). У производного тарифа не редактируется: берётся у родителя.
-- Цена в календаре — за base_occupancy гостей, строки hotels_rates_occupancy — надбавки/скидки к ней для другого числа гостей.
CALL hotels_search_ddl('hotels_rates_rooms', 'base_occupancy', 0,
  'ALTER TABLE hotels_rates_rooms ADD COLUMN base_occupancy TINYINT UNSIGNED NULL DEFAULT NULL
     COMMENT ''Базовое размещение рум-рейта; NULL = hotels_rooms.base_occupancy''');

-- ---------- окно «цены для другого числа гостей»: процент или сумма
--   amount_type 0 — amount в процентах к цене за base_occupancy (как раньше), 1 — сумма за ночь в валюте отеля (может быть < 0);
--   active = 0 — на это число гостей рум-рейт не продаётся.
CALL hotels_search_ddl('hotels_rates_occupancy', 'amount_type', 0,
  'ALTER TABLE hotels_rates_occupancy ADD COLUMN amount_type TINYINT UNSIGNED NOT NULL DEFAULT 0
     COMMENT ''0 = amount в %, 1 = amount — сумма за ночь'' AFTER guests');

-- ---------- детская политика отеля: возрастные группы (экран «Children's prices by age», до 4 групп на возраст 0-17)
--   price_type: 1 — бесплатно;
--               2 — фикс за ребёнка за ночь (price_value в валюте отеля);
--               3 — % тарифа на номер (price_value — проценты от цены номера для проживающих);
--               4 — % цены взрослого (цена номера / число гостей, за которое взята цена, × price_value %);
--               5 — полная цена взрослого (цена номера / число гостей).
--   Возраст, не попавший ни в одну группу, считается взрослым (и для цены, и для вместимости).
--   Дети младше hotels.children_min_age и любые дети при hotels.allow_children = 0 не принимаются.
--   Политика одна на отель: группы по тарифам и календарные исключения цен — следующий этап.
CREATE TABLE IF NOT EXISTS hotels_children_groups (
  id          INT(11)          NOT NULL AUTO_INCREMENT,
  id_hotel    INT(11)          NOT NULL DEFAULT 0,
  age_from    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  age_to      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'включительно, полных лет (0-2 = до 2,99)',
  price_type  TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 бесплатно, 2 фикс за ночь, 3 % тарифа на номер, 4 % цены взрослого, 5 полная цена взрослого',
  price_value DECIMAL(20,2)    NOT NULL DEFAULT 0.00,
  active      TINYINT(4)       NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_hotel (id_hotel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------- обновление базы с прошлой версией прототипа (на чистой базе ничего не делает)
-- группы: цена «на существующей кровати» -> одна цена группы (1 бесплатно, 2 фикс, 3 «% от одноместной» -> 4 «% цены взрослого»);
-- группы отдельных тарифов удаляются (политика теперь одна на отель)
CALL hotels_search_ddl('hotels_children_groups', 'price_type', 0,
  'ALTER TABLE hotels_children_groups
     ADD COLUMN price_type TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER age_to,
     ADD COLUMN price_value DECIMAL(20,2) NOT NULL DEFAULT 0.00 AFTER price_type');
CALL hotels_search_ddl('hotels_children_groups', 'bed_type', 1,
  'UPDATE hotels_children_groups SET price_type = CASE bed_type WHEN 2 THEN 2 WHEN 3 THEN 4 ELSE 1 END, price_value = bed_value');
CALL hotels_search_ddl('hotels_children_groups', 'bed_type', 1,
  'ALTER TABLE hotels_children_groups DROP COLUMN bed_type, DROP COLUMN bed_value, DROP COLUMN extra_type, DROP COLUMN extra_value');
CALL hotels_search_ddl('hotels_children_groups', 'id_rate', 1,
  'DELETE FROM hotels_children_groups WHERE id_rate > 0');
CALL hotels_search_ddl('hotels_children_groups', 'id_rate', 1,
  'ALTER TABLE hotels_children_groups DROP KEY idx_hotel, DROP COLUMN id_rate, ADD KEY idx_hotel (id_hotel)');
DROP TABLE IF EXISTS hotels_children_prices;
CALL hotels_search_ddl('hotels', 'extra_bed_adult_price', 1, 'ALTER TABLE hotels DROP COLUMN extra_bed_adult_price');
CALL hotels_search_ddl('hotels_rooms', 'places_regular', 1, 'ALTER TABLE hotels_rooms DROP COLUMN places_regular');
CALL hotels_search_ddl('hotels_rooms', 'share_slots', 1, 'ALTER TABLE hotels_rooms DROP COLUMN share_slots');

DROP PROCEDURE hotels_search_ddl;

-- ---------- переход с pricing_model (выполнить вручную после проверки, в поиске модель больше не используется)
-- У номеров «per room» (pricing_model = 1) строки окна по числу гостей раньше не действовали, теперь действуют.
-- Если такие строки остались от старых настроек — цены изменятся. Проверка (должно быть 0 строк или осознанные строки):
--   SELECT o.* FROM hotels_rates_occupancy o JOIN hotels_rooms r ON r.id = o.id_room WHERE r.pricing_model = 1;
--   SELECT COUNT(*) FROM hotels_rates_occupancy_daily d JOIN hotels_rooms r ON r.id = d.id_room WHERE r.pricing_model = 1;
-- Производный тариф теперь наследует цены по числу гостей родителя; свои строки окна у него — осознанное исключение
-- (считаются от цены производного тарифа). Проверка своих строк у производных рум-рейтов:
--   SELECT o.* FROM hotels_rates_occupancy o JOIN hotels_rates t ON t.id = o.id_rate WHERE t.id_parent > 0;

-- Проверки, которые стоит сделать в экстранете при сохранении (в поиске они не нужны, но без них отели заведут странное):
--   * группы идут без пересечений и без пропусков от 0 (или от children_min_age): иначе младенец без группы считается взрослым;
--   * max_occupancy >= max_adults, max_children <= max_occupancy; max_infants <= max_children, если младенцы учитываются;
--   * у производного рум-рейта base_occupancy только для чтения (как у родителя); в окне по числу гостей пустая строка =
--     «как у родителя» (цена родителя за это число гостей с изменением тарифа), заполненная — % или сумма к цене производного;
--   * подсказка в окне: «проценты и суммы — к цене в календаре за base_occupancy гостей».
