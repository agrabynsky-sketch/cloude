<?php
/**
 * Сверка поискового кэша с эталоном, посчитанным "в лоб" по ночам прямо из MySQL (без кода Search_Sync_Builder
 * и Search_Occupancy, размещение детей и комбинации номеров — полным перебором).
 *   php tests/verify_reference.php [cases=200] [seed=1] [hotel_from-hotel_to]
 *
 *   1. hotelRates(), взрослые без детей       == эталон (id рум-рейтов, итоговая цена, цены по ночам);
 *   2. hotelRooms(), один номер с детьми      == эталон (id рум-рейтов, итоговая цена, цены взрослых по ночам);
 *   3. search(), один номер с детьми          == минимум эталона по каждому отелю (наборы по 40 отелей);
 *   4. search() и hotelRooms()['best'], 2-3 номера == минимум эталона полным перебором рум-рейтов с учётом наличия;
 *   5. search() с фильтрами и сортировкой по убыванию цены.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reference.php';

$cases = isset($argv[1]) ? (int)$argv[1] : 200;
mt_srand(isset($argv[2]) ? (int)$argv[2] : 1);
$model = new Search_Model_Stay($chHttp);
$today = date('Y-m-d');
$hotelIds = $mysql->fetchCol('SELECT id FROM hotels WHERE active = 1');
// необязательный диапазон отелей, напр. 1005-1104 (проверка дублей из tests/inject_duplicates.sql)
if(isset($argv[3]) && preg_match('/^(\d+)-(\d+)$/', $argv[3], $m)) {
    $hotelIds = array_values(array_filter($hotelIds, function($id) use ($m) {
        return $id >= $m[1] && $id <= $m[2];
    }));
}

/** случайный состав номера: чаще 2 взрослых, 0-2 ребёнка 0-17 лет (иногда 1 или 3 взрослых, 3 ребёнка) */
function random_room() {
    $ages = array();
    for($i = mt_rand(0, 9) ? mt_rand(0, 2) : 3; $i > 0; $i--) {
        $ages[] = mt_rand(0, 17);
    }
    $adults = mt_rand(0, 9);
    return array('adults' => $adults < 6 ? 2 : ($adults < 9 ? 1 : 3), 'children' => $ages);
}
function random_checkin($today, $maxOffset) {
    return date('Y-m-d', strtotime("$today 12:00:00 +" . mt_rand(0, $maxOffset) . ' day'));
}
function sample_hotels(array $hotelIds, $n) {
    $sample = array();
    foreach((array)array_rand($hotelIds, min($n, count($hotelIds))) as $i) {
        $sample[] = (int)$hotelIds[$i];
    }
    return $sample;
}
function to_minor_list(array $values) {
    return array_map(function($v) {
        return (int)round($v * 100);
    }, $values);
}

$failures = array();

// ---------- 1. hotelRates(): взрослые без детей (как до поддержки детей)
$fail = 0;
$checked = 0;
$empty = 0;
for($n = 0; $n < $cases; $n++) {
    $hotelId = $hotelIds[mt_rand(0, count($hotelIds) - 1)];
    $nights = mt_rand(1, 14);
    $guests = mt_rand(1, 4);
    $checkin = random_checkin($today, 365 - $nights);
    $expected = array();
    foreach(referenceRates($mysql, $hotelId, $checkin, $nights, $guests, $today) as $rrId => $v) {
        $expected[$rrId] = array('total' => $v['total'], 'nightly' => $v['nightly']);
    }
    $got = array();
    foreach($model->hotelRates($hotelId, array('checkin' => $checkin, 'nights' => $nights, 'guests' => $guests)) as $row) {
        $got[$row['id_rate_room']] = array('total' => $row['price_minor'], 'nightly' => to_minor_list($row['nightly']));
    }
    ksort($got);
    $checked += count($expected);
    $empty += empty($expected) ? 1 : 0;
    if($expected != $got) {
        $fail++;
        printf("MISMATCH adults hotel=%d checkin=%s nights=%d guests=%d\n  expected: %s\n  got:      %s\n", $hotelId, $checkin, $nights, $guests,
            json_encode($expected), json_encode($got));
    }
}
printf("1. hotelRates(), adults only: %d cases, %d room-rate prices compared, %d cases with nothing available, mismatches: %d\n",
    $cases, $checked, $empty, $fail);
$failures[] = $fail;

// ---------- 2. hotelRooms(): один номер с детьми
$fail = 0;
$checked = 0;
$empty = 0;
$withKids = 0;
$moreGuests = 0;
$asAdults = 0;
for($n = 0; $n < $cases; $n++) {
    $hotelId = $hotelIds[mt_rand(0, count($hotelIds) - 1)];
    $nights = mt_rand(1, 10);
    $room = random_room();
    $checkin = random_checkin($today, 365 - $nights);
    $expected = array();
    foreach(referenceRooms($mysql, $hotelId, $checkin, $nights, $room['adults'], $room['children'], $today) as $rrId => $v) {
        $expected[$rrId] = array('total' => $v['total'], 'nightly' => $v['nightly']);
    }
    $offer = $model->hotelRooms($hotelId, array('checkin' => $checkin, 'nights' => $nights, 'rooms' => array($room)));
    $got = array();
    foreach($offer['rooms'][0]['items'] as $row) {
        $got[$row['id_rate_room']] = array('total' => $row['price_minor'], 'nightly' => to_minor_list($row['nightly']));
        $withKids += !empty($row['children']) && ($row['price_children'] > 0 || $row['capped']) ? 1 : 0;
        $moreGuests += $row['guests'] > $row['adults'] && !$row['capped'] ? 1 : 0;      // ребёнок на свободном месте цены
        $asAdults += $row['capped'] ? 1 : 0;                                             // дети посчитаны как взрослые
    }
    ksort($got);
    $checked += count($expected);
    $empty += empty($expected) ? 1 : 0;
    if($expected != $got) {
        $fail++;
        printf("MISMATCH children hotel=%d checkin=%s nights=%d room=%s\n  expected: %s\n  got:      %s\n", $hotelId, $checkin, $nights,
            json_encode($room), json_encode($expected), json_encode($got));
    }
}
printf("2. hotelRooms(), one room with children: %d cases, %d room-rate prices compared (%d with paid children: %d priced for more guests"
    . " than adults, %d children counted as adults), %d empty, mismatches: %d\n", $cases, $checked, $withKids, $moreGuests, $asAdults, $empty, $fail);
$failures[] = $fail;

// ---------- 3. search(): один номер с детьми, минимум по отелю
$fail = 0;
$checked = 0;
$sets = max(3, (int)($cases / 40));
for($n = 0; $n < $sets; $n++) {
    $sample = sample_hotels($hotelIds, 40);
    $nights = mt_rand(1, 10);
    $room = random_room();
    $checkin = random_checkin($today, 300);
    $res = $model->search(array('checkin' => $checkin, 'nights' => $nights, 'rooms' => array($room), 'id_hotel' => $sample, 'limit' => 1000));
    $got = array();
    foreach($res['items'] as $item) {
        $got[$item['id_hotel']] = $item['price_minor'];
    }
    $expected = array();
    foreach($sample as $hotelId) {
        foreach(referenceRooms($mysql, $hotelId, $checkin, $nights, $room['adults'], $room['children'], $today) as $v) {
            if(!isset($expected[$hotelId]) || $v['total'] < $expected[$hotelId]) {
                $expected[$hotelId] = $v['total'];
            }
        }
    }
    ksort($got);
    ksort($expected);
    $checked += count($expected);
    if($got != $expected || $res['total'] != count($expected)) {
        $fail++;
        printf("SEARCH MISMATCH checkin=%s nights=%d room=%s\n  expected: %s\n  got:      %s\n", $checkin, $nights, json_encode($room),
            json_encode($expected), json_encode($got));
    }
}
printf("3. search(), one room with children: %d sets x 40 hotels, %d hotel minimum prices compared, mismatches: %d\n", $sets, $checked, $fail);
$failures[] = $fail;

// ---------- 4. несколько номеров: search() и hotelRooms()['best'] против полного перебора
$fail = 0;
$checked = 0;
$bestChecked = 0;
$sameRoomType = 0;
for($n = 0; $n < $sets; $n++) {
    $sample = sample_hotels($hotelIds, 30);
    $nights = mt_rand(1, 7);
    $rooms = array();
    for($i = mt_rand(2, 3); $i > 0; $i--) {
        $rooms[] = random_room();
    }
    if(0 == $n % 2) {
        $rooms[] = $rooms[0];                        // одинаковые номера: проверка наличия одного типа номера
    }
    $checkin = random_checkin($today, 300);
    $res = $model->search(array('checkin' => $checkin, 'nights' => $nights, 'rooms' => $rooms, 'id_hotel' => $sample, 'limit' => 1000));
    $got = array();
    foreach($res['items'] as $item) {
        $got[$item['id_hotel']] = $item['price_minor'];
        $types = array();
        foreach($item['rooms'] as $r) {
            $types[] = $r['id_room'];
        }
        $sameRoomType += count($types) != count(array_unique($types)) ? 1 : 0;
    }
    $expected = array();
    foreach($sample as $hotelId) {
        $total = referenceHotelTotal($mysql, $hotelId, $checkin, $nights, $rooms, $today);
        if(!is_null($total)) {
            $expected[$hotelId] = $total;
        }
    }
    ksort($got);
    ksort($expected);
    $checked += count($expected);
    if($got != $expected || $res['total'] != count($expected)) {
        $fail++;
        printf("MULTI-ROOM MISMATCH checkin=%s nights=%d rooms=%s\n  expected: %s\n  got:      %s\n", $checkin, $nights, json_encode($rooms),
            json_encode($expected), json_encode($got));
    }
    // карточка отеля: самая дешёвая комбинация
    foreach(array_slice(array_keys($expected), 0, 5) as $hotelId) {
        $offer = $model->hotelRooms($hotelId, array('checkin' => $checkin, 'nights' => $nights, 'rooms' => $rooms));
        $bestChecked++;
        if(!$offer['best'] || $offer['best']['price_minor'] != $expected[$hotelId]) {
            $fail++;
            printf("BEST COMBINATION MISMATCH hotel=%d checkin=%s nights=%d rooms=%s expected=%d got=%s\n", $hotelId, $checkin, $nights,
                json_encode($rooms), $expected[$hotelId], $offer['best'] ? $offer['best']['price_minor'] : 'null');
        }
    }
}
printf("4. several rooms: %d sets x 30 hotels, %d hotel totals compared (%d use one room type twice), %d hotel cards (best combination), mismatches: %d\n",
    $sets, $checked, $sameRoomType, $bestChecked, $fail);
$failures[] = $fail;

// ---------- 5. search() с фильтрами (звёзды, питание, возвратность, цена) и сортировкой по убыванию цены
$fail = 0;
$checked = 0;
$filterSets = array(
    array('stars' => array(4, 5)),
    array('id_board_type' => array(4, 7)),
    array('refundable' => 0),
    array('stars' => array(2, 3), 'refundable' => 1, 'price_min' => 5000),
    array('id_board_type' => array(1), 'price_max' => 20000),
);
foreach($filterSets as $filters) {
    $sample = sample_hotels($hotelIds, 40);
    $nights = mt_rand(2, 7);
    $room = random_room();
    $checkin = random_checkin($today, 300);
    $res = $model->search(array_merge(array('checkin' => $checkin, 'nights' => $nights, 'rooms' => array($room), 'id_hotel' => $sample,
        'order' => '-price', 'limit' => 1000), $filters));
    $got = array();
    $prices = array();
    foreach($res['items'] as $item) {
        $got[$item['id_hotel']] = $item['price_minor'];
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
        foreach(referenceRooms($mysql, $hotelId, $checkin, $nights, $room['adults'], $room['children'], $today) as $rrId => $v) {
            $rate = $mysql->fetchRow('SELECT t.id_board_type, t.id_cancel_policy FROM hotels_rates_rooms rr JOIN hotels_rates t ON t.id = rr.id_rate WHERE rr.id = ?', array($rrId));
            if((!empty($filters['id_board_type']) && !in_array((int)$rate['id_board_type'], $filters['id_board_type']))
                || (isset($filters['refundable']) && (int)!empty($rate['id_cancel_policy']) != $filters['refundable'])) {
                continue;
            }
            if(!isset($expected[$hotelId]) || $v['total'] < $expected[$hotelId]) {
                $expected[$hotelId] = $v['total'];
            }
        }
        // фильтр цены — по цене отеля (минимум), как в поиске
        if(isset($expected[$hotelId]) && ((isset($filters['price_min']) && $expected[$hotelId] < $filters['price_min'] * 100)
            || (isset($filters['price_max']) && $expected[$hotelId] > $filters['price_max'] * 100))) {
            unset($expected[$hotelId]);
        }
    }
    ksort($got);
    ksort($expected);
    $checked += count($expected);
    if($got != $expected || $prices !== $sorted) {
        $fail++;
        printf("FILTER MISMATCH %s checkin=%s nights=%d room=%s\n  expected: %s\n  got:      %s\n", json_encode($filters), $checkin, $nights,
            json_encode($room), json_encode($expected), json_encode($got));
    }
}
printf("5. search() with filters: %d filter sets, %d hotel minimum prices compared, mismatches: %d\n", count($filterSets), $checked, $fail);
$failures[] = $fail;

printf("nights priced from hotels_rates_occupancy_daily in the reference: %d\n", isset($GLOBALS['referenceDailyHits']) ? $GLOBALS['referenceDailyHits'] : 0);
exit(array_sum($failures) ? 1 : 0);
