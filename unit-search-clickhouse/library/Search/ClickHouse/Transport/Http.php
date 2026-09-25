<?php

/**
 * HTTP-транспорт ClickHouse (порт 8123) на curl. Совместим с PHP 5.4+.
 *
 * - одно keep-alive соединение на процесс (curl-хендл переиспользуется);
 * - логин/пароль передаются заголовками X-ClickHouse-User/Key (не попадают в URL и логи);
 * - SELECT возвращается в TabSeparatedWithNames (быстрее JSON для PHP 5, все значения — строки, как у PDO);
 * - INSERT отправляется телом запроса в TabSeparated, по умолчанию сжатым gzip;
 * - ошибка ClickHouse (HTTP != 200) -> Search_ClickHouse_Exception с текстом ошибки сервера.
 */
class Search_ClickHouse_Transport_Http implements Search_ClickHouse_Transport_Interface {
    protected $_options = array(
        'scheme'          => 'http',
        'host'            => '127.0.0.1',
        'port'            => 8123,
        'username'        => 'default',
        'password'        => '',
        'dbname'          => 'unit_search',
        'timeout'         => 10,      // секунд на запрос; для воркера синхронизации ставьте 300
        'connect_timeout' => 2,
        'compress'        => true,    // gzip тела INSERT
        'settings'        => array(), // настройки ClickHouse на каждый запрос, напр. array('max_execution_time' => 3)
    );
    protected $_curl;

    public function __construct(array $options = array()) {
        $this->_options = array_merge($this->_options, $options);
    }

    public function __destruct() {
        if($this->_curl) {
            curl_close($this->_curl);
        }
    }

    public function fetchAll($sql) {
        $body = $this->_request(array(), rtrim($sql, " \t\n\r;") . "\nFORMAT TabSeparatedWithNames");
        if('' === $body) {
            return array();
        }
        $lines = explode("\n", rtrim($body, "\n"));
        $names = explode("\t", array_shift($lines));
        $rows = array();
        foreach($lines as $line) {
            $values = explode("\t", $line);
            if(false !== strpos($line, '\\')) {
                foreach($values as $i => $v) {
                    $values[$i] = self::unescape($v);
                }
            }
            $rows[] = array_combine($names, $values);
        }
        return $rows;
    }

    public function execute($sql) {
        $this->_request(array(), $sql);
    }

    public function insertTsv($table, array $columns, $tsv) {
        if('' === $tsv) {
            return 0;
        }
        $query = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') FORMAT TabSeparated';
        $this->_request(array('query' => $query), $tsv, !empty($this->_options['compress']));
        return substr_count($tsv, "\n");
    }

    public function quote($value) {
        return "'" . strtr((string)$value, array('\\' => '\\\\', "'" => "\\'")) . "'";
    }

    /**
     * Экранирование значения для TabSeparated.
     */
    public static function escape($value) {
        if(is_null($value)) {
            return '\\N';
        }
        return strtr((string)$value, array('\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r'));
    }

    public static function unescape($value) {
        if('\\N' === $value) {
            return null;
        }
        return strtr($value, array('\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\r' => "\r", "\\'" => "'", '\\0' => "\0"));
    }

    protected function _request(array $params, $body, $gzip = false) {
        $o = $this->_options;
        $params = array_merge(array('database' => $o['dbname']), $o['settings'], $params);
        $url = $o['scheme'] . '://' . $o['host'] . ':' . $o['port'] . '/?' . http_build_query($params);
        $headers = array(
            'X-ClickHouse-User: ' . $o['username'],
            'X-ClickHouse-Key: ' . $o['password'],
            'Content-Type: text/plain; charset=UTF-8',
            'Expect:',
        );
        if($gzip && function_exists('gzencode')) {
            $body = gzencode($body, 1);
            $headers[] = 'Content-Encoding: gzip';
        }
        if(!$this->_curl) {
            $this->_curl = curl_init();
        }
        curl_setopt_array($this->_curl, array(
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $o['timeout'],
            CURLOPT_CONNECTTIMEOUT => $o['connect_timeout'],
        ));
        $response = curl_exec($this->_curl);
        if(false === $response) {
            throw new Search_ClickHouse_Exception('ClickHouse HTTP error: ' . curl_error($this->_curl), 503);
        }
        $code = (int)curl_getinfo($this->_curl, CURLINFO_HTTP_CODE);
        if(200 !== $code) {
            throw new Search_ClickHouse_Exception('ClickHouse error (HTTP ' . $code . '): ' . trim($response), $code);
        }
        return $response;
    }
}
