<?php
/**
 * Сверка поискового кэша с эталоном, посчитанным "в лоб" по ночам прямо из MySQL (без кода Search_Sync_Builder).
 *   php tests/verify_reference.php [cases=200] [seed=1]
 *
 * Для случайных (отель, дата заезда, ночей, гостей):
 *   - hotelRates()   == эталонный список доступных рум-рейтов (id, итоговая цена, цены по ночам);
 *   - search()       == минимум эталона по каждому отелю (для случайных наборов по 50 отелей).
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reference.php';

$cases = isset($argv[1]) ? (int)$argv[1] : 200;
mt_srand(isset($argv[2]) ? (int)$argv[2] : 1);
$model = new Search_Model_Stay($chHttp);
$today = date('Y-m-d');
$hotelIds = $mysql->fetchCol('SELECT id FROM hotels WHERE active = 1');

$fail = 0;
$checkedRates = 0;
$emptyCases = 0;
for($n = 0; $n < $cases; $n++) {
    $hotelId = $hotelIds[mt_rand(0, count($hotelIds) - 1)];
    $nights = mt_rand(1, 14);
    $guests = mt_rand(1, 4);
    $checkin = date('Y-m-d', strtotime("$today 12:00:00 +" . mt_rand(0, 365 - $nights) . ' day'));
    $expected = referenceRates($mysql, $hotelId, $checkin, $nights, $guests, $today);
    $got = array();
    foreach($model->hotelRates($hotelId, array('checkin' => $checkin, 'nights' => $nights, 'guests' => $guests, 'channel' => 1)) as $row) {
        $got[$row['rate_room_id']] = array('total' => $row['price_minor'], 'nightly' => array_map(function($v) {
            return (int)round($v * 100);
        }, $row['nightly']));
    }
    ksort($got);
    $checkedRates += count($expected);
    $emptyCases += empty($expected) ? 1 : 0;
    if($expected != $got) {
        $fail++;
        printf("MISMATCH hotel=%d checkin=%s nights=%d guests=%d\n  expected: %s\n  got:      %s\n", $hotelId, $checkin, $nights, $guests,
            json_encode($expected), json_encode($got));
    }
}
printf("hotelRates: %d cases, %d room-rate prices compared, %d cases with nothing available, mismatches: %d\n", $cases, $checkedRates, $emptyCases, $fail);

// search(): минимум по отелю для случайных наборов из 50 отелей
$searchFail = 0;
$searchChecked = 0;
for($n = 0; $n < 5; $n++) {
    $sample = array();
    foreach(array_rand($hotelIds, 50) as $i) {
        $sample[] = (int)$hotelIds[$i];
    }
    $nights = mt_rand(1, 10);
    $guests = mt_rand(1, 3);
    $checkin = date('Y-m-d', strtotime("$today 12:00:00 +" . mt_rand(0, 300) . ' day'));
    $res = $model->search(array('checkin' => $checkin, 'nights' => $nights, 'guests' => $guests, 'hotel_ids' => $sample, 'limit' => 1000));
    $got = array();
    foreach($res['items'] as $item) {
        $got[$item['hotel_id']] = $item['price_minor'];
    }
    $expected = array();
    foreach($sample as $hotelId) {
        $rates = referenceRates($mysql, $hotelId, $checkin, $nights, $guests, $today);
        foreach($rates as $rrId => $v) {
            if(!isset($expected[$hotelId]) || $v['total'] < $expected[$hotelId]) {
                $expected[$hotelId] = $v['total'];
            }
        }
    }
    ksort($got);
    ksort($expected);
    $searchChecked += count($expected);
    if($got != $expected || $res['total'] != count($expected)) {
        $searchFail++;
        printf("SEARCH MISMATCH checkin=%s nights=%d guests=%d\n  expected: %s\n  got:      %s\n", $checkin, $nights, $guests, json_encode($expected), json_encode($got));
    }
}
printf("search(): 5 x 50 hotels, %d hotel minimum prices compared, mismatches: %d\n", $searchChecked, $searchFail);

// search() с фильтрами (звёзды, питание, возвратность, цена) и сортировкой по убыванию цены
$filterFail = 0;
$filterChecked = 0;
$filterSets = array(
    array('stars' => array(4, 5)),
    array('board_ids' => array(4, 7)),
    array('refundable' => 0),
    array('stars' => array(2, 3), 'refundable' => 1, 'price_min' => 5000),
    array('board_ids' => array(1), 'price_max' => 20000),
);
foreach($filterSets as $filters) {
    $sample = array();
    foreach(array_rand($hotelIds, 60) as $i) {
        $sample[] = (int)$hotelIds[$i];
    }
    $nights = mt_rand(2, 7);
    $guests = mt_rand(1, 3);
    $checkin = date('Y-m-d', strtotime("$today 12:00:00 +" . mt_rand(0, 300) . ' day'));
    $res = $model->search(array_merge(array('checkin' => $checkin, 'nights' => $nights, 'guests' => $guests, 'hotel_ids' => $sample,
        'order' => '-price', 'limit' => 1000), $filters));
    $got = array();
    $prices = array();
    foreach($res['items'] as $item) {
        $got[$item['hotel_id']] = $item['price_minor'];
        $prices[] = $item['price_minor'];
    }
    $sorted = $prices;
    rsort($sorted);
    $expected = array();
    foreach($sample as $hotelId) {
        $stars = (int)$mysql->fetchOne('SELECT stars FROM hotels WHERE id = ?', array($hotelId));
        if(!empty($filters['stars']) && !in_array($stars, $filters['stars'])) {
            continue;
        }
        foreach(referenceRates($mysql, $hotelId, $checkin, $nights, $guests, $today) as $rrId => $v) {
            $rate = $mysql->fetchRow('SELECT t.id_board_type, t.id_cancel_policy FROM hotels_rates_rooms rr JOIN hotels_rates t ON t.id = rr.id_rate WHERE rr.id = ?', array($rrId));
            if((!empty($filters['board_ids']) && !in_array((int)$rate['id_board_type'], $filters['board_ids']))
                || (isset($filters['refundable']) && (int)!empty($rate['id_cancel_policy']) != $filters['refundable'])
                || (isset($filters['price_min']) && $v['total'] < $filters['price_min'] * 100)
                || (isset($filters['price_max']) && $v['total'] > $filters['price_max'] * 100)) {
                continue;
            }
            if(!isset($expected[$hotelId]) || $v['total'] < $expected[$hotelId]) {
                $expected[$hotelId] = $v['total'];
            }
        }
    }
    ksort($got);
    ksort($expected);
    $filterChecked += count($expected);
    if($got != $expected || $prices !== $sorted) {
        $filterFail++;
        printf("FILTER MISMATCH %s checkin=%s nights=%d guests=%d\n  expected: %s\n  got:      %s\n", json_encode($filters), $checkin, $nights, $guests,
            json_encode($expected), json_encode($got));
    }
}
printf("search() with filters: %d filter sets, %d hotel minimum prices compared, mismatches: %d\n", count($filterSets), $filterChecked, $filterFail);
exit($fail + $searchFail + $filterFail ? 1 : 0);
