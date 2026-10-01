<?php

/**
 * Состав номера и цена номера за проживание (взрослые, дети, младенцы), плюс выбор самой дешёвой комбинации,
 * когда в запросе несколько номеров.
 *
 * Одна и та же логика используется в карточке отеля (Search_Model_Stay::hotelRooms), при перерасчёте брони
 * и повторена в SQL поиска (Search_Model_Stay::search). Правила — README, раздел «Вместимость, дети и несколько номеров».
 *
 * Все суммы — в копейках валюты отеля, за всё проживание.
 */
class Search_Occupancy {
    const INFANT_AGE_MAX = 2;               // младенец: 0-2 полных года (0-2,99)
    const MAX_CHILD_AGE = 17;
    const MAX_ADULTS = 8;                   // гостей в цене номера (колонки c1..c8) и взрослых в номере запроса
    const MAX_CHILDREN = 6;                 // детей в одном номере запроса
    const MAX_ROOMS = 4;                    // номеров в одном запросе (колонки k2..k4)
    const NO_LIMIT = 255;                   // max_infants без лимита; children_min_age = 255 — отель не принимает детей

    // типы детской цены (hotels_children_groups.price_type)
    const CHILD_FREE = 1;
    const CHILD_FIXED = 2;                  // фикс за ночь
    const CHILD_ROOM_PERCENT = 3;           // % тарифа на номер
    const CHILD_ADULT_PERCENT = 4;          // % цены взрослого: цена номера / число гостей × %
    const CHILD_ADULT_FULL = 5;             // полная цена взрослого: цена номера / число гостей

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
     * Цена одного номера за проживание или null, если состав в номер не подходит.
     *
     * Правила:
     *  - возраст вне групп детской политики — взрослый (и для цены, и для вместимости); ребёнок младше children_min_age
     *    или отель без детей (children_min_age = 255) — номер не подходит;
     *  - младенец (0-2 в группе) не больше max_infants; при infants_excluded он не считается в max_children / max_occupancy
     *    и не занимает место; остальные дети занимают место: детей <= max_children, взрослые + дети <= max_occupancy;
     *  - цена номера берётся за g = число взрослых гостей; если на столько не продаётся — за ближайшее большее продаваемое g,
     *    свободные места (g − взрослые) бесплатно занимают самые дорогие дети с местом; не хватает детей — номер не подходит;
     *  - ребёнок платит по типу цены своей группы от цены номера за g гостей за проживание (округление half up один раз):
     *    бесплатно / фикс × ночей / цена × % / цена / g × % / цена / g;
     *  - итог не дороже, чем если бы платящие дети были взрослыми: min(итог, цена номера за g + платящих), если на столько продаётся.
     *
     * @param array $rr рум-рейт на выбранные даты:
     *   gmask             бит g = продаётся на g гостей (в нём уже max_adults, max_occupancy, выключенные строки окна)
     *   max_children, max_occupancy, max_infants (255 = без лимита), infants_excluded (hotels_rooms.is_without_infants)
     *   children_min_age  255 = отель не принимает детей
     *   age_group         array(возраст 0..17 => номер группы j, 0 = взрослый)
     *   child_type, child_value  array(j => тип цены группы / значение: фикс — копейки за ночь, процент — базисные пункты)
     *   adults            array(g => цена номера за g гостей за проживание)
     *   nights            ночей
     * @param int $adults взрослых в запросе
     * @param array $ages возраста детей
     * @return array|null array('total', 'adults' (с детьми, считающимися взрослыми), 'guests' (за сколько гостей взята цена номера),
     *                          'price_adults' (цена номера), 'price_children', 'capped' (дети посчитаны как взрослые),
     *                          'children' => array(array('age', 'group', 'place' => bed|free|none|adult, 'price', 'as_adult')))
     */
    public static function price(array $rr, $adults, array $ages) {
        $a = (int)$adults;
        $children = array();      // по порядку запроса
        $kids = array();          // индексы детей из групп
        foreach(array_values($ages) as $n => $age) {
            if($age < $rr['children_min_age']) {
                return null;                                   // ребёнок младше минимального возраста / дети не принимаются
            }
            $j = isset($rr['age_group'][$age]) ? (int)$rr['age_group'][$age] : 0;
            if(0 == $j) {
                $a++;                                          // возраст вне групп — взрослый
                $children[$n] = array('age' => $age, 'group' => 0, 'place' => 'adult', 'price' => 0, 'as_adult' => false);
                continue;
            }
            $infant = $age <= self::INFANT_AGE_MAX;
            $children[$n] = array('age' => $age, 'group' => $j, 'place' => ($infant && $rr['infants_excluded']) ? 'none' : 'bed',
                'price' => 0, 'as_adult' => false, 'infant' => $infant);
            $kids[] = $n;
        }
        if($a < 1 || $a > self::MAX_ADULTS) {
            return null;
        }
        $placed = 0;
        $infants = 0;
        foreach($kids as $n) {
            $placed += 'bed' == $children[$n]['place'] ? 1 : 0;
            $infants += $children[$n]['infant'] ? 1 : 0;
        }
        if($placed > $rr['max_children'] || $a + $placed > $rr['max_occupancy']
            || (self::NO_LIMIT != $rr['max_infants'] && $infants > $rr['max_infants'])) {
            return null;
        }
        // за сколько гостей берётся цена номера: взрослые, иначе ближайшее большее продаваемое число (места займут дети)
        $g = 0;
        for($x = $a; $x <= min(self::MAX_ADULTS, $a + $placed) && !$g; $x++) {
            $g = (($rr['gmask'] >> $x) & 1) ? $x : 0;
        }
        if(!$g) {
            return null;
        }
        $roomPrice = (int)$rr['adults'][$g];
        $bed = array();
        foreach($kids as $n) {
            $j = $children[$n]['group'];
            $children[$n]['price'] = self::childPrice($rr['child_type'][$j], $rr['child_value'][$j], $roomPrice, $g, $rr['nights']);
            if('bed' == $children[$n]['place']) {
                $bed[] = $n;
            }
        }
        // свободные места цены номера бесплатно занимают самые дорогие дети
        usort($bed, function($x, $y) use ($children) {
            $px = $children[$x]['price'];
            $py = $children[$y]['price'];
            return $px == $py ? $x - $y : ($px > $py ? -1 : 1);
        });
        foreach(array_slice($bed, 0, $g - $a) as $n) {
            $children[$n]['place'] = 'free';
            $children[$n]['price'] = 0;
        }
        $total = $roomPrice;
        $paying = 0;
        foreach($children as $c) {
            $total += $c['price'];
            $paying += $c['price'] > 0 ? 1 : 0;
        }
        // не дороже, чем если бы платящие дети были взрослыми
        $guests = $g;
        $capped = $paying > 0 && $g + $paying <= self::MAX_ADULTS && (($rr['gmask'] >> ($g + $paying)) & 1)
            && $rr['adults'][$g + $paying] < $total;
        if($capped) {
            $guests = $g + $paying;
            $total = (int)$rr['adults'][$guests];
            foreach($children as $n => $c) {
                if($c['price'] > 0) {
                    $children[$n]['price'] = 0;
                    $children[$n]['as_adult'] = true;
                }
            }
        }
        foreach($children as $n => $c) {
            unset($children[$n]['infant']);
        }
        $priceAdults = $capped ? $total : $roomPrice;
        return array(
            'total'          => $total,
            'adults'         => $a,
            'guests'         => $guests,
            'price_adults'   => $priceAdults,
            'price_children' => $total - $priceAdults,
            'capped'         => $capped,
            'children'       => array_values($children),
        );
    }

    /**
     * Цена ребёнка за проживание по типу цены его группы, half up.
     * @param int $roomPrice цена номера за $guests гостей за проживание
     * @param int $value фикс — копейки за ночь, процент — базисные пункты (50% = 5000)
     */
    public static function childPrice($type, $value, $roomPrice, $guests, $nights) {
        switch((int)$type) {
            case self::CHILD_FIXED:
                return $value * $nights;
            case self::CHILD_ROOM_PERCENT:
                return self::_roundDiv($roomPrice * $value, 10000);
            case self::CHILD_ADULT_PERCENT:
                return self::_roundDiv($roomPrice * $value, 10000 * $guests);
            case self::CHILD_ADULT_FULL:
                return self::_roundDiv($roomPrice, $guests);
            default:
                return 0;
        }
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

    protected static function _roundDiv($a, $b) {
        $a += (int)($b / 2);
        return ($a - $a % $b) / $b;
    }
}
