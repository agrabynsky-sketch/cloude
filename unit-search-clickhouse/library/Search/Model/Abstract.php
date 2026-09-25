<?php

/**
 * Базовая модель чтения из ClickHouse (ZF1-стиль, PHP 5.4+).
 *
 * Модели строят SQL сами и подставляют только проверенные значения (int, даты Y-m-d, списки int),
 * поэтому одинаково работают через HTTP (8123) и через MySQL-интерфейс (9004).
 */
abstract class Search_Model_Abstract {
    protected $_table;
    /** @var Search_ClickHouse_Client */
    protected $_client;
    protected static $_instances = array();

    public function __construct(Search_ClickHouse_Client $client = null) {
        $this->_client = $client ? $client : Search_ClickHouse_Client::getDefault();
    }

    /**
     * @return static
     */
    public static function getInstance() {
        $class = get_called_class();
        if(!isset(self::$_instances[$class])) {
            self::$_instances[$class] = new $class();
        }
        return self::$_instances[$class];
    }

    /**
     * @return Search_ClickHouse_Client
     */
    public function getClient() {
        return $this->_client;
    }

    protected function _int($value) {
        return (int)$value;
    }

    /**
     * @return string список через запятую; пустой список -> '0' (ничего не найдёт)
     */
    protected function _intList($values) {
        $values = array_unique(array_map('intval', (array)$values));
        return empty($values) ? '0' : implode(',', $values);
    }

    /**
     * Список дат для d IN (...) — быстрее, чем BETWEEN, когда запрос по одному/нескольким отелям:
     * ключ сортировки (d, id_hotel, ...) отсекает гранулы по id_hotel для каждой даты отдельно.
     * @return string "'2026-12-10','2026-12-11',..."
     */
    protected function _dateList($from, $to, $maxDays = 400) {
        $a = strtotime(trim($this->_date($from), "'") . ' 12:00:00');
        $b = strtotime(trim($this->_date($to), "'") . ' 12:00:00');
        $days = (int)round(($b - $a) / 86400);
        if($days < 0 || $days > $maxDays) {
            throw new Search_ClickHouse_Exception('Invalid date range', 400);
        }
        $list = array();
        for($i = 0; $i <= $days; $i++) {
            $list[] = "'" . date('Y-m-d', $a + $i * 86400) . "'";
        }
        return implode(',', $list);
    }

    /**
     * @return string 'Y-m-d' в кавычках
     * @throws Search_ClickHouse_Exception с кодом 400 (неверный параметр)
     */
    protected function _date($value) {
        $ts = strtotime($value);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$value) || false === $ts) {
            throw new Search_ClickHouse_Exception('Invalid date: ' . $value, 400);
        }
        return "'" . date('Y-m-d', $ts) . "'";
    }
}
