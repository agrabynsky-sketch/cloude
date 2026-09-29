<?php
/**
 * Замер скорости моделей поиска из PHP (время полного вызова метода: запрос + разбор ответа).
 *   php tests/bench_search.php [runs=30]
 */
require __DIR__ . '/bootstrap.php';

$runs = isset($argv[1]) ? (int)$argv[1] : 30;
mt_srand(3);
$hotelIds = $mysql->fetchCol('SELECT id FROM hotels WHERE active = 1 AND id_region = 243836');
$dates = array();
for($i = 0; $i < $runs; $i++) {
    $dates[] = date('Y-m-d', strtotime('+' . mt_rand(5, 330) . ' day'));
}
$cases = array(
    'search: region (~1000 hotels), 7 nights, 2 guests' => function(Search_Model_Stay $m, $d) {
        return $m->search(array('checkin' => $d, 'nights' => 7, 'guests' => 2, 'id_region' => 243836, 'limit' => 30));
    },
    'search: 1000 hotels by id_hotel, 7 nights, 2 guests' => function(Search_Model_Stay $m, $d) use ($hotelIds) {
        return $m->search(array('checkin' => $d, 'nights' => 7, 'guests' => 2, 'id_hotel' => $hotelIds, 'limit' => 30));
    },
    'search: region, 14 nights, 3 guests, 4-5*, BB/HB, refundable' => function(Search_Model_Stay $m, $d) {
        return $m->search(array('checkin' => $d, 'nights' => 14, 'guests' => 3, 'id_region' => 243836, 'stars' => array(4, 5),
            'id_board_type' => array(4, 7), 'refundable' => 1, 'limit' => 30));
    },
    'search: region, all results (limit 1000)' => function(Search_Model_Stay $m, $d) {
        return $m->search(array('checkin' => $d, 'nights' => 7, 'guests' => 2, 'id_region' => 243836, 'limit' => 1000));
    },
    'search: region, 7 nights, 2 adults + children 7, 4' => function(Search_Model_Stay $m, $d) {
        return $m->search(array('checkin' => $d, 'nights' => 7, 'adults' => 2, 'children' => array(7, 4), 'id_region' => 243836, 'limit' => 30));
    },
    'search: region, 7 nights, 2 rooms (2 + 5 y.o., 2)' => function(Search_Model_Stay $m, $d) {
        return $m->search(array('checkin' => $d, 'nights' => 7, 'id_region' => 243836, 'limit' => 30,
            'rooms' => array(array('adults' => 2, 'children' => array(5)), array('adults' => 2))));
    },
    'search: region, 7 nights, 3 rooms (2 + 1 y.o., 2 + 9 y.o., 1)' => function(Search_Model_Stay $m, $d) {
        return $m->search(array('checkin' => $d, 'nights' => 7, 'id_region' => 243836, 'limit' => 30,
            'rooms' => array(array('adults' => 2, 'children' => array(1)), array('adults' => 2, 'children' => array(9)), array('adults' => 1))));
    },
    'hotelRates: one hotel, 7 nights, 2 guests' => function(Search_Model_Stay $m, $d) use ($hotelIds) {
        return $m->hotelRates($hotelIds[mt_rand(0, count($hotelIds) - 1)], array('checkin' => $d, 'nights' => 7, 'guests' => 2));
    },
    'hotelRooms: one hotel, 7 nights, 2 rooms with children' => function(Search_Model_Stay $m, $d) use ($hotelIds) {
        return $m->hotelRooms($hotelIds[mt_rand(0, count($hotelIds) - 1)], array('checkin' => $d, 'nights' => 7,
            'rooms' => array(array('adults' => 2, 'children' => array(7, 4)), array('adults' => 2))));
    },
);
foreach(array('HTTP 8123' => $chHttp, 'MySQL 9004' => $chMysql) as $transport => $client) {
    $model = new Search_Model_Stay($client);
    echo "== $transport\n";
    foreach($cases as $name => $fn) {
        $fn($model, $dates[0]);
        $t = array();
        foreach($dates as $d) {
            $s = microtime(true);
            $fn($model, $d);
            $t[] = (microtime(true) - $s) * 1000;
        }
        printf("  %-66s p50 %6.1f ms   p95 %6.1f ms\n", $name, pct($t, .5), pct($t, .95));
    }
}
