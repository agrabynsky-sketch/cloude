<?php

/**
 * Транспорт до ClickHouse. Две реализации:
 *  - Search_ClickHouse_Transport_Http  — HTTP-интерфейс (порт 8123) через curl, основной вариант сейчас;
 *  - Search_ClickHouse_Transport_Mysql — MySQL-интерфейс ClickHouse (порт 9004) через Zend_Db Pdo_Mysql.
 * Модели работают только с этим интерфейсом, поэтому транспорт меняется одной строкой в конфиге.
 */
interface Search_ClickHouse_Transport_Interface {
    /**
     * @param string $sql SELECT без секции FORMAT
     * @return array список строк (ассоциативные массивы, значения — строки)
     */
    public function fetchAll($sql);

    /**
     * DDL, INSERT ... SELECT, OPTIMIZE и т.п. — без результата.
     * @param string $sql
     */
    public function execute($sql);

    /**
     * Пакетная вставка готового TabSeparated (строки через "\n", колонки через "\t").
     * @param string $table
     * @param array $columns
     * @param string $tsv
     * @return int количество строк
     */
    public function insertTsv($table, array $columns, $tsv);

    /**
     * @param string $value
     * @return string строковый литерал в кавычках
     */
    public function quote($value);
}
