<?php
/**
 * Проверка Search_Occupancy без базы: случайные номера и составы против полного перебора.
 *   php tests/occupancy_unit.php [cases=20000] [seed=1]
 *
 *   price()                — «свободные места цены номера занимают самые дорогие дети» == минимум полного перебора
 *                            подмножеств детей; ближайшее продаваемое число гостей (в т.ч. с закрытыми ночами — сумма >= 2^40);
 *                            лимиты; цены 5 типов; «не дороже взрослых» (и выключенное children_as_adults = 0);
 *   cheapestCombination()  — отсечение до K лучших типов номера == полный перебор всех вариантов с учётом наличия;
 *   rooms()                — разбор параметров запроса.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require __DIR__ . '/../library/Search/ClickHouse/Exception.php';
require __DIR__ . '/../library/Search/Occupancy.php';

$cases = isset($argv[1]) ? (int)$argv[1] : 20000;
mt_srand(isset($argv[2]) ? (int)$argv[2] : 1);

/** цена ребёнка своей формулой (float + round half up вместо целочисленного деления) */
function brute_child($type, $value, $room, $g, $nights) {
    switch($type) {
        case 2: return $value * $nights;
        case 3: return (int)round($room * $value / 10000);
        case 4: return (int)round($room * $value / 10000 / $g);
        case 5: return (int)round($room / $g);
        default: return 0;
    }
}

/** полный перебор: какие дети занимают свободные места цены номера (все подмножества нужного размера) */
function brute_price(array $rr, $adults, array $ages) {
    $a = $adults;
    $kids = array();                          // array(группа, младенец, занимает место)
    foreach($ages as $age) {
        if($age < $rr['children_min_age']) {
            return null;
        }
        $j = $rr['age_group'][$age];
        if(!$j) {
            $a++;
            continue;
        }
        $inf = $age <= 2;
        $kids[] = array($j, $inf, !($inf && $rr['infants_excluded']));
    }
    $placed = 0;
    $infants = 0;
    foreach($kids as $kid) {
        $placed += $kid[2] ? 1 : 0;
        $infants += $kid[1] ? 1 : 0;
    }
    if($a < 1 || $a > 8 || $placed > $rr['max_children'] || $a + $placed > $rr['max_occupancy']
        || (255 != $rr['max_infants'] && $infants > $rr['max_infants'])) {
        return null;
    }
    $sold = function($x) use ($rr) {
        return ($rr['gmask'] >> $x) & 1 && $rr['adults'][$x] < Search_Occupancy::CLOSED;
    };
    $g = null;
    for($x = $a; $x <= min(8, $a + $placed); $x++) {
        if($sold($x)) {
            $g = $x;
            break;
        }
    }
    if(is_null($g)) {
        return null;
    }
    $prices = array();
    foreach($kids as $kid) {
        $prices[] = brute_child($rr['child_type'][$kid[0]], $rr['child_value'][$kid[0]], $rr['adults'][$g], $g, $rr['nights']);
    }
    // все подмножества детей с местом размера g - a: бесплатно; берётся минимальный итог
    $best = null;
    for($mask = 0; $mask < (1 << count($kids)); $mask++) {
        $size = 0;
        $ok = true;
        foreach($kids as $bit => $kid) {
            if(($mask >> $bit) & 1) {
                $size++;
                $ok = $ok && $kid[2];
            }
        }
        if(!$ok || $size != $g - $a) {
            continue;
        }
        $total = $rr['adults'][$g];
        $paying = 0;
        foreach($kids as $bit => $kid) {
            if(!(($mask >> $bit) & 1)) {
                $total += $prices[$bit];
                $paying += $prices[$bit] > 0 ? 1 : 0;
            }
        }
        if(is_null($best) || $total < $best[0]) {
            $best = array($total, $paying);
        }
    }
    list($total, $paying) = $best;
    if($rr['children_as_adults'] && $paying > 0 && $g + $paying <= 8 && $sold($g + $paying)) {
        $total = min($total, $rr['adults'][$g + $paying]);
    }
    return $total;
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
    $rr = array('gmask' => 0, 'max_children' => mt_rand(0, 4), 'max_occupancy' => mt_rand(2, 7),
        'max_infants' => mt_rand(0, 3) ? 255 : mt_rand(0, 2), 'infants_excluded' => mt_rand(0, 1),
        'children_min_age' => mt_rand(0, 9) ? (mt_rand(0, 3) ? 0 : 3) : 255, 'age_group' => $ageGroup,
        'children_as_adults' => mt_rand(0, 3) ? 1 : 0,
        'adults' => array(), 'child_type' => array(), 'child_value' => array(), 'nights' => mt_rand(1, 14));
    $flat = mt_rand(0, 2) == 0;                       // одна цена за номер для любого числа гостей
    $base = mt_rand(50, 300) * 1000 * $rr['nights'];
    for($g = 1; $g <= 8; $g++) {
        if($g <= min($rr['max_occupancy'], mt_rand(1, 8)) && mt_rand(0, 4)) {
            $rr['gmask'] |= 1 << $g;
        }
        // цены в кэше не бывают меньше 0 (UInt64, сборщик ограничивает нулём)
        $rr['adults'][$g] = $flat ? $base : max(0, $base + ($g - 2) * mt_rand(-20, 60) * 1000 * $rr['nights']);
        if(!mt_rand(0, 7)) {
            $rr['adults'][$g] += Search_Occupancy::CLOSED * mt_rand(1, $rr['nights']);     // дневная цена 0: закрыты 1..n ночей
        }
    }
    for($j = 1; $j <= $groups; $j++) {
        $rr['child_type'][$j] = mt_rand(1, 5);
        $rr['child_value'][$j] = 2 == $rr['child_type'][$j] ? mt_rand(0, 50) * 1000 : mt_rand(0, 100) * 100;
    }
    return $rr;
}

// ---------- price(): «самые дорогие дети на свободные места» == полный перебор
$fail = 0;
$feasible = 0;
$covered = 0;
$capped = 0;
$closedSeen = 0;
$asAdultsOff = 0;
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
    $covered += ($got && $got['guests'] > $got['adults'] && !$got['capped']) ? 1 : 0;
    $capped += ($got && $got['capped']) ? 1 : 0;
    $closedSeen += ($got && $rr['adults'][$adults] >= Search_Occupancy::CLOSED) ? 1 : 0;
    // выключенное «не дороже, чем взрослым» изменило цену
    $asAdultsOff += (!$rr['children_as_adults'] && $got && $got['total'] != brute_price(array_merge($rr, array('children_as_adults' => 1)), $adults, $ages)) ? 1 : 0;
    if((is_null($got) ? null : $got['total']) !== $expected) {
        $fail++;
        if($fail <= 5) {
            printf("PRICE MISMATCH adults=%d ages=%s rr=%s\n  expected: %s got: %s\n", $adults, json_encode($ages), json_encode($rr),
                var_export($expected, true), json_encode($got));
        }
    }
}
printf("price(): %d random rooms, %d feasible (%d priced for more guests than adults, %d children counted as adults,"
    . " %d with nights closed for the adults' number of guests, %d dearer with children_as_adults = 0), mismatches with brute force: %d\n",
    $cases, $feasible, $covered, $capped, $closedSeen, $asAdultsOff, $fail);
$failures = $fail;

// ---------- примеры из обсуждения (README): 1 взрослый + 7 и 9 лет по 50% взрослого, «1 гость» выключен, 2 гостя 100, 3 гостя 115
$rr = array('gmask' => (1 << 2) | (1 << 3), 'max_children' => 2, 'max_occupancy' => 3, 'max_infants' => 255, 'infants_excluded' => 0,
    'children_min_age' => 0, 'age_group' => array_merge(array_fill(0, 3, 1), array_fill(3, 15, 2)),
    'child_type' => array(1 => 1, 2 => 4), 'child_value' => array(1 => 0, 2 => 5000), 'adults' => array(2 => 10000, 3 => 11500), 'nights' => 1,
    'children_as_adults' => 1);
$p = Search_Occupancy::price($rr, 1, array(7, 9));
$examples = array(array('1 adult + 7, 9 y.o., 1 guest off: 100 + 25 -> capped to 115', 11500, $p['total']));
$p = Search_Occupancy::price(array_merge($rr, array('children_as_adults' => 0)), 1, array(7, 9));
$examples[] = array('... children_as_adults = 0: 100 + 25 (by the children policy)', 12500, $p['total']);
$rr['gmask'] = (1 << 1) | (1 << 2) | (1 << 3);
$rr['adults'] = array(1 => 14000, 2 => 14000, 3 => 14000);  // per room 1400: одна цена для любого числа гостей
$rr['child_value'][2] = 5000;
$p = Search_Occupancy::price($rr, 2, array(12));
$examples[] = array('per room 1400, 2 adults + 12 y.o. (50% of adult): as 3 adults = 1400', 14000, $p['total']);
$p = Search_Occupancy::price(array_merge($rr, array('children_as_adults' => 0)), 2, array(12));
$examples[] = array('... children_as_adults = 0: 1400 + 1400 / 2 x 50% = 1750', 17500, $p['total']);
$rr['gmask'] = (1 << 1) | (1 << 2);
$p = Search_Occupancy::price($rr, 2, array(12));
$examples[] = array('... max 2 adults: 1400 + 1400 / 2 x 50% = 1750', 17500, $p['total']);
foreach($examples as $e) {
    printf("example: %-72s expected %6d got %6d %s\n", $e[0], $e[1], $e[2], $e[1] == $e[2] ? 'OK' : 'FAIL');
    $failures += $e[1] == $e[2] ? 0 : 1;
}

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
