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

$fail = 0;
$cases = array(
    array(3, array(array('adults' => 2))),
    array(7, array(array('adults' => 2))),
    array(14, array(array('adults' => 3))),
    array(7, array(array('adults' => 2, 'children' => array(7, 4)))),
    array(7, array(array('adults' => 2, 'children' => array(1)), array('adults' => 2))),
);
foreach($cases as $case) {
    list($nights, $rooms) = $case;
    $criteria = array('checkin' => $checkin, 'nights' => $nights, 'rooms' => $rooms, 'id_region' => $regionId, 'limit' => 1000);
    $a = $http->search($criteria);
    $b = $pdo->search($criteria);
    $fail += $a == $b ? 0 : 1;
    printf("search region=%d nights=%d rooms=%s: hotels http=%d mysql=%d, same result: %s\n", $regionId, $nights, json_encode($rooms),
        $a['total'], $b['total'], $a == $b ? 'yes' : 'NO');
}
$first = $a['items'][0]['id_hotel'];
$ra = $http->hotelRates($first, array('checkin' => $checkin, 'nights' => 7, 'guests' => 2));
$rb = $pdo->hotelRates($first, array('checkin' => $checkin, 'nights' => 7, 'guests' => 2));
printf("hotelRates hotel=%d: room-rates http=%d mysql=%d, same result: %s\n", $first, count($ra), count($rb), $ra == $rb ? 'yes' : 'NO');
$fail += $ra == $rb ? 0 : 1;
$card = array('checkin' => $checkin, 'nights' => 7, 'rooms' => array(array('adults' => 2, 'children' => array(7, 4)), array('adults' => 2)));
$oa = $http->hotelRooms($first, $card);
$ob = $pdo->hotelRooms($first, $card);
printf("hotelRooms hotel=%d (2 rooms, children): best http=%s mysql=%s, same result: %s\n", $first,
    $oa['best'] ? $oa['best']['price'] : 'null', $ob['best'] ? $ob['best']['price'] : 'null', $oa == $ob ? 'yes' : 'NO');
$fail += $oa == $ob ? 0 : 1;
echo "\nexample search item:\n";
print_r($a['items'][0]);
echo "example hotel room-rate:\n";
print_r($ra[0]);
echo "example hotel room with children (hotelRooms):\n";
print_r($oa['rooms'][0]['items'][0]);
exit($fail ? 1 : 0);
