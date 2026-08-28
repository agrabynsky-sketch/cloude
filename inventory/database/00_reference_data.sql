-- =====================================================================
--  Unit.Travel — Инвенторная система
--  Файл 00: Справочные данные и вспомогательный календарь
-- =====================================================================

-- Типы питания
INSERT INTO board_types (code, name) VALUES
  ('RO','Room Only'),
  ('BB','Bed & Breakfast'),
  ('HB','Half Board'),
  ('FB','Full Board'),
  ('AI','All Inclusive');

-- Каналы продаж (bit = степень двойки; используется в channel_mask)
INSERT INTO sales_channels (bit, code, name) VALUES
  (1,  'web',    'Website B2C'),
  (2,  'b2b',    'B2B / Agents'),
  (4,  'mobile', 'Mobile app'),
  (8,  'api',    'API partners');

-- ---------------------------------------------------------------------
--  Вспомогательный календарь дат — нужен для разворота периодов в
--  посуточные строки кэша (JOIN calendar). Заполняется на N лет вперёд.
-- ---------------------------------------------------------------------
CREATE TABLE calendar (
  d DATE NOT NULL,
  PRIMARY KEY (d)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Пример заполнения на PHP:
--   $d = new DateTime('2026-01-01'); $end = new DateTime('2028-12-31');
--   while ($d <= $end) { INSERT INTO calendar (d) VALUES ($d); $d->modify('+1 day'); }
