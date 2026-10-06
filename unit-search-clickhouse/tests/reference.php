<?php
/**
 * Эталонный расчёт "в лоб" по ночам прямо из MySQL — независимая от Search_Sync_Builder и Search_Occupancy реализация
 * тех же правил. Используется в verify_reference.php и queue_flow.php. При дублях строк берётся строка с максимальным id.
 * Какие дети занимают свободные места цены номера — полным перебором подмножеств, несколько номеров — полным перебором
 * рум-рейтов, поэтому эталон проверяет и выбор «самых дорогих детей», и отсечение вариантов в поиске.
 */

define('REFERENCE_INFANT_AGE_MAX', 2);

/** half up для неотрицательных: своя формула через float (суммы в копейках < 2^53) */
function reference_round($x) {
    return (int)floor($x + 0.5);
}

/**
 * Совместимость: взрослые без детей. Возвращает id_rate_room => array('total', 'nightly', ...).
 */
function referenceRates($db, $hotelId, $checkin, $nights, $guests, $today, $channel = 1) {
    return referenceRooms($db, $hotelId, $checkin, $nights, $guests, array(), $today, $channel);
}

/**
 * Эталон: доступные рум-рейты отеля для одного номера (взрослые + возраста детей).
 * @return array id_rate_room => array('total' => копейки, 'nightly' => цены номера по ночам, 'id_room', 'rooms_left')
 */
function referenceRooms($db, $hotelId, $checkin, $nights, $adults, array $ages, $today, $channel = 1) {
    $days = array();
    for($i = 0; $i <= $nights; $i++) {
        $days[] = date('Y-m-d', strtotime("$checkin 12:00:00 +$i day"));
    }
    $checkout = $days[$nights];
    $adv = (int)round((strtotime("$checkin 12:00:00") - strtotime("$today 12:00:00")) / 86400);
    $hotel = $db->fetchRow('SELECT * FROM hotels WHERE id = ?', array($hotelId));
    if(!$hotel || !$hotel['active']) {
        return array();
    }
    // дети: отель не принимает детей / младше минимального возраста -> ничего не подходит
    foreach($ages as $age) {
        if((!is_null($hotel['allow_children']) && !$hotel['allow_children']) || $age < (int)$hotel['children_min_age']) {
            return array();
        }
    }
    $rooms = $db->fetchAssoc('SELECT * FROM hotels_rooms WHERE id_hotel = ? AND active = 1', array($hotelId));
    $rates = $db->fetchAssoc('SELECT * FROM hotels_rates WHERE id_hotel = ? AND active = 1', array($hotelId));
    if(!$rooms || !$rates) {
        return array();
    }
    $rrs = $db->fetchAssoc('SELECT * FROM hotels_rates_rooms WHERE id_room IN (' . implode(',', array_keys($rooms)) . ')
        AND id_rate IN (' . implode(',', array_keys($rates)) . ')');
    $price = function($rrId, $date) use ($db) {
        return $db->fetchRow('SELECT * FROM hotels_rates_prices WHERE id_rate_room = ? AND date = ? ORDER BY id DESC LIMIT 1', array($rrId, $date));
    };
    $occRow = function($rrId, $g) use ($db) {
        return $rrId ? $db->fetchRow('SELECT * FROM hotels_rates_occupancy WHERE id_rate_room = ? AND guests = ? ORDER BY id DESC LIMIT 1', array($rrId, $g)) : false;
    };
    $daily = function($rrId, $g, $date) use ($db) {
        $v = $rrId ? $db->fetchOne('SELECT price FROM hotels_rates_occupancy_daily WHERE id_rate_room = ? AND guests = ? AND date = ?
            ORDER BY id DESC LIMIT 1', array($rrId, $g, $date)) : false;
        return (false !== $v && !is_null($v)) ? (int)round($v * 100) : null;      // <= 0 — на g гостей в эту ночь не продаётся
    };
    // строка окна к цене: процент или сумма, не меньше 0; нет строки — цена как есть
    $apply = function($base, $row) {
        if(!$row) {
            return $base;
        }
        if(1 == $row['amount_type']) {
            return max(0, $base + (int)round($row['amount'] * 100));
        }
        $bp = (int)round($row['amount'] * 100);
        return 0 == $bp ? $base : max(0, reference_round($base * (10000 + $bp) / 10000));
    };
    // детская политика отеля: группа возраста — активная группа с наибольшим id среди покрывающих возраст
    $kidsAll = array();
    $a0 = (int)$adults;
    foreach($ages as $age) {
        $g = $db->fetchRow('SELECT * FROM hotels_children_groups WHERE active = 1 AND id_hotel = ? AND age_from <= ? AND age_to >= ?
            ORDER BY id DESC LIMIT 1', array($hotelId, $age, $age));
        if($g) {
            $kidsAll[] = array($age, $g);
        } else {
            $a0++;                                              // возраст вне групп — взрослый
        }
    }
    $result = array();
    foreach($rrs as $rrId => $rr) {
        $room = $rooms[$rr['id_room']];
        $rate = $rates[$rr['id_rate']];
        if(!((int)$rate['channel_mask'] & $channel) || 'public' != $rate['visibility'] || $rate['id_hotel'] != $room['id_hotel']) {
            continue;
        }
        $parentRrId = null;
        $parentRr = null;
        if($rate['id_parent']) {
            $parentRr = $db->fetchRow('SELECT * FROM hotels_rates_rooms WHERE id_rate = ? AND id_room = ? ORDER BY id LIMIT 1',
                array($rate['id_parent'], $rr['id_room']));
            $parentRrId = $parentRr ? (int)$parentRr['id'] : null;
        }
        // лимиты номера; base_occupancy: свой рум-рейта (у производного — родителя), иначе номера
        $rrBase = $rate['id_parent'] ? ($parentRr ? $parentRr['base_occupancy'] : null) : $rr['base_occupancy'];
        $baseOcc = max(1, (int)(is_null($rrBase) ? $room['base_occupancy'] : $rrBase));
        $maxAdults = (int)$room['max_adults'] > 0 ? (int)$room['max_adults'] : max((int)$room['max_occupancy'], $baseOcc);
        $maxOcc = (int)$room['max_occupancy'] > 0 ? (int)$room['max_occupancy'] : $maxAdults + (int)$room['max_children'];
        $sold = function($g) use ($rrId, $parentRrId, $rate, $maxAdults, $maxOcc, $occRow) {
            if($g < 1 || $g > 8 || $g > $maxAdults || $g > $maxOcc) {
                return false;
            }
            $own = $occRow($rrId, $g);
            if($own) {
                return (bool)$own['active'];
            }
            if($rate['id_parent']) {
                $par = $occRow($parentRrId, $g);
                return !$par || (bool)$par['active'];
            }
            return true;
        };
        $a = $a0;
        $placed = 0;
        $infants = 0;
        foreach($kidsAll as $kid) {
            $inf = $kid[0] <= REFERENCE_INFANT_AGE_MAX;
            $infants += $inf ? 1 : 0;
            $placed += ($inf && $room['is_without_infants']) ? 0 : 1;
        }
        if($a > 8 || $placed > (int)$room['max_children'] || $a + $placed > $maxOcc
            || (!is_null($room['max_infants']) && $infants > (int)$room['max_infants'])) {
            continue;
        }
        // цена номера за $guests гостей в ночь $i: null — ночь не продаётся совсем, array(база, null) — закрыта на $guests гостей
        $nightPrice = function($guests, $i) use ($days, $rrId, $parentRrId, $rate, $price, $occRow, $daily, $apply) {
            $own = $price($rrId, $days[$i]);
            $parBase = null;
            $dv = 0;
            $base = null;
            if(!$rate['id_parent']) {
                if($own && $own['active'] && !is_null($own['price'])) {
                    $base = (int)round($own['price'] * 100);
                }
            } elseif($own && !$own['active']) {
                return null;
            } elseif($own && !is_null($own['price']) && 0 == $own['derive_type']) {
                $base = (int)round($own['price'] * 100);
            } elseif($parentRrId && ($par = $price($parentRrId, $days[$i])) && $par['active'] && !is_null($par['price'])) {
                $parBase = (int)round($par['price'] * 100);
                $dv = ($own && 4 == $own['derive_type']) ? (int)$own['derive_value'] : -(int)$rate['derive_value'];
                $base = reference_round($parBase * (100 + $dv) / 100);
            }
            if(is_null($base) || $base <= 0) {
                return null;
            }
            $d = $daily($rrId, $guests, $days[$i]);
            if(!is_null($d)) {
                $GLOBALS['referenceDailyHits'] = (isset($GLOBALS['referenceDailyHits']) ? $GLOBALS['referenceDailyHits'] : 0) + 1;
                if($d <= 0) {
                    $GLOBALS['referenceDailyClosed'] = (isset($GLOBALS['referenceDailyClosed']) ? $GLOBALS['referenceDailyClosed'] : 0) + 1;
                    return array($base, null);
                }
                return array($base, $d);
            }
            $ownRow = $occRow($rrId, $guests);
            if($ownRow || !$rate['id_parent']) {
                return array($base, $apply($base, $ownRow));
            }
            $parRow = $occRow($parentRrId, $guests);
            if(is_null($parBase)) {
                return array($base, $apply($base, $parRow));             // своя цена ночи: строка родителя к своей цене
            }
            $pd = $daily($parentRrId, $guests, $days[$i]);
            if(!is_null($pd) && $pd <= 0) {
                $GLOBALS['referenceDailyClosed'] = (isset($GLOBALS['referenceDailyClosed']) ? $GLOBALS['referenceDailyClosed'] : 0) + 1;
                return array($base, null);                                // закрыто у родителя — закрыто и у производного
            }
            return array($base, reference_round((is_null($pd) ? $apply($parBase, $parRow) : $pd) * (100 + $dv) / 100));
        };
        $ok = true;
        $roomsLeft = PHP_INT_MAX;
        for($i = 0; $i < $nights && $ok; $i++) {
            $av = $db->fetchRow('SELECT * FROM hotels_rooms_availability WHERE id_room = ? AND date = ? ORDER BY id DESC LIMIT 1', array($rr['id_room'], $days[$i]));
            $allot = ($av && !is_null($av['allotment'])) ? (int)$av['allotment'] : (int)$room['allotment'];
            $free = $allot - ($av ? (int)$av['net_booked'] : 0);
            $ok = !($av && !$av['active']) && $free > 0 && !is_null($nightPrice(1, $i));
            $roomsLeft = min($roomsLeft, $free);
        }
        if(!$ok) {
            continue;
        }
        // restrictions: arrival day and departure day (own price row, rate defaults otherwise)
        $arr = $price($rrId, $checkin);
        $minLos = ($arr && !is_null($arr['min_los'])) ? (int)$arr['min_los'] : (int)$rate['min_los'];
        $maxLos = ($arr && $arr['max_los'] > 0) ? (int)$arr['max_los'] : 999;
        $minAdv = ($arr && !is_null($arr['min_adv'])) ? (int)$arr['min_adv'] : (int)$rate['min_adv'];
        $maxAdv = ($arr && $arr['max_adv'] > 0) ? (int)$arr['max_adv'] : 9999;
        if(($arr && $arr['cta']) || max(1, $minLos) > $nights || $maxLos < $nights || max(0, $minAdv) > $adv || $maxAdv < $adv) {
            continue;
        }
        $dep = $price($rrId, $checkout);
        if($dep && $dep['ctd']) {
            continue;
        }
        // цены номера за $guests гостей по ночам; null — на $guests гостей не продаётся (выключено или закрыта хотя бы одна ночь)
        $stay = function($guests) use ($nights, $nightPrice, $sold) {
            if(!$sold($guests)) {
                return null;
            }
            $list = array();
            for($i = 0; $i < $nights; $i++) {
                $p = $nightPrice($guests, $i);
                if(is_null($p[1])) {
                    return null;
                }
                $list[] = $p[1];
            }
            return $list;
        };
        $g = null;
        $nightly = null;
        for($x = $a; $x <= $a + $placed && is_null($g); $x++) {
            $nightly = $stay($x);
            $g = is_null($nightly) ? null : $x;
        }
        if(is_null($g)) {
            continue;
        }
        $roomPrice = array_sum($nightly);
        // цены детей от цены номера за g гостей; свободные места цены (g - a) — полный перебор подмножеств детей с местом
        $kidPrice = array();
        $kidPlaced = array();
        foreach($kidsAll as $n => $kid) {
            $v = (int)round($kid[1]['price_value'] * 100);
            switch((int)$kid[1]['price_type']) {
                case 2: $kidPrice[$n] = $v * $nights; break;
                case 3: $kidPrice[$n] = reference_round($roomPrice * $v / 10000); break;
                case 4: $kidPrice[$n] = reference_round($roomPrice * $v / 10000 / $g); break;
                case 5: $kidPrice[$n] = reference_round($roomPrice / $g); break;
                default: $kidPrice[$n] = 0;
            }
            $kidPlaced[$n] = !($kid[0] <= REFERENCE_INFANT_AGE_MAX && $room['is_without_infants']);
        }
        $best = null;
        for($mask = 0; $mask < (1 << count($kidsAll)); $mask++) {
            $size = 0;
            foreach($kidsAll as $n => $kid) {
                if(($mask >> $n) & 1) {
                    $size += $kidPlaced[$n] ? 1 : 100;
                }
            }
            if($size != $g - $a) {
                continue;
            }
            $sum = $roomPrice;
            $paying = 0;
            foreach($kidsAll as $n => $kid) {
                if(!(($mask >> $n) & 1)) {
                    $sum += $kidPrice[$n];
                    $paying += $kidPrice[$n] > 0 ? 1 : 0;
                }
            }
            if(is_null($best) || $sum < $best[0]) {
                $best = array($sum, $paying);
            }
        }
        list($total, $paying) = $best;
        // не дороже, чем если бы платящие дети были взрослыми
        if($paying > 0 && !is_null($asAdults = $stay($g + $paying))) {
            if(array_sum($asAdults) < $total) {
                $total = array_sum($asAdults);
                $nightly = $asAdults;
            }
        }
        $result[$rrId] = array('total' => $total, 'nightly' => $nightly, 'id_room' => (int)$rr['id_room'], 'rooms_left' => $roomsLeft);
    }
    ksort($result);
    return $result;
}

/**
 * Эталон для нескольких номеров: минимальная сумма полным перебором рум-рейтов с учётом свободных номеров каждого типа.
 * @param array $rooms список array('adults' => .., 'children' => array(..))
 * @return int|null копейки
 */
function referenceHotelTotal($db, $hotelId, $checkin, $nights, array $rooms, $today, $channel = 1) {
    $options = array();
    foreach($rooms as $i => $room) {
        $options[$i] = array_values(referenceRooms($db, $hotelId, $checkin, $nights, $room['adults'], $room['children'], $today, $channel));
        if(empty($options[$i])) {
            return null;
        }
    }
    $best = null;
    $walk = function($i, $used, $sum) use (&$walk, &$best, $options) {
        if($i == count($options)) {
            $best = is_null($best) ? $sum : min($best, $sum);
            return;
        }
        foreach($options[$i] as $o) {
            $n = isset($used[$o['id_room']]) ? $used[$o['id_room']] : 0;
            if($n < $o['rooms_left']) {
                $used[$o['id_room']] = $n + 1;
                $walk($i + 1, $used, $sum + $o['total']);
                $used[$o['id_room']] = $n;
            }
        }
    };
    $walk(0, array(), 0);
    return $best;
}
