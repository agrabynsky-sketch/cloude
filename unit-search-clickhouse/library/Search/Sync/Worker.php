<?php

/**
 * Воркер синхронизации MySQL -> ClickHouse.
 *
 *   $worker = new Search_Sync_Worker($writerClient, new Search_Sync_Builder($mysqlDb), new Search_Sync_Queue($mysqlDb));
 *   $worker->run(55);                      // cron раз в минуту: разбирать очередь 55 секунд
 *   $worker->syncHotels(array(1005, 1006)); // пересобрать конкретные отели сразу
 *   $worker->enqueueAll('nightly');         // ночью: все отели в очередь (сдвиг горизонта + сверка)
 *
 * Один отель = одна версия ver для всех его строк; удалённые рум-рейты получают tombstone-строки.
 * Одновременно должен работать один воркер (scripts/search-sync.php держит flock), иначе версии
 * с разных машин могут прийти не по порядку.
 */
class Search_Sync_Worker {
    const TABLE = 'hotels_search_stay';

    /** @var Search_ClickHouse_Client */
    protected $_client;
    /** @var Search_Sync_Builder */
    protected $_builder;
    /** @var Search_Sync_Queue */
    protected $_queue;
    protected $_batchHotels = 10;       // отелей на одно чтение из MySQL (память PHP 5: ~4 МБ на отель)
    protected $_flushRows = 250000;     // строк в одном INSERT в ClickHouse (одна вставка = один атомарный блок)
    protected $_token;
    protected $_logger;
    protected $_buffer = '';
    protected $_bufferRows = 0;
    protected $_stats;

    public function __construct(Search_ClickHouse_Client $client, Search_Sync_Builder $builder, Search_Sync_Queue $queue = null) {
        $this->_client = $client;
        $this->_builder = $builder;
        $this->_queue = $queue;
        $this->_token = gethostname() . ':' . getmypid() . ':' . substr(md5(uniqid('', true)), 0, 8);
    }

    public function setBatchHotels($n) {
        $this->_batchHotels = max(1, (int)$n);
        return $this;
    }

    public function setFlushRows($n) {
        $this->_flushRows = max(1000, (int)$n);
        return $this;
    }

    /**
     * @param callable $logger function($message)
     */
    public function setLogger($logger) {
        $this->_logger = $logger;
        return $this;
    }

    /**
     * Разбирать очередь до истечения $seconds.
     * @return int сколько отелей обработано
     */
    public function run($seconds = 55, $claimLimit = 200, $idleSleep = 1) {
        $until = microtime(true) + $seconds;
        $total = 0;
        while(microtime(true) < $until) {
            $n = $this->runOnce($claimLimit);
            $total += $n;
            if(!$n) {
                sleep($idleSleep);
            }
        }
        return $total;
    }

    /**
     * Одна итерация: забрать пачку отелей из очереди и пересобрать.
     * @return int сколько отелей обработано
     */
    public function runOnce($claimLimit = 200) {
        $claimed = $this->_queue->claim($this->_token, $claimLimit);
        if(empty($claimed)) {
            return 0;
        }
        try {
            $this->syncHotels(array_keys($claimed));
            $this->_queue->done($this->_token, $claimed);
        } catch(Exception $e) {
            $this->_queue->fail($this->_token, array_keys($claimed), $e->getMessage());
            $this->_log('ERROR: ' . $e->getMessage());
            throw $e;
        }
        return count($claimed);
    }

    /**
     * Пересобрать отели в ClickHouse прямо сейчас (без очереди).
     * @return array статистика
     */
    public function syncHotels(array $hotelIds) {
        $t = microtime(true);
        $this->_stats = array('hotels' => 0, 'rows' => 0, 'tombstones' => 0, 'inserts' => 0);
        $hotelIds = array_values(array_unique(array_map('intval', $hotelIds)));
        $ver = self::newVersion();
        $existing = $this->_existingRateRooms($hotelIds);
        $self = $this;
        $emit = function($line) use ($self) {
            $self->append($line);
        };
        foreach(array_chunk($hotelIds, $this->_batchHotels) as $chunk) {
            $built = $this->_builder->build($chunk, $ver, $emit);
            foreach($chunk as $hotelId) {
                $gone = array_diff(isset($existing[$hotelId]) ? $existing[$hotelId] : array(),
                    isset($built[$hotelId]) ? $built[$hotelId] : array());
                if(!empty($gone)) {
                    $before = $this->_bufferRows;
                    $this->_builder->buildTombstones($hotelId, $gone, $ver, $emit);
                    $this->_stats['tombstones'] += $this->_bufferRows - $before;
                }
            }
            $this->_stats['hotels'] += count($chunk);
            if($this->_bufferRows >= $this->_flushRows) {
                $this->flush();
            }
        }
        $this->flush();
        $this->_stats['ms'] = round((microtime(true) - $t) * 1000);
        $this->_log(sprintf('synced %d hotels: %d rows (%d tombstones), %d inserts, %d ms',
            $this->_stats['hotels'], $this->_stats['rows'], $this->_stats['tombstones'], $this->_stats['inserts'], $this->_stats['ms']));
        return $this->_stats;
    }

    /**
     * Поставить в очередь все отели: из MySQL и те, что ещё лежат в ClickHouse (удалённые получат tombstone).
     * @return int
     */
    public function enqueueAll($reason = 'full', $db = null) {
        $db = $db ? $db : Zend_Db_Table_Abstract::getDefaultAdapter();
        $ids = $db->fetchCol('SELECT id FROM hotels');
        $ids = array_merge($ids, $this->_client->fetchCol('SELECT DISTINCT id_hotel FROM ' . self::TABLE . ' FINAL WHERE d = '
            . $this->_client->quote($this->_builder->getStartDate())));
        return $this->_queue->push(array_unique($ids), $reason);
    }

    /**
     * @internal вызывается из callback сборщика
     */
    public function append($line) {
        $this->_buffer .= $line;
        $this->_bufferRows++;
    }

    public function flush() {
        if(!$this->_bufferRows) {
            return 0;
        }
        $n = $this->_client->insertTsv(self::TABLE, Search_Sync_Builder::$columns, $this->_buffer);
        $this->_stats['rows'] += $this->_bufferRows;
        $this->_stats['inserts']++;
        $this->_buffer = '';
        $this->_bufferRows = 0;
        return $n;
    }

    /**
     * Версия сборки: микросекунды текущего времени (монотонна в пределах одного воркера).
     */
    public static function newVersion() {
        static $last = 0;
        $ver = (int)sprintf('%.0f', microtime(true) * 1000000);
        $last = max($last + 1, $ver);
        return $last;
    }

    /**
     * Какие рум-рейты отелей сейчас лежат в ClickHouse (по первой дате горизонта).
     * @return array id_hotel => array(id_rate_room, ...)
     */
    protected function _existingRateRooms(array $hotelIds) {
        $result = array();
        foreach(array_chunk($hotelIds, 1000) as $chunk) {
            $rows = $this->_client->fetchAll('SELECT id_hotel, id_rate_room FROM ' . self::TABLE . ' FINAL
                WHERE d = ' . $this->_client->quote($this->_builder->getStartDate()) . ' AND id_hotel IN (' . implode(',', $chunk) . ')');
            foreach($rows as $row) {
                $result[$row['id_hotel']][] = (int)$row['id_rate_room'];
            }
        }
        return $result;
    }

    protected function _log($message) {
        if($this->_logger) {
            call_user_func($this->_logger, $message);
        }
    }
}
