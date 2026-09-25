<?php
/**
 * CLI синхронизации поискового кэша MySQL -> ClickHouse.
 *
 *   php search-sync.php worker [seconds=55]     разбирать очередь (cron: * * * * *)
 *   php search-sync.php full                    поставить в очередь все отели и сразу всё пересобрать
 *   php search-sync.php enqueue-all [reason]    все отели в очередь (cron ночью: 5 0 * * *), разберёт worker
 *   php search-sync.php hotels 1005,1006-1010   пересобрать указанные отели сразу, минуя очередь
 *   php search-sync.php enqueue 1005 [reason]   поставить отель в очередь
 *   php search-sync.php optimize                склеить версии строк (OPTIMIZE FINAL), cron раз в 1-3 часа: FINAL-запросы
 *                                               заметно быстрее, когда у строк нет старых версий
 *   php search-sync.php stats                   состояние очереди и таблицы в ClickHouse
 *
 * Конфиг: search-sync.config.php (см. search-sync.config.php.dist) или переменная окружения SEARCH_SYNC_CONFIG.
 * В проекте можно вместо конфига поднять штатный bootstrap приложения и взять его Zend_Db-адаптер.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

$configFile = getenv('SEARCH_SYNC_CONFIG') ? getenv('SEARCH_SYNC_CONFIG') : __DIR__ . '/search-sync.config.php';
if(!is_file($configFile)) {
    fwrite(STDERR, "Config not found: $configFile (copy search-sync.config.php.dist)\n");
    exit(1);
}
$config = require $configFile;
// дата горизонта считается в PHP: часовой пояс должен совпадать с часовым поясом отелей / сайта
date_default_timezone_set(!empty($config['timezone']) ? $config['timezone'] : (ini_get('date.timezone') ? ini_get('date.timezone') : 'Europe/Kiev'));

set_include_path(implode(PATH_SEPARATOR, array_merge(array(__DIR__ . '/../library'), (array)$config['include_path'], array(get_include_path()))));
require_once 'Zend/Loader/Autoloader.php';
Zend_Loader_Autoloader::getInstance()->registerNamespace('Search_');

$mysql = Zend_Db::factory('Pdo_Mysql', $config['mysql']);
Zend_Db_Table_Abstract::setDefaultAdapter($mysql);
$clickhouse = Search_ClickHouse_Client::factory($config['clickhouse']);

$log = function($message) {
    echo date('Y-m-d H:i:s') . ' ' . $message . "\n";
};
$builder = new Search_Sync_Builder($mysql);
$queue = new Search_Sync_Queue($mysql);
$worker = new Search_Sync_Worker($clickhouse, $builder, $queue);
$worker->setLogger($log);
if(!empty($config['batch_hotels'])) {
    $worker->setBatchHotels($config['batch_hotels']);
}

$cmd = isset($argv[1]) ? $argv[1] : 'help';
$arg = isset($argv[2]) ? $argv[2] : null;

// один воркер на сервер: без блокировки версии разных процессов могут прийти не по порядку
if(in_array($cmd, array('worker', 'full', 'hotels', 'optimize'))) {
    $lock = fopen(sys_get_temp_dir() . '/search-sync.lock', 'c');
    if(!flock($lock, LOCK_EX | LOCK_NB)) {
        $log('another sync process is running, exit');
        exit(0);
    }
}

switch($cmd) {
    case 'worker':
        $n = $worker->run($arg ? (int)$arg : 55);
        $log("worker done, hotels processed: $n");
        break;
    case 'full':
        $log('enqueued: ' . $worker->enqueueAll('full', $mysql));
        while($worker->runOnce(200)) {
        }
        $log('full sync done');
        break;
    case 'enqueue-all':
        $log('enqueued: ' . $worker->enqueueAll($arg ? $arg : 'nightly', $mysql));
        break;
    case 'hotels':
        $ids = array();
        foreach(explode(',', (string)$arg) as $part) {
            if(preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                $ids = array_merge($ids, range((int)$m[1], (int)$m[2]));
            } elseif(ctype_digit($part)) {
                $ids[] = (int)$part;
            }
        }
        $worker->syncHotels($ids);
        break;
    case 'enqueue':
        $queue->push((int)$arg, isset($argv[3]) ? $argv[3] : 'manual');
        $log("hotel $arg enqueued");
        break;
    case 'optimize':
        $t = microtime(true);
        $clickhouse->execute('OPTIMIZE TABLE hotels_search_stay FINAL');
        $log(sprintf('optimize done in %.1f s', microtime(true) - $t));
        break;
    case 'stats':
        print_r($queue->stats());
        print_r($clickhouse->fetchRow('SELECT count() AS rows_total, uniqExact(id_hotel) AS hotels, uniqExact(id_rate_room) AS rate_rooms,
            min(d) AS first_date, max(d) AS last_date FROM hotels_search_stay FINAL'));
        break;
    default:
        echo "usage: php search-sync.php worker|full|enqueue-all|hotels <ids>|enqueue <id>|optimize|stats\n";
}
