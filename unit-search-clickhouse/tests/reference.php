<?php
/**
 * Эталонный расчёт "в лоб" по ночам прямо из MySQL — независимая от Search_Sync_Builder реализация тех же правил.
 * Используется в verify_reference.php и queue_flow.php.
 */

/**
 * Эталон: доступные рум-рейты отеля на проживание. Возвращает rate_room_id => array('total' => копейки, 'nightly' => array(...)).
 */
function referenceRates($db, $hotelId, $checkin, $nights, $guests, $today, $channel = 1) {
    $days = array();
    for($i = 0; $i <= $nights; $i++) {
        $days[] = date('Y-m-d', strtotime("$checkin 12:00:00 +$i day"));
    }
    $checkout = $days[$nights];
    $adv = (int)round((strtotime("$checkin 12:00:00") - strtotime("$today 12:00:00")) / 86400);
    if(!$db->fetchOne('SELECT active FROM hotels WHERE id = ?', array($hotelId))) {
        return array();
    }
    $rooms = $db->fetchAssoc('SELECT * FROM hotels_rooms WHERE id_hotel = ? AND active = 1', array($hotelId));
    $rates = $db->fetchAssoc('SELECT * FROM hotels_rates WHERE id_hotel = ? AND active = 1', array($hotelId));
    if(!$rooms || !$rates) {
        return array();
    }
    $rrs = $db->fetchAssoc('SELECT * FROM hotels_rates_rooms WHERE id_room IN (' . implode(',', array_keys($rooms)) . ')
        AND id_rate IN (' . implode(',', array_keys($rates)) . ')');
    $price = function($rrId, $date) use ($db) {
        return $db->fetchRow('SELECT * FROM hotels_rates_prices WHERE id_rate_room = ? AND date = ?', array($rrId, $date));
    };
    $result = array();
    foreach($rrs as $rrId => $rr) {
        $room = $rooms[$rr['id_room']];
        $rate = $rates[$rr['id_rate']];
        if(!((int)$rate['channel_mask'] & $channel) || 'public' != $rate['visibility'] || $rate['id_hotel'] != $room['id_hotel']) {
            continue;
        }
        // guests allowed / multiplier
        $maxGuests = min(8, max((int)$room['max_occupancy'], (int)$room['base_occupancy'], 1));
        if($guests > $maxGuests) {
            continue;
        }
        $mult = 10000;
        if(2 == $room['pricing_model']) {
            $occ = $db->fetchRow('SELECT * FROM hotels_rates_occupancy WHERE id_rate_room = ? AND guests = ?', array($rrId, $guests));
            if($occ) {
                if(!$occ['active']) {
                    continue;
                }
                $mult = 10000 + (int)round($occ['amount'] * 100);
            }
        }
        $parentRrId = null;
        if($rate['id_parent']) {
            $parentRrId = $db->fetchOne('SELECT id FROM hotels_rates_rooms WHERE id_rate = ? AND id_room = ?', array($rate['id_parent'], $rr['id_room']));
        }
        $ok = true;
        $nightly = array();
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
            $av = $db->fetchRow('SELECT * FROM hotels_rooms_availability WHERE id_room = ? AND date = ?', array($rr['id_room'], $days[$i]));
            $allot = ($av && !is_null($av['allotment'])) ? (int)$av['allotment'] : (int)$room['allotment'];
            $free = $allot - ($av ? (int)$av['net_booked'] : 0);
            if(($av && !$av['active']) || $free <= 0 || is_null($base) || $base <= 0) {
                $ok = false;
                break;
            }
            $nightly[] = 10000 == $mult ? $base : (int)floor((2 * $base * $mult + 10000) / 20000);
        }
        if(!$ok) {
            continue;
        }
        // restrictions: arrival day and departure day (own price row, rate defaults otherwise)
        $a = $price($rrId, $checkin);
        $minLos = ($a && !is_null($a['min_los'])) ? (int)$a['min_los'] : (int)$rate['min_los'];
        $maxLos = ($a && $a['max_los'] > 0) ? (int)$a['max_los'] : 999;
        $minAdv = ($a && !is_null($a['min_adv'])) ? (int)$a['min_adv'] : (int)$rate['min_adv'];
        $maxAdv = ($a && $a['max_adv'] > 0) ? (int)$a['max_adv'] : 9999;
        if(($a && $a['cta']) || max(1, $minLos) > $nights || $maxLos < $nights || max(0, $minAdv) > $adv || $maxAdv < $adv) {
            continue;
        }
        $dep = $price($rrId, $checkout);
        if($dep && $dep['ctd']) {
            continue;
        }
        $result[$rrId] = array('total' => array_sum($nightly), 'nightly' => $nightly);
    }
    ksort($result);
    return $result;
}
