<?php
/**
 * Smoke-тест SearchController через настоящий диспетчер ZF1 (без веб-сервера).
 *   php tests/controller_smoke.php
 */
require __DIR__ . '/bootstrap.php';

function dispatch($uri) {
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_GET = array();
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $_GET);
    $front = Zend_Controller_Front::getInstance();
    $front->resetInstance();
    $front = Zend_Controller_Front::getInstance();
    // json-хелпер ZF1 после отправки делает exit — в тесте отключаем (resetInstance сбрасывает хелперы)
    Zend_Controller_Action_HelperBroker::getStaticHelper('json')->suppressExit = true;
    $front->setControllerDirectory(__DIR__ . '/../application/controllers')->returnResponse(true)->throwExceptions(true)
        ->setParam('noViewRenderer', true);
    $request = new Zend_Controller_Request_Http();
    $response = $front->dispatch($request, new Zend_Controller_Response_Cli());
    return array($response->getHttpResponseCode(), json_decode($response->getBody(), true));
}

$checkin = date('Y-m-d', strtotime('+60 day'));
$fail = 0;
list($code, $body) = dispatch("/search/hotels?checkin=$checkin&nights=7&guests=2&region_id=243836&stars=4,5&limit=5");
printf("GET /search/hotels (region, 4-5*)  -> HTTP %d, total=%d, items=%d, first=%s, %.1f ms\n", $code, $body['total'], count($body['items']),
    json_encode($body['items'][0]), $body['ms']);
$fail += (200 == $code && 5 == count($body['items'])) ? 0 : 1;

$hotelId = $body['items'][0]['hotel_id'];
list($code, $body) = dispatch("/search/hotel?id=$hotelId&checkin=$checkin&nights=7&guests=2");
printf("GET /search/hotel?id=%d            -> HTTP %d, room-rates=%d, cheapest=%s\n", $hotelId, $code, count($body['items']), json_encode($body['items'][0]));
$fail += (200 == $code && count($body['items']) > 0) ? 0 : 1;

list($code, $body) = dispatch("/search/hotels?checkin=2020-01-01&nights=7&guests=2&region_id=243836");
printf("GET /search/hotels (past date)      -> HTTP %d, %s\n", $code, json_encode($body));
$fail += 400 == $code ? 0 : 1;

list($code, $body) = dispatch("/search/hotels?checkin=abc&nights=7&guests=2&region_id=243836");
printf("GET /search/hotels (bad date)       -> HTTP %d, %s\n", $code, json_encode($body));
$fail += 400 == $code ? 0 : 1;

// ClickHouse недоступен -> 503 без подробностей
Search_ClickHouse_Client::setDefault(Search_ClickHouse_Client::factory(array('host' => '127.0.0.1', 'port' => 1, 'connect_timeout' => 1)));
$ref = new ReflectionProperty('Search_Model_Abstract', '_instances');
$ref->setAccessible(true);
$ref->setValue(array());
list($code, $body) = dispatch("/search/hotels?checkin=$checkin&nights=7&guests=2&region_id=243836");
printf("GET /search/hotels (CH down)        -> HTTP %d, %s\n", $code, json_encode($body));
$fail += 503 == $code ? 0 : 1;

echo $fail ? "FAILED: $fail\n" : "ALL OK\n";
exit($fail ? 1 : 0);
