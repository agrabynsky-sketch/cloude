<?php

/**
 * Размещение гостей в номере и цена номера за проживание (взрослые, дети, доп. кровати), плюс выбор
 * самой дешёвой комбинации, когда в запросе несколько номеров.
 *
 * Одна и та же логика используется в карточке отеля (Search_Model_Stay::hotelRooms), при перерасчёте брони
 * и повторена в SQL поиска (Search_Model_Stay::search). Правила — README, раздел «Дети, доп. кровати и несколько номеров».
 *
 * Все суммы — в копейках валюты отеля, за всё проживание.
 */
class Search_Occupancy {
    const INFANT_AGE_MAX = 2;               // инфант: 0-2 полных года (0-2,99)
    const MAX_CHILD_AGE = 17;
    const MAX_ADULTS = 8;                   // колонки c1..c8
    const MAX_CHILDREN = 6;                 // детей в одном номере запроса
    const MAX_ROOMS = 4;                    // номеров в одном запросе (колонки k2..k4)
    const NOT_ALLOWED = 1099511627776;      // 2^40: сумма детской цены >= 2^40 = размещение запрещено хотя бы в одну ночь

    /**
     * Составы номеров из параметров запроса.
     *   'rooms'    => array(array('adults' => 2, 'children' => array(7, 4)), array('adults' => 2)) или JSON этой структуры;
     *   'adults'   => 2, 'children' => array(7, 4) или '7,4' — один номер;
     *   'guests'   => 2 — как раньше: взрослые без детей.
     * @return array список array('adults' => int, 'children' => array(int, ...))
     * @throws Search_ClickHouse_Exception 400
     */
    public static function rooms(array $criteria) {
        if(isset($criteria['rooms']) && '' !== $criteria['rooms'] && array() !== $criteria['rooms']) {
            $rooms = $criteria['rooms'];
            if(is_string($rooms)) {
                $rooms = json_decode($rooms, true);
            }
            if(!is_array($rooms) || empty($rooms)) {
                throw new Search_ClickHouse_Exception('rooms must be a non-empty list of {adults, children}', 400);
            }
        } elseif(isset($criteria['adults'])) {
            $rooms = array(array('adults' => $criteria['adults'], 'children' => isset($criteria['children']) ? $criteria['children'] : array()));
        } else {
            $rooms = array(array('adults' => isset($criteria['guests']) ? $criteria['guests'] : 2, 'children' => array()));
        }
        if(count($rooms) > self::MAX_ROOMS) {
            throw new Search_ClickHouse_Exception('rooms: at most ' . self::MAX_ROOMS . ' rooms per request', 400);
        }
        $result = array();
        foreach(array_values($rooms) as $room) {
            if(!is_array($room) || !isset($room['adults'])) {
                throw new Search_ClickHouse_Exception('rooms: every room needs adults', 400);
            }
            $adults = (int)$room['adults'];
            $children = isset($room['children']) ? $room['children'] : array();
            if(is_string($children)) {
                $children = '' === trim($children) ? array() : explode(',', $children);
            }
            $ages = array();
            foreach((array)$children as $age) {
                if(!is_numeric($age) || (int)$age < 0 || (int)$age > self::MAX_CHILD_AGE) {
                    throw new Search_ClickHouse_Exception('children: ages must be 0..' . self::MAX_CHILD_AGE, 400);
                }
                $ages[] = (int)$age;
            }
            if($adults < 1 || $adults > self::MAX_ADULTS) {
                throw new Search_ClickHouse_Exception('adults must be 1..' . self::MAX_ADULTS, 400);
            }
            if(count($ages) > self::MAX_CHILDREN) {
                throw new Search_ClickHouse_Exception('at most ' . self::MAX_CHILDREN . ' children per room', 400);
            }
            $result[] = array('adults' => $adults, 'children' => $ages);
        }
        return $result;
    }

    /**
     * Цена одного номера за проживание или null, если состав в номер не размещается.
     *
     * @param array $rr рум-рейт на выбранные даты:
     *   gmask             бит g = продаётся на g взрослых (в нём уже max_adults, R + E, max_occupancy, цена за g)
     *   places_regular    R — основные места;  extra_beds — E;  share_slots — S (детей в кроватях со взрослыми)
     *   max_children, max_occupancy, infants_excluded (hotels_rooms.is_without_infants)
     *   children_min_age  255 = отель не принимает детей
     *   age_group         array(возраст 0..17 => номер группы j, 0 = взрослый)
     *   adults            array(g => цена за g взрослых за проживание)
     *   bed, extra        array(j => цена ребёнка группы j за проживание на существующей / доп. кровати; >= NOT_ALLOWED = нельзя)
     * @param int $adults взрослых в запросе
     * @param array $ages возраста детей
     * @return array|null array('total', 'adults' (с учётом детей, считающихся взрослыми), 'price_adults', 'price_children',
     *                          'extra_beds_adults', 'children' => array(array('age', 'group', 'place' => bed|extra|none, 'price')))
     */
    public static function price(array $rr, $adults, array $ages) {
        $a = (int)$adults;
        $children = array();      // возраст => план размещения, по порядку запроса
        $placed = array();        // индексы детей, которым нужно место
        $fixed = 0;
        foreach(array_values($ages) as $n => $age) {
            if($age < $rr['children_min_age']) {
                return null;                                   // ребёнок младше минимального возраста / дети не принимаются
            }
            $j = isset($rr['age_group'][$age]) ? (int)$rr['age_group'][$age] : 0;
            if(0 == $j) {
                $a++;                                          // возраст вне групп — взрослый
                $children[$n] = array('age' => $age, 'group' => 0, 'place' => 'adult', 'price' => 0);
                continue;
            }
            $bed = self::_allowed($rr['bed'][$j]);
            $extra = self::_allowed($rr['extra'][$j]);
            $children[$n] = array('age' => $age, 'group' => $j, 'place' => null, 'price' => 0, 'bed' => $bed, 'extra' => $extra);
            if($rr['infants_excluded'] && $age <= self::INFANT_AGE_MAX) {
                // инфант: место и лимиты не занимает, платит меньшую из разрешённых цен своей группы (обычно 0)
                if(is_null($bed) && is_null($extra)) {
                    return null;
                }
                $children[$n]['place'] = 'none';
                $children[$n]['price'] = is_null($bed) ? $extra : (is_null($extra) ? $bed : min($bed, $extra));
                continue;
            }
            $placed[] = $n;
        }
        if($a < 1 || $a > self::MAX_ADULTS || !(($rr['gmask'] >> $a) & 1)) {
            return null;                                       // здесь же max_adults, A <= R + E, per room без цены доп. взрослого
        }
        if(count($placed) > $rr['max_children'] || $a + count($placed) > $rr['max_occupancy']) {
            return null;
        }
        $bedLeft = max(0, $rr['places_regular'] - $a) + $rr['share_slots'];    // свободные основные места + места рядом со взрослыми
        $extraLeft = $rr['extra_beds'] - max(0, $a - $rr['places_regular']);   // доп. кровати, не занятые взрослыми
        $flex = array();
        foreach($placed as $n) {
            $c = $children[$n];
            if(is_null($c['bed']) && is_null($c['extra'])) {
                return null;
            }
            if(is_null($c['extra'])) {                          // только существующая кровать
                $bedLeft--;
                $children[$n]['place'] = 'bed';
                $children[$n]['price'] = $c['bed'];
            } elseif(is_null($c['bed'])) {                      // только доп. кровать
                $extraLeft--;
                $children[$n]['place'] = 'extra';
                $children[$n]['price'] = $c['extra'];
            } else {
                $flex[] = $n;
            }
        }
        if($bedLeft < 0 || $extraLeft < 0) {
            return null;
        }
        // остальные дети: на существующие кровати — те, кому это выгоднее всего (доп. кровать − существующая, по убыванию);
        // их число m между lo (сколько не влезет на доп. кровати) и hi (сколько влезет на существующие) — это минимум стоимости
        usort($flex, function($x, $y) use ($children) {
            $gx = $children[$x]['extra'] - $children[$x]['bed'];
            $gy = $children[$y]['extra'] - $children[$y]['bed'];
            return $gx == $gy ? $x - $y : ($gx > $gy ? -1 : 1);
        });
        $lo = max(0, count($flex) - $extraLeft);
        $hi = min(count($flex), $bedLeft);
        if($lo > $hi) {
            return null;
        }
        $m = 0;
        foreach($flex as $n) {
            $m += $children[$n]['extra'] > $children[$n]['bed'] ? 1 : 0;
        }
        $m = min(max($m, $lo), $hi);
        foreach($flex as $i => $n) {
            $children[$n]['place'] = $i < $m ? 'bed' : 'extra';
            $children[$n]['price'] = $i < $m ? $children[$n]['bed'] : $children[$n]['extra'];
        }
        $priceChildren = 0;
        foreach($children as $n => $c) {
            unset($children[$n]['bed'], $children[$n]['extra']);
            $priceChildren += $c['price'];
        }
        $priceAdults = (int)$rr['adults'][$a];
        return array(
            'total'             => $priceAdults + $priceChildren,
            'adults'            => $a,
            'price_adults'      => $priceAdults,
            'price_children'    => $priceChildren,
            'extra_beds_adults' => max(0, $a - $rr['places_regular']),
            'children'          => array_values($children),
        );
    }

    /**
     * Самая дешёвая комбинация, когда в запросе несколько номеров.
     * Наличие принадлежит типу номера (id_room), его делят все тарифы этого номера.
     *
     * @param array $options i => список вариантов для номера i запроса: array('price' => int, 'id_room' => int, 'rooms_left' => int, ...)
     * @param bool $prepared варианты уже по одному на тип номера, по возрастанию цены, не больше K на номер (так их отдаёт поиск)
     * @return array|null array('total' => int, 'picks' => array(i => вариант))
     */
    public static function cheapestCombination(array $options, $prepared = false) {
        $k = count($options);
        $cands = array();
        foreach(array_values($options) as $i => $list) {
            if($prepared) {
                if(empty($list)) {
                    return null;
                }
                $cands[$i] = $list;
                continue;
            }
            // лучший вариант по каждому типу номера, затем K самых дешёвых типов: остальные K-1 номеров займут
            // не больше K-1 типов, поэтому среди K лучших для номера i всегда останется свободный
            $best = array();
            foreach($list as $o) {
                if(!isset($best[$o['id_room']]) || $o['price'] < $best[$o['id_room']]['price']) {
                    $best[$o['id_room']] = $o;
                }
            }
            if(empty($best)) {
                return null;
            }
            usort($best, function($x, $y) {
                return $x['price'] == $y['price'] ? $x['id_room'] - $y['id_room'] : ($x['price'] < $y['price'] ? -1 : 1);
            });
            $cands[$i] = array_slice($best, 0, $k);
        }
        // обычно самые дешёвые варианты номеров не упираются в наличие — тогда перебор не нужен
        $picks = array();
        $used = array();
        $sum = 0;
        $fits = true;
        foreach($cands as $i => $list) {
            $picks[$i] = $list[0];
            $sum += $list[0]['price'];
            $used[$list[0]['id_room']] = isset($used[$list[0]['id_room']]) ? $used[$list[0]['id_room']] + 1 : 1;
            $fits = $fits && $used[$list[0]['id_room']] <= $list[0]['rooms_left'];
        }
        if($fits) {
            return array('total' => $sum, 'picks' => $picks);
        }
        $result = null;
        self::_combine($cands, 0, array(), array(), 0, $result);
        return $result;
    }

    protected static function _combine(array $cands, $i, array $picks, array $used, $sum, &$result) {
        if($i == count($cands)) {
            if(is_null($result) || $sum < $result['total']) {
                $result = array('total' => $sum, 'picks' => $picks);
            }
            return;
        }
        foreach($cands[$i] as $o) {
            $n = isset($used[$o['id_room']]) ? $used[$o['id_room']] : 0;
            if($n >= $o['rooms_left']) {
                continue;
            }
            $used[$o['id_room']] = $n + 1;
            $picks[$i] = $o;
            self::_combine($cands, $i + 1, $picks, $used, $sum + $o['price'], $result);
            $used[$o['id_room']] = $n;
        }
    }

    protected static function _allowed($value) {
        return (is_null($value) || $value >= self::NOT_ALLOWED) ? null : (int)$value;
    }
}
