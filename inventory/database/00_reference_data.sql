-- =====================================================================
--  Unit.Travel — Инвенторная система
--  Файл 00: Справочные данные и вспомогательный календарь
-- =====================================================================

-- Виды из окна (room.room_view)
INSERT INTO room_views (id, code, name) VALUES
  (0, 'unknown',  'Unknown'),
  (1, 'city',     'City view'),
  (2, 'garden',   'Garden view'),
  (3, 'sea',      'Sea view'),
  (4, 'lake',     'Lake view'),
  (5, 'mountain', 'Mountain view');   -- в тз было дублем 'lake'; уточнить

-- Глобальные возрастные группы (child_age_bands).
-- Инфанты (in_occupancy=0) не тарифицируются и не входят в occ_key.
-- Позиции b1,b2,b3 в occ_key = тарифицируемые бэнды по порядку (young,child,teen).
INSERT INTO child_age_bands (id, code, name, age_from, age_to, in_occupancy) VALUES
  (1, 'infant', 'Infant 0-1',      0,  1, 0),   -- отдельно: room.max_infants
  (2, 'young',  'Child 2-6',       2,  6, 1),   -- occ_key b1
  (3, 'child',  'Child 7-12',      7, 12, 1),   -- occ_key b2
  (4, 'teen',   'Teen 13-17',     13, 17, 1);   -- occ_key b3
-- Возраст >= 18 считается взрослым (adults).

-- Типы кроватей (room_space_beds.bed_type_id)
INSERT INTO bed_types (code, name, sleeps) VALUES
  ('single', 'Single bed',       1),
  ('double', 'Double bed',       2),
  ('queen',  'Queen bed',        2),
  ('king',   'King bed',         2),
  ('twin',   'Twin bed',         1),
  ('sofa',   'Sofa bed',         1),
  ('bunk',   'Bunk bed',         2),
  ('crib',   'Crib / cot',       1);

-- Типы питания
INSERT INTO board_types (code, name) VALUES
  ('RO','Room Only'),
  ('BB','Bed & Breakfast'),
  ('HB','Half Board'),
  ('FB','Full Board'),
  ('AI','All Inclusive');

-- Каналы продаж (bit = степень двойки; используется в channel_mask).
-- Это «ось 1» видимости — широкая аудитория дистрибуции. «Ось 2»
-- (public/private + access_group) гейтит приватные/negotiated тарифы.
INSERT INTO sales_channels (bit, code, name) VALUES
  (1,  'web',    'Website B2C'),
  (2,  'b2b',    'B2B / Agents'),
  (4,  'b2b2c',  'B2B2C / White-label'),
  (8,  'corp',   'Corporate'),
  (16, 'mobile', 'Mobile app'),
  (32, 'api',    'API partners');

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
