<?php
/**
 * Одинаковые ли ответы у HTTP (8123) и MySQL-интерфейса (9004) и сколько стоит каждый транспорт.
 *   php tests/compare_transports.php
 */
require __DIR__ . '/bootstrap.php';

$regionId = isset($argv[1]) ? (int)$argv[1] : 243836;
$http = new Search_Model_Stay($chHttp);
$pdo = new Search_Model_Stay($chMysql);
$checkin = date('Y-m-d', strtotime('+75 day'));

foreach(array(array(3, 2), array(7, 2), array(14, 3), array(7, 4)) as $case) {
    list($nights, $guests) = $case;
    $criteria = array('checkin' => $checkin, 'nights' => $nights, 'guests' => $guests, 'region_id' => $regionId, 'limit' => 1000);
    $a = $http->search($criteria);
    $b = $pdo->search($criteria);
    printf("search region=%d nights=%d guests=%d: hotels http=%d mysql=%d, same result: %s\n", $regionId, $nights, $guests,
        $a['total'], $b['total'], $a == $b ? 'yes' : 'NO');
}
$first = $a['items'][0]['hotel_id'];
$ra = $http->hotelRates($first, array('checkin' => $checkin, 'nights' => 7, 'guests' => 2));
$rb = $pdo->hotelRates($first, array('checkin' => $checkin, 'nights' => 7, 'guests' => 2));
printf("hotelRates hotel=%d: room-rates http=%d mysql=%d, same result: %s\n", $first, count($ra), count($rb), $ra == $rb ? 'yes' : 'NO');
echo "\nexample search item:\n";
print_r($a['items'][0]);
echo "example hotel room-rate:\n";
print_r($ra[0]);
