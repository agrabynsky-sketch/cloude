<?php

/**
 * Клиент ClickHouse для моделей поиска.
 *
 * Bootstrap (один раз, например в Bootstrap::_initClickHouse или в index.php):
 *
 *   Search_ClickHouse_Client::setDefault(Search_ClickHouse_Client::factory(array(
 *       'transport' => 'http',                // 'http' (8123, curl) или 'mysql' (9004, Zend_Db Pdo_Mysql)
 *       'host'      => 'clickhouse.internal',
 *       'port'      => 8123,                  // 9004 для 'mysql'
 *       'username'  => 'search_reader',
 *       'password'  => '...',
 *       'dbname'    => 'unit_search',
 *       'timeout'   => 3,
 *       'settings'  => array('max_execution_time' => 3),   // только для http
 *   )));
 *
 * Параметры можно брать из application.ini (resources.clickhouse.*) через Zend_Config::toArray().
 */
class Search_ClickHouse_Client {
    /** @var Search_ClickHouse_Client */
    protected static $_default;
    /** @var Search_ClickHouse_Transport_Interface */
    protected $_transport;
    protected $_profiler = false;
    protected $_queries = array();

    public function __construct(Search_ClickHouse_Transport_Interface $transport) {
        $this->_transport = $transport;
    }

    /**
     * @param array|Zend_Config $options
     * @return Search_ClickHouse_Client
     */
    public static function factory($options) {
        if($options instanceof Zend_Config) {
            $options = $options->toArray();
        }
        $type = empty($options['transport']) ? 'http' : $options['transport'];
        unset($options['transport']);
        if('mysql' == $type) {
            unset($options['settings'], $options['timeout'], $options['compress']);
            return new self(new Search_ClickHouse_Transport_Mysql($options));
        }
        return new self(new Search_ClickHouse_Transport_Http($options));
    }

    public static function setDefault(Search_ClickHouse_Client $client) {
        self::$_default = $client;
    }

    /**
     * @return Search_ClickHouse_Client
     */
    public static function getDefault() {
        if(!self::$_default) {
            throw new Search_ClickHouse_Exception('Default ClickHouse client is not configured: call Search_ClickHouse_Client::setDefault()');
        }
        return self::$_default;
    }

    /**
     * @return Search_ClickHouse_Transport_Interface
     */
    public function getTransport() {
        return $this->_transport;
    }

    public function setProfiler($enabled) {
        $this->_profiler = (bool)$enabled;
        return $this;
    }

    /**
     * @return array список array('sql' => ..., 'ms' => ...) при включённом профайлере
     */
    public function getQueries() {
        return $this->_queries;
    }

    public function fetchAll($sql) {
        $t = microtime(true);
        $rows = $this->_transport->fetchAll($sql);
        $this->_log($sql, $t);
        return $rows;
    }

    public function fetchRow($sql) {
        $rows = $this->fetchAll($sql);
        return empty($rows) ? null : $rows[0];
    }

    public function fetchCol($sql) {
        $result = array();
        foreach($this->fetchAll($sql) as $row) {
            $result[] = reset($row);
        }
        return $result;
    }

    public function fetchOne($sql) {
        $row = $this->fetchRow($sql);
        return empty($row) ? null : reset($row);
    }

    public function execute($sql) {
        $t = microtime(true);
        $this->_transport->execute($sql);
        $this->_log($sql, $t);
    }

    public function insertTsv($table, array $columns, $tsv) {
        $t = microtime(true);
        $count = $this->_transport->insertTsv($table, $columns, $tsv);
        $this->_log('INSERT INTO ' . $table . ' (' . $count . ' rows)', $t);
        return $count;
    }

    public function quote($value) {
        return $this->_transport->quote($value);
    }

    protected function _log($sql, $start) {
        if($this->_profiler) {
            $this->_queries[] = array('sql' => $sql, 'ms' => round((microtime(true) - $start) * 1000, 2));
        }
    }
}
