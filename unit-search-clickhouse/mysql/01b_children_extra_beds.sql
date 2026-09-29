-- =====================================================================
-- Дети, доп. кровати и доп. взрослые: новые поля и таблицы (MySQL 5.7).
-- Правила расчёта — README, раздел «Дети, доп. кровати и несколько номеров».
--
-- Таблицы маленькие (отели, номера, детские правила), ALTER выполняется быстро и без pt-osc.
-- Запускать до 02_demo_data.sql. Повторный запуск упадёт на ADD COLUMN — это нормально.
-- =====================================================================

-- ---------- отель: доплата за взрослого сверх base_occupancy (per room) = цена доп. кровати взрослого за ночь
-- NULL — доп. взрослые сверх base_occupancy в per room не принимаются; 0 — бесплатно.
-- Если у вас эта настройка уже хранится в другом месте, поменяйте источник в Search_Sync_Builder и 03_full_load_from_mysql.sql.
ALTER TABLE hotels
  ADD COLUMN extra_bed_adult_price DECIMAL(20,2) NULL DEFAULT NULL
    COMMENT 'Доплата за взрослого сверх base_occupancy (per room), за ночь. NULL = не принимаются, 0 = бесплатно';

-- ---------- номер: места
--   places_regular (R) — основные спальные места. NULL = не заполнено: считаем max(base_occupancy, max_adults),
--                        то есть все взрослые помещаются на основные места (поведение как до доработки);
--   extra_beds     (E) — сколько доп. кроватей можно поставить;
--   share_slots    (S) — сколько детей могут спать в существующих кроватях вместе со взрослыми.
ALTER TABLE hotels_rooms
  ADD COLUMN places_regular TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Основные спальные места (R). NULL = max(base_occupancy, max_adults)',
  ADD COLUMN extra_beds TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Доп. кроватей максимум (E)',
  ADD COLUMN share_slots TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Детей в существующих кроватях вместе со взрослыми (S)';

-- ---------- детская политика: возрастные группы с ценами по умолчанию (экран «Children & Beds»)
--   id_rate = 0  — группы отеля, действуют для всех тарифов;
--   id_rate > 0  — свои группы тарифа: если у тарифа есть хотя бы одна активная группа, группы отеля для него не действуют.
--   Цена ребёнка задаётся отдельно для места «на существующей кровати» (bed_*) и «на доп. кровати» (extra_*):
--     type 0 — нельзя, 1 — бесплатно, 2 — фикс за ночь (value в валюте отеля), 3 — % от одноместной цены за ночь (value — проценты).
--   Возраст, не попавший ни в одну группу, считается взрослым. Дети младше hotels.children_min_age и любые дети
--   при hotels.allow_children = 0 не принимаются.
CREATE TABLE IF NOT EXISTS hotels_children_groups (
  id          INT(11)          NOT NULL AUTO_INCREMENT,
  id_hotel    INT(11)          NOT NULL DEFAULT 0,
  id_rate     INT(11)          NOT NULL DEFAULT 0 COMMENT '0 = группы отеля для всех тарифов',
  age_from    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  age_to      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'включительно, полных лет (0-2 = до 2,99)',
  bed_type    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'На существующей кровати: 0 нельзя, 1 бесплатно, 2 фикс за ночь, 3 % от одноместной',
  bed_value   DECIMAL(20,2)    NOT NULL DEFAULT 0.00,
  extra_type  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'На доп. кровати: 0 нельзя, 1 бесплатно, 2 фикс за ночь, 3 % от одноместной',
  extra_value DECIMAL(20,2)    NOT NULL DEFAULT 0.00,
  active      TINYINT(4)       NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_hotel (id_hotel, id_rate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------- исключения цен группы: по тарифу и/или по датам (календарные акции «дети бесплатно» и т.п.)
--   id_rate = 0 — для всех тарифов, где действует группа; > 0 — только для этого тарифа.
--   date_from / date_to — включительно, NULL = без ограничения.
--   На каждую ночь берётся самое точное правило: тариф важнее «всех тарифов», с датами важнее «без дат»,
--   при равенстве — больший id. Нет подходящей строки — цены самой группы.
--   Границы возрастов по датам не меняются (они в hotels_children_groups), по датам и тарифам меняются только цены.
CREATE TABLE IF NOT EXISTS hotels_children_prices (
  id          INT(11)          NOT NULL AUTO_INCREMENT,
  id_hotel    INT(11)          NOT NULL DEFAULT 0 COMMENT 'дублирует группу: выборка по отелю без JOIN',
  id_group    INT(11)          NOT NULL DEFAULT 0 COMMENT 'hotels_children_groups.id',
  id_rate     INT(11)          NOT NULL DEFAULT 0 COMMENT '0 = все тарифы группы',
  date_from   DATE             NULL DEFAULT NULL,
  date_to     DATE             NULL DEFAULT NULL,
  bed_type    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  bed_value   DECIMAL(20,2)    NOT NULL DEFAULT 0.00,
  extra_type  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  extra_value DECIMAL(20,2)    NOT NULL DEFAULT 0.00,
  active      TINYINT(4)       NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_hotel (id_hotel),
  KEY idx_group (id_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Проверки, которые стоит сделать в экстранете при сохранении (в поиске они не нужны, но без них отели заведут странное):
--   * группы идут без пересечений и без пропусков от children_min_age; взрослыми остаются только старшие возрасты;
--   * base_occupancy <= places_regular; max_adults <= places_regular + extra_beds;
--   * max_occupancy <= places_regular + extra_beds + share_slots;
--   * для per guest (pricing_model = 2) подсказка: доплата за доп. кровать взрослому не начисляется, цена «за 3» уже её включает;
--   * «% от одноместной» в per room = % от цены номера.
