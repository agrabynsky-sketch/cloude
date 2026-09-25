<?php

/**
 * Транспорт через MySQL-интерфейс ClickHouse (порт 9004): обычный Zend_Db_Adapter_Pdo_Mysql.
 * https://clickhouse.com/docs/ru/interfaces/mysql
 *
 * Проверено на PHP 5.6 + ZF 1.12.20 + ClickHouse 24.8: fetchAll/fetchCol/fetchOne, bind-параметры
 * (PDO эмулирует prepared statements и отправляет готовый текст запроса), quoteInto с массивами.
 *
 * Ограничения MySQL-интерфейса: нет INSERT ... FORMAT, поэтому insertTsv() превращает TSV в
 * INSERT ... VALUES пачками. Для синхронизации (большие вставки) используйте HTTP-транспорт,
 * а этот — для чтения на сайте, если удобнее работать через Zend_Db.
 */
class Search_ClickHouse_Transport_Mysql implements Search_ClickHouse_Transport_Interface {
    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;
    protected $_insertChunk = 5000;

    /**
     * @param Zend_Db_Adapter_Abstract|array $db готовый адаптер или параметры для Zend_Db::factory('Pdo_Mysql', ...)
     */
    public function __construct($db) {
        if(is_array($db)) {
            $db = Zend_Db::factory('Pdo_Mysql', array_merge(array('port' => 9004, 'charset' => 'utf8'), $db));
        }
        $this->_db = $db;
    }

    /**
     * @return Zend_Db_Adapter_Abstract
     */
    public function getAdapter() {
        return $this->_db;
    }

    public function fetchAll($sql) {
        return $this->_db->fetchAll($sql, array(), Zend_Db::FETCH_ASSOC);
    }

    public function execute($sql) {
        $this->_db->query($sql);
    }

    public function insertTsv($table, array $columns, $tsv) {
        if('' === $tsv) {
            return 0;
        }
        $total = 0;
        $values = array();
        foreach(explode("\n", rtrim($tsv, "\n")) as $line) {
            $row = array();
            foreach(explode("\t", $line) as $v) {
                $v = Search_ClickHouse_Transport_Http::unescape($v);
                $row[] = is_null($v) ? 'NULL' : (preg_match('/^-?\d+$/', $v) ? $v : $this->_db->quote($v));
            }
            $values[] = '(' . implode(',', $row) . ')';
            if(count($values) >= $this->_insertChunk) {
                $total += $this->_flush($table, $columns, $values);
                $values = array();
            }
        }
        return $total + $this->_flush($table, $columns, $values);
    }

    public function quote($value) {
        return $this->_db->quote((string)$value);
    }

    protected function _flush($table, array $columns, array $values) {
        if(empty($values)) {
            return 0;
        }
        $this->_db->query('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(',', $values));
        return count($values);
    }
}
