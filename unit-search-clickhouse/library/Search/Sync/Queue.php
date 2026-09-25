<?php

/**
 * Очередь отелей на пересборку поискового кэша (MySQL-таблица hotels_search_sync_queue).
 *
 * Постановка в очередь (из любого места, где меняются данные отеля):
 *   Search_Sync_Queue::getInstance()->push($hotelId, 'bulkedit');
 *   Search_Sync_Queue::getInstance()->push(array(1, 2, 3), 'nightly');
 *
 * Работает через Zend_Db-адаптер MySQL (по умолчанию Zend_Db_Table_Abstract::getDefaultAdapter()).
 */
class Search_Sync_Queue {
    const TABLE = 'hotels_search_sync_queue';
    const STALE_CLAIM_MINUTES = 10;   // забранные, но не завершённые отели (упавший воркер) вернутся в работу

    protected static $_instance;
    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;

    public function __construct($db = null) {
        $this->_db = $db ? $db : Zend_Db_Table_Abstract::getDefaultAdapter();
    }

    /**
     * @return Search_Sync_Queue
     */
    public static function getInstance() {
        if(!self::$_instance) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public static function setInstance(Search_Sync_Queue $queue) {
        self::$_instance = $queue;
    }

    /**
     * Пометить отели "грязными". Идемпотентно: повторный вызов лишь увеличивает version.
     * @param int|array $hotelIds
     * @param string $reason
     * @return int
     */
    public function push($hotelIds, $reason = '') {
        $hotelIds = array_unique(array_filter(array_map('intval', (array)$hotelIds)));
        if(empty($hotelIds)) {
            return 0;
        }
        $reason = $this->_db->quote(substr((string)$reason, 0, 32));
        foreach(array_chunk($hotelIds, 1000) as $chunk) {
            $values = array();
            foreach($chunk as $id) {
                $values[] = '(' . $id . ', 1, ' . $reason . ', NOW())';
            }
            $this->_db->query('INSERT INTO ' . self::TABLE . ' (id_hotel, version, reason, queued_at) VALUES ' . implode(',', $values) .
                ' ON DUPLICATE KEY UPDATE version = version + 1, reason = VALUES(reason), queued_at = VALUES(queued_at)');
        }
        return count($hotelIds);
    }

    /**
     * Атомарно забрать до $limit отелей. Возвращает array(id_hotel => version).
     * @param string $token уникальный идентификатор воркера
     * @param int $limit
     * @return array
     */
    public function claim($token, $limit = 100) {
        $this->_db->query('UPDATE ' . self::TABLE . ' SET claimed_by = ?, claimed_at = NOW(), attempts = attempts + 1
            WHERE claimed_by IS NULL OR claimed_at < NOW() - INTERVAL ' . (int)self::STALE_CLAIM_MINUTES . ' MINUTE
            ORDER BY queued_at LIMIT ' . (int)$limit, array($token));
        return $this->_db->fetchPairs('SELECT id_hotel, version FROM ' . self::TABLE . ' WHERE claimed_by = ?', array($token));
    }

    /**
     * Удалить обработанные отели. Отель, изменённый во время сборки (version выросла), остаётся в очереди.
     * @param string $token
     * @param array $claimed array(id_hotel => version) из claim()
     */
    public function done($token, array $claimed) {
        foreach(array_chunk($claimed, 500, true) as $chunk) {
            $pairs = array();
            foreach($chunk as $hotelId => $version) {
                $pairs[] = '(' . (int)$hotelId . ',' . (int)$version . ')';
            }
            $this->_db->query('DELETE FROM ' . self::TABLE . ' WHERE claimed_by = ? AND (id_hotel, version) IN (' . implode(',', $pairs) . ')', array($token));
        }
        $this->release($token);
    }

    /**
     * Вернуть отели в очередь с текстом ошибки.
     */
    public function fail($token, array $hotelIds, $error) {
        $hotelIds = array_map('intval', $hotelIds);
        if(!empty($hotelIds)) {
            $this->_db->query('UPDATE ' . self::TABLE . ' SET claimed_by = NULL, claimed_at = NULL, last_error = ?
                WHERE claimed_by = ? AND id_hotel IN (' . implode(',', $hotelIds) . ')', array(substr((string)$error, 0, 255), $token));
        }
    }

    public function release($token) {
        $this->_db->query('UPDATE ' . self::TABLE . ' SET claimed_by = NULL, claimed_at = NULL WHERE claimed_by = ?', array($token));
    }

    /**
     * @return array array('queued' => ..., 'claimed' => ..., 'failed' => ..., 'oldest' => ...)
     */
    public function stats() {
        return $this->_db->fetchRow('SELECT COUNT(*) queued, SUM(claimed_by IS NOT NULL) claimed, SUM(last_error IS NOT NULL) failed,
            MIN(queued_at) oldest FROM ' . self::TABLE);
    }
}
