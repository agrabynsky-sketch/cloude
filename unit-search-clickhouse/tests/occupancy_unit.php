<?php
/**
 * Проверка Search_Occupancy без базы: случайные номера и составы против полного перебора.
 *   php tests/occupancy_unit.php [cases=20000] [seed=1]
 *
 *   price()                — жадное размещение детей (обязательные места, затем по выгоде) == минимум полного перебора;
 *   cheapestCombination()  — отсечение до K лучших типов номера == полный перебор всех вариантов с учётом наличия;
 *   rooms()                — разбор параметров запроса.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require __DIR__ . '/../library/Search/ClickHouse/Exception.php';
require __DIR__ . '/../library/Search/Occupancy.php';

$cases = isset($argv[1]) ? (int)$argv[1] : 20000;
mt_srand(isset($argv[2]) ? (int)$argv[2] : 1);
$big = Search_Occupancy::NOT_ALLOWED;

/** полный перебор: каждому ребёнку с местом — существующая или доп. кровать */
function brute_price(array $rr, $adults, array $ages) {
    $a = $adults;
    $placed = array();
    $sum = 0;
    foreach($ages as $age) {
        if($age < $rr['children_min_age']) {
            return null;
        }
        $j = $rr['age_group'][$age];
        if(!$j) {
            $a++;
            continue;
        }
        $bed = $rr['bed'][$j] >= Search_Occupancy::NOT_ALLOWED ? null : $rr['bed'][$j];
        $extra = $rr['extra'][$j] >= Search_Occupancy::NOT_ALLOWED ? null : $rr['extra'][$j];
        if($rr['infants_excluded'] && $age <= Search_Occupancy::INFANT_AGE_MAX) {
            if(is_null($bed) && is_null($extra)) {
                return null;
            }
            $sum += is_null($bed) ? $extra : (is_null($extra) ? $bed : min($bed, $extra));
            continue;
        }
        $placed[] = array($bed, $extra);
    }
    if($a < 1 || $a > 8 || !(($rr['gmask'] >> $a) & 1) || count($placed) > $rr['max_children'] || $a + count($placed) > $rr['max_occupancy']) {
        return null;
    }
    $bedSlots = max(0, $rr['places_regular'] - $a) + $rr['share_slots'];
    $extraSlots = $rr['extra_beds'] - max(0, $a - $rr['places_regular']);
    $best = null;
    for($mask = 0; $mask < (1 << count($placed)); $mask++) {
        $s = 0;
        $beds = 0;
        foreach($placed as $bit => $p) {
            $onBed = ($mask >> $bit) & 1;
            $c = $p[$onBed ? 0 : 1];
            if(is_null($c)) {
                continue 2;
            }
            $s += $c;
            $beds += $onBed;
        }
        if($beds <= $bedSlots && count($placed) - $beds <= $extraSlots && (is_null($best) || $s < $best)) {
            $best = $s;
        }
    }
    return is_null($best) ? null : $rr['adults'][$a] + $sum + $best;
}

function random_rr() {
    $groups = mt_rand(0, 4);
    $ageGroup = array_fill(0, 18, 0);
    $age = 0;
    for($j = 1; $j <= $groups && $age < 18; $j++) {
        $to = min(17, $age + mt_rand(0, 6));
        for($x = $age; $x <= $to; $x++) {
            $ageGroup[$x] = $j;
        }
        $age = $to + 1 + (mt_rand(0, 4) ? 0 : 1);     // иногда пропуск возраста -> взрослый
    }
    $price = function() {
        $r = mt_rand(0, 9);
        return $r < 2 ? Search_Occupancy::NOT_ALLOWED * mt_rand(1, 3) : ($r < 4 ? 0 : mt_rand(1, 50) * 1000);
    };
    $rr = array('gmask' => 0, 'places_regular' => mt_rand(1, 4), 'extra_beds' => mt_rand(0, 2), 'share_slots' => mt_rand(0, 2),
        'max_children' => mt_rand(0, 4), 'max_occupancy' => mt_rand(2, 7), 'infants_excluded' => mt_rand(0, 1),
        'children_min_age' => mt_rand(0, 9) ? (mt_rand(0, 3) ? 0 : 3) : 255, 'age_group' => $ageGroup,
        'adults' => array(), 'bed' => array(), 'extra' => array());
    for($g = 1; $g <= 8; $g++) {
        if($g <= $rr['places_regular'] + $rr['extra_beds'] && mt_rand(0, 5)) {
            $rr['gmask'] |= 1 << $g;
        }
        $rr['adults'][$g] = $g * mt_rand(50, 100) * 1000;
    }
    for($j = 1; $j <= $groups; $j++) {
        $rr['bed'][$j] = $price();
        $rr['extra'][$j] = $price();
    }
    return $rr;
}

// ---------- price(): жадный алгоритм == полный перебор
$fail = 0;
$feasible = 0;
for($n = 0; $n < $cases; $n++) {
    $rr = random_rr();
    $ages = array();
    for($i = mt_rand(0, 5); $i > 0; $i--) {
        $ages[] = mt_rand(0, 17);
    }
    $adults = mt_rand(1, 4);
    $expected = brute_price($rr, $adults, $ages);
    $got = Search_Occupancy::price($rr, $adults, $ages);
    $feasible += is_null($expected) ? 0 : 1;
    if((is_null($got) ? null : $got['total']) !== $expected) {
        $fail++;
        if($fail <= 5) {
            printf("PRICE MISMATCH adults=%d ages=%s rr=%s\n  expected: %s got: %s\n", $adults, json_encode($ages), json_encode($rr),
                var_export($expected, true), json_encode($got));
        }
    }
}
printf("price(): %d random rooms, %d feasible, mismatches with brute force: %d\n", $cases, $feasible, $fail);
$failures = $fail;

// ---------- cheapestCombination(): отсечение == полный перебор
$fail = 0;
$found = 0;
$combCases = (int)($cases / 4);
for($n = 0; $n < $combCases; $n++) {
    $k = mt_rand(1, 4);
    $roomsLeft = array();
    for($r = 1; $r <= 6; $r++) {
        $roomsLeft[$r] = mt_rand(1, 3);
    }
    $options = array();
    for($i = 0; $i < $k; $i++) {
        $options[$i] = array();
        for($m = mt_rand(0, 8); $m > 0; $m--) {
            $idRoom = mt_rand(1, 6);
            $options[$i][] = array('price' => mt_rand(1, 20) * 100, 'id_room' => $idRoom, 'rooms_left' => $roomsLeft[$idRoom]);
        }
    }
    $expected = null;
    $walk = function($i, $used, $sum) use (&$walk, &$expected, $options) {
        if($i == count($options)) {
            $expected = is_null($expected) ? $sum : min($expected, $sum);
            return;
        }
        foreach($options[$i] as $o) {
            $u = isset($used[$o['id_room']]) ? $used[$o['id_room']] : 0;
            if($u < $o['rooms_left']) {
                $used[$o['id_room']] = $u + 1;
                $walk($i + 1, $used, $sum + $o['price']);
                $used[$o['id_room']] = $u;
            }
        }
    };
    $walk(0, array(), 0);
    $got = Search_Occupancy::cheapestCombination($options);
    $found += is_null($expected) ? 0 : 1;
    if((is_null($got) ? null : $got['total']) !== $expected) {
        $fail++;
        if($fail <= 5) {
            printf("COMBINATION MISMATCH options=%s expected=%s got=%s\n", json_encode($options), var_export($expected, true), json_encode($got));
        }
    }
}
printf("cheapestCombination(): %d random requests (1-4 rooms), %d with a combination, mismatches with brute force: %d\n", $combCases, $found, $fail);
$failures += $fail;

// ---------- rooms(): разбор параметров
$checks = array(
    array(array('guests' => 3), array(array('adults' => 3, 'children' => array()))),
    array(array('adults' => 2, 'children' => '7,4'), array(array('adults' => 2, 'children' => array(7, 4)))),
    array(array('rooms' => '[{"adults":2,"children":[5]},{"adults":1}]'), array(array('adults' => 2, 'children' => array(5)), array('adults' => 1, 'children' => array()))),
    array(array('rooms' => array(array('adults' => '2', 'children' => array('0', '17')))), array(array('adults' => 2, 'children' => array(0, 17)))),
);
$bad = array(array('adults' => 0), array('adults' => 2, 'children' => '18'), array('rooms' => 'x'),
    array('rooms' => array_fill(0, 5, array('adults' => 1))), array('adults' => 2, 'children' => '1,2,3,4,5,6,7'));
$fail = 0;
foreach($checks as $c) {
    $fail += Search_Occupancy::rooms($c[0]) === $c[1] ? 0 : 1;
}
foreach($bad as $c) {
    try {
        Search_Occupancy::rooms($c);
        $fail++;
    } catch(Search_ClickHouse_Exception $e) {
        $fail += 400 == $e->getCode() ? 0 : 1;
    }
}
printf("rooms(): %d valid and %d invalid requests, failures: %d\n", count($checks), count($bad), $fail);
$failures += $fail;

echo $failures ? "\nFAILED: $failures\n" : "\nALL OK\n";
exit($failures ? 1 : 0);
