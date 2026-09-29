<?php
/**
 * Эталонный расчёт "в лоб" по ночам прямо из MySQL — независимая от Search_Sync_Builder и Search_Occupancy реализация
 * тех же правил. Используется в verify_reference.php и queue_flow.php. При дублях строк берётся строка с максимальным id.
 * Размещение детей — полным перебором (каждому ребёнку существующая или доп. кровать), несколько номеров — полным
 * перебором рум-рейтов, поэтому эталон проверяет и жадный алгоритм размещения, и отсечение вариантов в поиске.
 */

define('REFERENCE_INFANT_AGE_MAX', 2);

/**
 * Совместимость: взрослые без детей. Возвращает id_rate_room => array('total', 'nightly', ...).
 */
function referenceRates($db, $hotelId, $checkin, $nights, $guests, $today, $channel = 1) {
    return referenceRooms($db, $hotelId, $checkin, $nights, $guests, array(), $today, $channel);
}

/**
 * Эталон: доступные рум-рейты отеля для одного номера (взрослые + возраста детей).
 * @return array id_rate_room => array('total' => копейки, 'nightly' => цены взрослых по ночам, 'id_room', 'rooms_left')
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
    $extraAdult = is_null($hotel['extra_bed_adult_price']) ? null : (int)round($hotel['extra_bed_adult_price'] * 100);
    $result = array();
    foreach($rrs as $rrId => $rr) {
        $room = $rooms[$rr['id_room']];
        $rate = $rates[$rr['id_rate']];
        if(!((int)$rate['channel_mask'] & $channel) || 'public' != $rate['visibility'] || $rate['id_hotel'] != $room['id_hotel']) {
            continue;
        }
        // детские группы тарифа (свои группы тарифа, иначе группы отеля) и группа каждого ребёнка
        $groupRate = $db->fetchOne('SELECT COUNT(*) FROM hotels_children_groups WHERE active = 1 AND id_hotel = ? AND id_rate = ?',
            array($hotelId, $rate['id'])) ? (int)$rate['id'] : 0;
        $a = (int)$adults;
        $kids = array();       // array(age, group row)
        foreach($ages as $age) {
            $g = $db->fetchRow('SELECT * FROM hotels_children_groups WHERE active = 1 AND id_hotel = ? AND id_rate = ?
                AND age_from <= ? AND age_to >= ? ORDER BY id DESC LIMIT 1', array($hotelId, $groupRate, $age, $age));
            if($g) {
                $kids[] = array($age, $g);
            } else {
                $a++;                                           // возраст вне групп — взрослый
            }
        }
        // места и лимиты номера
        $baseOcc = max(1, (int)$room['base_occupancy']);
        $maxAdults = (int)$room['max_adults'] > 0 ? (int)$room['max_adults'] : max((int)$room['max_occupancy'], $baseOcc);
        $R = (int)$room['places_regular'] > 0 ? (int)$room['places_regular'] : max($baseOcc, $maxAdults);
        $E = (int)$room['extra_beds'];
        $S = (int)$room['share_slots'];
        $maxOcc = (int)$room['max_occupancy'] > 0 ? (int)$room['max_occupancy'] : $maxAdults + (int)$room['max_children'];
        $perGuest = 2 == $room['pricing_model'];
        // продаётся ли рум-рейт на g взрослых и множитель (per guest)
        $sellable = function($g) use ($db, $rrId, $maxAdults, $R, $E, $maxOcc, $perGuest, $baseOcc, $extraAdult) {
            if($g < 1 || $g > 8 || $g > $maxAdults || $g > $R + $E || $g > $maxOcc) {
                return false;
            }
            if($perGuest) {
                $occ = $db->fetchRow('SELECT * FROM hotels_rates_occupancy WHERE id_rate_room = ? AND guests = ? ORDER BY id DESC LIMIT 1', array($rrId, $g));
                return $occ ? ($occ['active'] ? 10000 + (int)round($occ['amount'] * 100) : false) : 10000;
            }
            return ($g <= $baseOcc || !is_null($extraAdult)) ? 10000 : false;
        };
        $multA = $sellable($a);
        if(false === $multA) {
            continue;
        }
        $infants = array();
        $placed = array();
        foreach($kids as $kid) {
            if($room['is_without_infants'] && $kid[0] <= REFERENCE_INFANT_AGE_MAX) {
                $infants[] = $kid;
            } else {
                $placed[] = $kid;
            }
        }
        if(count($placed) > (int)$room['max_children'] || $a + count($placed) > $maxOcc) {
            continue;
        }
        $mult1 = $sellable(1);
        $multBase = $sellable($baseOcc);
        $parentRrId = null;
        if($rate['id_parent']) {
            $parentRrId = $db->fetchOne('SELECT id FROM hotels_rates_rooms WHERE id_rate = ? AND id_room = ?', array($rate['id_parent'], $rr['id_room']));
        }
        // цена ночи за g взрослых
        $nightPrice = function($g, $mult, $base, $date) use ($db, $rrId, $perGuest, $baseOcc, $extraAdult) {
            if(!$perGuest) {
                return $base + max(0, $g - $baseOcc) * (int)$extraAdult;
            }
            $dayPrice = $db->fetchOne('SELECT price FROM hotels_rates_occupancy_daily WHERE id_rate_room = ? AND guests = ? AND date = ?
                ORDER BY id DESC LIMIT 1', array($rrId, $g, $date));
            if(!is_null($dayPrice) && false !== $dayPrice && $dayPrice > 0) {
                $GLOBALS['referenceDailyHits'] = (isset($GLOBALS['referenceDailyHits']) ? $GLOBALS['referenceDailyHits'] : 0) + 1;
                return (int)round($dayPrice * 100);
            }
            return 10000 == $mult ? $base : max(0, (int)floor((2 * $base * $mult + 10000) / 20000));
        };
        $ok = true;
        $nightly = array();
        $roomsLeft = PHP_INT_MAX;
        $kidBed = array();     // индекс ребёнка => сумма за проживание на существующей кровати (null = нельзя)
        $kidExtra = array();
        foreach($kids as $n => $kid) {
            $kidBed[$n] = 0;
            $kidExtra[$n] = 0;
        }
        for($i = 0; $i < $nights && $ok; $i++) {
            $own = $price($rrId, $days[$i]);
            $base = null;
            if(!$rate['id_parent']) {
                if($own && $own['active'] && !is_null($own['price'])) {
                    $base = (int)round($own['price'] * 100);
                }
            } elseif($own && !$own['active']) {
                $base = null;
            } elseif($own && !is_null($own['price']) && 0 == $own['derive_type']) {
                $base = (int)round($own['price'] * 100);
            } elseif($parentRrId && ($par = $price($parentRrId, $days[$i])) && $par['active'] && !is_null($par['price'])) {
                $dv = ($own && 4 == $own['derive_type']) ? (int)$own['derive_value'] : -(int)$rate['derive_value'];
                $x = (int)round($par['price'] * 100) * (100 + $dv);
                $base = (int)floor((2 * $x + 100) / 200);            // half up
            }
            $av = $db->fetchRow('SELECT * FROM hotels_rooms_availability WHERE id_room = ? AND date = ? ORDER BY id DESC LIMIT 1', array($rr['id_room'], $days[$i]));
            $allot = ($av && !is_null($av['allotment'])) ? (int)$av['allotment'] : (int)$room['allotment'];
            $free = $allot - ($av ? (int)$av['net_booked'] : 0);
            if(($av && !$av['active']) || $free <= 0 || is_null($base) || $base <= 0) {
                $ok = false;
                break;
            }
            $roomsLeft = min($roomsLeft, $free);
            $nightly[] = $nightPrice($a, $multA, $base, $days[$i]);
            if(empty($kids)) {
                continue;
            }
            // одноместная цена ночи: за 1 взрослого, иначе за base_occupancy, иначе база
            if(false !== $mult1) {
                $single = $nightPrice(1, $mult1, $base, $days[$i]);
            } elseif(false !== $multBase) {
                $single = $nightPrice($baseOcc, $multBase, $base, $days[$i]);
            } else {
                $single = $base;
            }
            foreach($kids as $n => $kid) {
                $g = $kid[1];
                $o = $db->fetchRow('SELECT * FROM hotels_children_prices WHERE active = 1 AND id_group = ? AND id_hotel = ? AND (id_rate = 0 OR id_rate = ?)
                    AND (date_from IS NULL OR date_from <= ?) AND (date_to IS NULL OR date_to >= ?)
                    ORDER BY (id_rate = ?) DESC, (date_from IS NOT NULL OR date_to IS NOT NULL) DESC, id DESC LIMIT 1',
                    array($g['id'], $g['id_hotel'], $rate['id'], $days[$i], $days[$i], $rate['id']));
                $rule = $o ? $o : $g;
                $cost = function($type, $value) use ($single) {
                    $v = (int)round($value * 100);
                    switch((int)$type) {
                        case 1: return 0;
                        case 2: return max(0, $v);
                        case 3: return (int)floor((2 * $single * max(0, $v) + 10000) / 20000);
                        default: return null;
                    }
                };
                $b = $cost($rule['bed_type'], $rule['bed_value']);
                $e = $cost($rule['extra_type'], $rule['extra_value']);
                $kidBed[$n] = (is_null($kidBed[$n]) || is_null($b)) ? null : $kidBed[$n] + $b;
                $kidExtra[$n] = (is_null($kidExtra[$n]) || is_null($e)) ? null : $kidExtra[$n] + $e;
            }
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
        // дети: инфанты (is_without_infants) — место не занимают; остальные — полный перебор мест
        $kidsCost = 0;
        foreach($kids as $n => $kid) {
            if($room['is_without_infants'] && $kid[0] <= REFERENCE_INFANT_AGE_MAX) {
                $allowed = array_filter(array($kidBed[$n], $kidExtra[$n]), function($v) {
                    return !is_null($v);
                });
                if(empty($allowed)) {
                    continue 2;
                }
                $c = min($allowed);                             // инфант: меньшая из разрешённых цен
                $kidsCost += $c;
            }
        }
        $placedIdx = array();
        foreach($kids as $n => $kid) {
            if(!($room['is_without_infants'] && $kid[0] <= REFERENCE_INFANT_AGE_MAX)) {
                $placedIdx[] = $n;
            }
        }
        $bedSlots = max(0, $R - $a) + $S;
        $extraSlots = $E - max(0, $a - $R);
        $bestPlacement = null;
        for($mask = 0; $mask < (1 << count($placedIdx)); $mask++) {
            $sum = 0;
            $beds = 0;
            $extras = 0;
            foreach($placedIdx as $bit => $n) {
                $onBed = ($mask >> $bit) & 1;
                $c = $onBed ? $kidBed[$n] : $kidExtra[$n];
                if(is_null($c)) {
                    continue 2;
                }
                $sum += $c;
                $beds += $onBed;
                $extras += 1 - $onBed;
            }
            if($beds <= $bedSlots && $extras <= $extraSlots && (is_null($bestPlacement) || $sum < $bestPlacement)) {
                $bestPlacement = $sum;
            }
        }
        if(is_null($bestPlacement)) {
            continue;
        }
        $result[$rrId] = array('total' => array_sum($nightly) + $kidsCost + $bestPlacement, 'nightly' => $nightly,
            'id_room' => (int)$rr['id_room'], 'rooms_left' => $roomsLeft);
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
