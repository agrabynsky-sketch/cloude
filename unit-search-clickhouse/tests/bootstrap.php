<?php
/**
 * Общий bootstrap тестовых скриптов (PHP 5.4+ CLI).
 * Параметры подключения берутся из того же конфига, что и scripts/search-sync.php
 * (SEARCH_SYNC_CONFIG или scripts/search-sync.config.php), плюс секция 'reader' для MySQL-интерфейса ClickHouse.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

$configFile = getenv('SEARCH_SYNC_CONFIG') ? getenv('SEARCH_SYNC_CONFIG') : __DIR__ . '/../scripts/search-sync.config.php';
$config = require $configFile;
date_default_timezone_set(!empty($config['timezone']) ? $config['timezone'] : 'Europe/Kiev');

set_include_path(implode(PATH_SEPARATOR, array_merge(array(__DIR__ . '/../library'), (array)$config['include_path'], array(get_include_path()))));
require_once 'Zend/Loader/Autoloader.php';
Zend_Loader_Autoloader::getInstance()->registerNamespace('Search_');

$mysql = Zend_Db::factory('Pdo_Mysql', $config['mysql']);
Zend_Db_Table_Abstract::setDefaultAdapter($mysql);

// клиент для записи (HTTP) и два клиента для чтения: HTTP и MySQL-интерфейс (9004)
$chWriter = Search_ClickHouse_Client::factory($config['clickhouse']);
$readerOptions = isset($config['reader']) ? $config['reader'] : $config['clickhouse'];
$chHttp = Search_ClickHouse_Client::factory(array_merge($readerOptions, array('transport' => 'http', 'port' => 8123)));
$chMysql = Search_ClickHouse_Client::factory(array_merge($readerOptions, array('transport' => 'mysql', 'port' => 9004)));
Search_ClickHouse_Client::setDefault($chHttp);

function pct(array $a, $p) {
    sort($a);
    return $a[(int)floor((count($a) - 1) * $p)];
}
