-- =====================================================================
-- Очередь "грязных" отелей для синхронизации MySQL -> ClickHouse.
--
-- Любой код, меняющий цены / наличие / тарифы / номера / надбавки / бронирования отеля,
-- вызывает Search_Sync_Queue::getInstance()->push($hotelId, 'reason') — желательно в той же транзакции.
-- Одна строка на отель: повторные изменения только увеличивают version.
-- Воркер (scripts/search-sync.php worker) атомарно забирает пачку отелей, пересобирает их целиком
-- и удаляет строку, только если version не изменилась за время сборки (иначе отель соберётся ещё раз).
-- =====================================================================

CREATE TABLE IF NOT EXISTS hotels_search_sync_queue (
  id_hotel   INT UNSIGNED      NOT NULL,
  version    INT UNSIGNED      NOT NULL DEFAULT 1,
  reason     VARCHAR(32)       NOT NULL DEFAULT '',
  queued_at  DATETIME          NOT NULL,
  claimed_by VARCHAR(64)       NULL,
  claimed_at DATETIME          NULL,
  attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255)      NULL,
  PRIMARY KEY (id_hotel),
  KEY idx_claim (claimed_by, queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
