-- =====================================================================
--  Unit.Travel — Инвенторная система
--  Файл 04: ИНТЕГРАЦИОННЫЙ СЛОЙ — PMS и Channel Managers
--  СУБД: MySQL 5.6 / 5.7 (InnoDB, utf8mb4)
-- ---------------------------------------------------------------------
--  Аллотменты, тарифы, цены и ограничения могут обновляться из внешних
--  систем (PMS / Channel Manager) через ARI-сообщения
--  (Availability, Rates, Inventory).
--
--  Поток данных (inbound):
--    внешняя система ──push/pull──> ari_inbox (сырьё, идемпотентно)
--       ──PHP normalize──> external_mappings (перевод кодов)
--       ──apply (last-write-wins по external_rev)──> Слой 1 (config)
--       ──enqueue──> cache_rebuild_queue ──worker──> search_daily (Слой 2)
--
--  Ключевые принципы:
--    * Идемпотентность: message_uid уникален -> повторы отбрасываются.
--    * Порядок: применяем только если external_rev >= текущего в записи.
--    * Изоляция: приём сообщений НИКОГДА не блокирует горячий поиск —
--      inbox пишется отдельно, поиск читает только search_daily.
-- =====================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------------------------------------------------------------------
-- 1. ПРОВАЙДЕРЫ (типы внешних систем)
-- ---------------------------------------------------------------------
CREATE TABLE integration_providers (
  id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code      VARCHAR(40)  NOT NULL,      -- 'siteminder','travelclick','opera','1c-hotel'...
  name      VARCHAR(120) NOT NULL,
  kind      ENUM('pms','channel_manager','both') NOT NULL,
  protocol  ENUM('ota_htng','json_api','xml_api','csv','custom') NOT NULL DEFAULT 'json_api',
  push_supported TINYINT(1) NOT NULL DEFAULT 1,  -- умеет пушить нам ARI
  pull_supported TINYINT(1) NOT NULL DEFAULT 0,  -- умеем опрашивать его
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. ПОДКЛЮЧЕНИЯ (экземпляр интеграции для конкретного отеля)
--    Один отель может быть подключён к нескольким системам; одна система
--    может обслуживать много отелей. connection_id — источник изменений.
-- ---------------------------------------------------------------------
CREATE TABLE provider_connections (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id   SMALLINT UNSIGNED NOT NULL,
  hotel_id      INT UNSIGNED NOT NULL,
  external_hotel_code VARCHAR(64) NULL,          -- id отеля на стороне провайдера
  credentials_ref VARCHAR(120) NULL,             -- ССЫЛКА на секрет в vault (не сам ключ!)
  -- что этому подключению разрешено менять у нас:
  manages_rates        TINYINT(1) NOT NULL DEFAULT 1,
  manages_availability TINYINT(1) NOT NULL DEFAULT 1,
  manages_restrictions TINYINT(1) NOT NULL DEFAULT 1,
  status        ENUM('active','paused','error','disabled') NOT NULL DEFAULT 'active',
  last_msg_at   DATETIME NULL,                   -- последнее принятое сообщение
  last_pull_cursor VARCHAR(120) NULL,            -- курсор дельта-опроса (для pull)
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conn (provider_id, hotel_id),
  KEY idx_conn_hotel (hotel_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. МАППИНГ КОДОВ (наш id <-> внешний код провайдера)
--    Обязателен: ARI-сообщения приходят во внешних кодах, PHP переводит
--    их в наши id перед применением.
-- ---------------------------------------------------------------------
CREATE TABLE external_mappings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id INT UNSIGNED NOT NULL,
  entity_type   ENUM('room_type','rate_plan','board','occupancy') NOT NULL,
  internal_id   INT UNSIGNED NOT NULL,           -- наш id (room_type_id / rate_plan_id ...)
  external_code VARCHAR(80) NOT NULL,            -- код на стороне провайдера
  PRIMARY KEY (id),
  UNIQUE KEY uq_map_ext (connection_id, entity_type, external_code),
  KEY idx_map_int (connection_id, entity_type, internal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. INBOX — приёмник сырых ARI-сообщений (идемпотентный журнал)
--    Пишется мгновенно при получении; обрабатывается воркером асинхронно.
--    Хранит сырьё для аудита/повторной обработки/дебага.
-- ---------------------------------------------------------------------
CREATE TABLE ari_inbox (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id INT UNSIGNED NOT NULL,
  message_uid   VARCHAR(120) NOT NULL,           -- уникальный id сообщения у провайдера
  message_type  ENUM('rate','availability','restriction','mixed','full_sync') NOT NULL,
  external_rev  BIGINT UNSIGNED NULL,            -- seq/timestamp порядка от провайдера
  payload       MEDIUMTEXT NOT NULL,             -- сырой JSON/XML (в 5.7 можно JSON)
  status        ENUM('received','processing','applied','failed','skipped') NOT NULL DEFAULT 'received',
  error_text    VARCHAR(500) NULL,
  received_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at  DATETIME NULL,
  PRIMARY KEY (id),
  -- идемпотентность: один и тот же message_uid от подключения = 1 запись
  UNIQUE KEY uq_inbox_msg (connection_id, message_uid),
  KEY idx_inbox_status (status, id)              -- очередь обработки FIFO
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. OUTBOX — исходящие уведомления провайдеру (двусторонняя синхро)
--    Напр.: у нас упало наличие после брони с другого канала -> шлём CM.
--    Не на горячем пути; воркер вычитывает pending и доставляет.
-- ---------------------------------------------------------------------
CREATE TABLE ari_outbox (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id INT UNSIGNED NOT NULL,
  message_type  ENUM('rate','availability','restriction') NOT NULL,
  payload       MEDIUMTEXT NOT NULL,
  status        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_outbox_due (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. ОЧЕРЕДЬ ПЕРЕСБОРКИ КЭША
--    Применение ARI к Слою 1 ставит сюда задачу «пересобрать search_daily
--    для тарифа×диапазона». Воркер схлопывает пересечения и пересобирает
--    пачками -> поиск всегда читает согласованный кэш.
-- ---------------------------------------------------------------------
CREATE TABLE cache_rebuild_queue (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope         ENUM('rate_plan','room_type','hotel') NOT NULL,
  scope_id      INT UNSIGNED NOT NULL,           -- rate_plan_id / room_type_id / hotel_id
  date_from     DATE NOT NULL,
  date_to       DATE NOT NULL,
  reason        VARCHAR(40) NOT NULL,            -- 'ari_rate','ari_avail','booking','manual'
  status        ENUM('pending','processing','done') NOT NULL DEFAULT 'pending',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rebuild_due (status, id),
  KEY idx_rebuild_scope (scope, scope_id, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. ЖУРНАЛ СИНХРОНИЗАЦИИ (наблюдаемость)
-- ---------------------------------------------------------------------
CREATE TABLE sync_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id INT UNSIGNED NOT NULL,
  direction     ENUM('in','out') NOT NULL,
  message_type  VARCHAR(40) NOT NULL,
  rows_affected INT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('ok','partial','error') NOT NULL,
  detail        VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_synclog_conn (connection_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

-- =====================================================================
--  ПРИМЕРЫ КЛЮЧЕВЫХ ОПЕРАЦИЙ (реализует PHP-воркер)
-- =====================================================================

-- (a) Приём сообщения — идемпотентная вставка. Дубликат тихо игнорируется.
--     INSERT IGNORE отбросит повтор по uq_inbox_msg.
INSERT IGNORE INTO ari_inbox
  (connection_id, message_uid, message_type, external_rev, payload)
VALUES (:conn, :uid, :type, :rev, :payload);

-- (b) Применение обновления НАЛИЧИЯ с last-write-wins по external_rev.
--     Наличие ключуется на КАТЕГОРИЮ: CM шлёт «room» -> external_mappings
--     резолвит его в room_type_id. Обновляем только если пришедшая ревизия
--     не старше сохранённой и подключению разрешено управлять наличием.
INSERT INTO room_availability
   (room_type_id, stay_date, allotment, booked, blocked,
    managed_by, connection_id, external_rev, updated_at)
VALUES (:room_type_id, :date, :allotment, 0, 0,
        'channel_manager', :conn, :rev, NOW())
ON DUPLICATE KEY UPDATE
   allotment     = IF(:rev >= IFNULL(external_rev,0), VALUES(allotment), allotment),
   managed_by    = IF(:rev >= IFNULL(external_rev,0), VALUES(managed_by), managed_by),
   connection_id = IF(:rev >= IFNULL(external_rev,0), VALUES(connection_id), connection_id),
   external_rev  = IF(:rev >= IFNULL(external_rev,0), VALUES(external_rev), external_rev),
   updated_at    = NOW();

-- (c) Поставить задачу на пересборку кэша по затронутой категории и диапазону.
--     Воркер развернёт её в ВСЕ тарифы этого room_type.
INSERT INTO cache_rebuild_queue (scope, scope_id, date_from, date_to, reason)
VALUES ('room_type', :room_type_id, :date_from, :date_to, 'ari_avail');
