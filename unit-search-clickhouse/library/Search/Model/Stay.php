<?php

/**
 * Поиск по кэшу unit_search.hotels_search_stay.
 *
 * Состав гостей — по номерам: взрослые + возраста детей. Цена номера — за число взрослых гостей (окно цен по числу гостей,
 * нет настроек — одна цена за номер), дети — по детской политике отеля от цены номера, не дороже, чем взрослые.
 * Вместимость — только существующие места; доп. кровати и детские кроватки в расчёте не участвуют (условия проживания).
 * Правила — README, раздел «Вместимость, дети и несколько номеров».
 *
 * 1) Минимальная цена по отелям (выдача 500-1000 отелей):
 *
 *   $result = Search_Model_Stay::getInstance()->search(array(
 *       'checkin'   => '2026-12-10',
 *       'checkout'  => '2026-12-17',       // или 'nights' => 7
 *       'rooms'     => array(              // до 4 номеров; или 'adults' => 2, 'children' => array(7, 4) для одного номера;
 *           array('adults' => 2, 'children' => array(7, 4)),        //   или 'guests' => 2 (как раньше: взрослые без детей)
 *           array('adults' => 2),
 *       ),
 *       'id_hotel'  => $ids,               // и/или 'id_region' / 'id_country' / 'id_city' (число или массив)
 *       'channel'   => 1,                  // бит канала продаж (hotels_sales_channels.bit), по умолчанию 1 = B2C
 *       'stars'     => array(4, 5),        // необязательные фильтры
 *       'id_board_type' => array(4, 7),    // hotels_board_types.id
 *       'refundable'=> 1,
 *       'price_min' => 5000, 'price_max' => 20000,   // за всё проживание всех номеров, в валюте отеля
 *       'order'     => 'price',            // price | -price | stars | -stars
 *       'limit'     => 30, 'offset' => 0,
 *   ));
 *   // array('total' => 812, 'items' => array(array('id_hotel' => .., 'price' => 12345.5, 'price_minor' => 1234550, 'id_currency' => ..,
 *   //        'stars' => .., 'nights' => 7, 'rooms' => array(array('price', 'price_minor', 'id_rate_room', 'id_room', 'id_rate',
 *   //        'id_board_type', 'id_cancel_policy', 'refundable'), ...)), ...))
 *   // Для одного номера поля первого номера (id_rate_room, id_room, id_rate, ...) продублированы в самом элементе, как раньше.
 *
 * 2) Карточка отеля — все доступные рум-рейты для каждого номера запроса и самая дешёвая комбинация:
 *
 *   $offer = Search_Model_Stay::getInstance()->hotelRooms(1005, array('checkin' => '2026-12-10', 'nights' => 7,
 *       'rooms' => array(array('adults' => 2, 'children' => array(7, 4)), array('adults' => 2))));
 *   // array('rooms' => array(i => array('adults', 'children', 'items' => рум-рейты по возрастанию цены)),
 *   //       'best' => array('price_minor', 'price', 'items' => array(i => рум-рейт)) или null)
 *
 *   $rates = Search_Model_Stay::getInstance()->hotelRates(1005, array('checkin' => '2026-12-10', 'nights' => 7, 'adults' => 2));
 *   // один номер: список рум-рейтов (как hotelRooms()['rooms'][0]['items'])
 *
 * Все цены — в валюте отеля (id_currency). Перед бронированием цену и наличие обязательно перепроверять в MySQL.
 */
class Search_Model_Stay extends Search_Model_Abstract {
    protected $_table = 'hotels_search_stay';

    const MAX_NIGHTS = 365;

    protected $_queryCacheTtl = 0;

    /**
     * Кэш результатов ClickHouse для выдачи (use_query_cache): одинаковый запрос в течение $seconds отдаётся из кэша за единицы мс.
     * Цены в выдаче могут отставать на $seconds — перед бронированием всё равно перепроверка в MySQL. 0 — выключено.
     */
    public function setQueryCacheTtl($seconds) {
        $this->_queryCacheTtl = max(0, (int)$seconds);
        return $this;
    }

    public function search(array $criteria) {
        $c = $this->_criteria($criteria);
        $rooms = $c['rooms'];
        $k = count($rooms);
        $in = $c['in'];
        $out = $c['out'];
        $n = $c['nights'];
        $where = $this->_where($c, 's.d IN (' . $in . ', ' . $out . ')');
        $orders = array('price' => 'price_minor ASC', '-price' => 'price_minor DESC', 'stars' => 'stars ASC, price_minor ASC', '-stars' => 'stars DESC, price_minor ASC');
        $order = isset($criteria['order'], $orders[$criteria['order']]) ? $orders[$criteria['order']] : $orders['price'];
        $limit = isset($criteria['limit']) ? max(1, min(1000, (int)$criteria['limit'])) : 30;
        $offset = isset($criteria['offset']) ? max(0, (int)$criteria['offset']) : 0;
        $filter = array();
        if(isset($criteria['price_min']) && '' !== $criteria['price_min']) {
            $filter[] = 'price_minor >= ' . (int)round($criteria['price_min'] * 100);
        }
        if(isset($criteria['price_max']) && '' !== $criteria['price_max']) {
            $filter[] = 'price_minor <= ' . (int)round($criteria['price_max'] * 100);
        }
        $rows = $this->_roomPricesSql($c, $where);
        $pick = '(total, id_rate_room, id_room, id_rate, id_board_type, id_cancel_policy, refundable)';
        if(1 == $k) {
            // один номер: лучший рум-рейт каждого отеля, фильтр цены, сортировка и страница — в ClickHouse
            $sql = "SELECT id_hotel, any(id_currency) AS id_currency, any(stars) AS stars,
                           argMin($pick, total) AS best,
                           arrayStringConcat([toString(best.1), toString(best.2), toString(best.3), toString(best.4), toString(best.5),
                               toString(best.6), toString(best.7)], ':') AS picks_str,
                           count() OVER () AS total_hotels
                    FROM ($rows)
                    GROUP BY id_hotel
                    " . (empty($filter) ? '' : 'HAVING ' . str_replace('price_minor', 'best.1', implode(' AND ', $filter))) . "
                    ORDER BY " . str_replace('price_minor', 'best.1', $order) . ", id_hotel
                    LIMIT $offset, $limit" . $this->_cacheSettings();
            $rows = $this->_client->fetchAll($sql);
            $total = empty($rows) ? 0 : (int)$rows[0]['total_hotels'];
            $hotels = array();
            foreach($rows as $row) {
                $v = array_map('intval', explode(':', $row['picks_str']));
                $hotels[] = array('id_hotel' => (int)$row['id_hotel'], 'price_minor' => $v[0], 'id_currency' => (int)$row['id_currency'],
                    'stars' => (int)$row['stars'], 'picks' => array($v));
            }
        } else {
            // несколько номеров: ClickHouse отдаёт по каждому отелю лучший рум-рейт каждого типа номера для каждого номера запроса
            // и сколько номеров этого типа свободно (cap, до 4); самую дешёвую комбинацию с учётом свободных номеров, фильтр цены,
            // сортировку и страницу считает PHP (Search_Occupancy::cheapestCombination), отелей в выдаче не больше ~1000
            $sql = "SELECT id_hotel, any(id_currency) AS id_currency, any(stars) AS stars,
                           toString(arraySort(groupArray((i, p.1, id_room, cap, p.2, p.4, p.5, p.6, p.7)))) AS cands
                    FROM (
                        SELECT id_hotel, i, id_room, any(id_currency) AS id_currency, any(stars) AS stars, max(cap) AS cap,
                               argMin($pick, total) AS p
                        FROM ($rows)
                        GROUP BY id_hotel, i, id_room
                    )
                    GROUP BY id_hotel
                    HAVING uniqExact(i) = $k" . $this->_cacheSettings();
            $hotels = array();
            foreach($this->_client->fetchAll($sql) as $row) {
                // лучший рум-рейт каждого типа номера для каждого номера запроса, по возрастанию (i, цена):
                // "[(i,цена,id_room,свободно,id_rate_room,id_rate,id_board_type,id_cancel_policy,refundable),(...)]"
                $options = array();
                foreach(explode('),(', substr($row['cands'], 2, -2)) as $tuple) {
                    $v = explode(',', $tuple);
                    if(!isset($options[$v[0] - 1]) || count($options[$v[0] - 1]) < $k) {     // K самых дешёвых типов номера
                        $options[$v[0] - 1][] = array('price' => (int)$v[1], 'id_room' => (int)$v[2], 'rooms_left' => (int)$v[3],
                            'pick' => array((int)$v[1], (int)$v[4], (int)$v[2], (int)$v[5], (int)$v[6], (int)$v[7], (int)$v[8]));
                    }
                }
                $best = Search_Occupancy::cheapestCombination($options, true);
                if(!$best
                    || (isset($criteria['price_min']) && '' !== $criteria['price_min'] && $best['total'] < (int)round($criteria['price_min'] * 100))
                    || (isset($criteria['price_max']) && '' !== $criteria['price_max'] && $best['total'] > (int)round($criteria['price_max'] * 100))) {
                    continue;
                }
                $picks = array();
                foreach($best['picks'] as $pick) {
                    $picks[] = $pick['pick'];
                }
                $hotels[] = array('id_hotel' => (int)$row['id_hotel'], 'price_minor' => $best['total'], 'id_currency' => (int)$row['id_currency'],
                    'stars' => (int)$row['stars'], 'picks' => $picks);
            }
            $sign = array('price' => array(0, 1), '-price' => array(0, -1), 'stars' => array(1, 1), '-stars' => array(-1, 1));
            $sort = isset($criteria['order'], $sign[$criteria['order']]) ? $sign[$criteria['order']] : $sign['price'];
            usort($hotels, function($x, $y) use ($sort) {
                if($sort[0] && $x['stars'] != $y['stars']) {
                    return $sort[0] * ($x['stars'] < $y['stars'] ? -1 : 1);
                }
                if($x['price_minor'] != $y['price_minor']) {
                    return $sort[1] * ($x['price_minor'] < $y['price_minor'] ? -1 : 1);
                }
                return $x['id_hotel'] - $y['id_hotel'];
            });
            $total = count($hotels);
            $hotels = array_slice($hotels, $offset, $limit);
        }
        $result = array('total' => $total, 'items' => array());
        foreach($hotels as $hotel) {
            $item = array(
                'id_hotel'    => $hotel['id_hotel'],
                'price_minor' => $hotel['price_minor'],
                'price'       => $hotel['price_minor'] / 100,
                'id_currency' => $hotel['id_currency'],
                'stars'       => $hotel['stars'],
                'nights'      => $n,
                'rooms'       => array(),
            );
            // pick: цена, id_rate_room, id_room, id_rate, id_board_type, id_cancel_policy, refundable
            foreach($hotel['picks'] as $i => $v) {
                $item['rooms'][] = array('adults' => $rooms[$i]['adults'], 'children' => $rooms[$i]['children'],
                    'price_minor' => $v[0], 'price' => $v[0] / 100, 'id_rate_room' => $v[1], 'id_room' => $v[2], 'id_rate' => $v[3],
                    'id_board_type' => $v[4], 'id_cancel_policy' => $v[5], 'refundable' => $v[6]);
            }
            if(1 == $k) {
                // совместимость с ответом до поддержки нескольких номеров
                foreach(array('id_rate_room', 'id_room', 'id_rate', 'id_board_type', 'id_cancel_policy', 'refundable') as $f) {
                    $item[$f] = $item['rooms'][0][$f];
                }
            }
            $result['items'][] = $item;
        }
        return $result;
    }

    /**
     * Карточка отеля: для каждого номера запроса — все доступные рум-рейты с ценой и планом размещения,
     * плюс самая дешёвая комбинация с учётом свободных номеров.
     */
    public function hotelRooms($idHotel, array $criteria) {
        $criteria['id_hotel'] = array((int)$idHotel);
        unset($criteria['id_region'], $criteria['id_country'], $criteria['id_city']);
        $c = $this->_criteria($criteria);
        $n = $c['nights'];
        // явный список дат вместо BETWEEN: для каждой даты ClickHouse отсекает гранулы по id_hotel (ORDER BY d, id_hotel)
        $where = $this->_where($c, 's.d IN (' . $this->_dateList(trim($c['in'], "'"), trim($c['out'], "'")) . ')') . "\n AND " . $this->_roomsPrefilter($c);
        // цены номера за g гостей нужны для g от min(adults) до max(adults + дети)
        $gMin = Search_Occupancy::MAX_ADULTS;
        $gMax = 1;
        foreach($c['rooms'] as $room) {
            $gMin = min($gMin, $room['adults']);
            $gMax = max($gMax, min(Search_Occupancy::MAX_ADULTS, $room['adults'] + count($room['children'])));
        }
        $cum = array();
        for($g = $gMin; $g <= $gMax; $g++) {
            $cum[] = "c$g";
        }
        $sql = "SELECT id_rate_room, any(id_room) AS id_room, any(id_rate) AS id_rate, any(id_parent) AS id_parent,
                       any(id_board_type) AS id_board_type, any(id_cancel_policy) AS id_cancel_policy,
                       any(refundable) AS refundable, any(id_room_type) AS id_room_type, any(base_occupancy) AS base_occupancy,
                       any(max_guests) AS max_guests, any(id_currency) AS id_currency, any(gmask) AS gmask,
                       any(max_children) AS max_children, any(max_occupancy) AS max_occupancy, any(max_infants) AS max_infants,
                       any(infants_excluded) AS infants_excluded, any(children_min_age) AS children_min_age,
                       any(children_as_adults) AS children_as_adults, any(extra_beds) AS extra_beds, any(cots) AS cots, any(cots_and_extra_beds) AS cots_and_extra_beds,
                       arrayStringConcat(any(age_group), ',') AS age_group,
                       arrayStringConcat(any(child_type), ',') AS child_type,
                       arrayStringConcat(any(child_value), ',') AS child_value,
                       minIf(avail, d < {$c['out']}) AS rooms_left,
                       arrayStringConcat(arrayMap(x -> arrayStringConcat(x.2, ':'),
                           arraySort(x -> x.1, groupArray((d, [" . implode(', ', $cum) . "])))), ',') AS cum
                FROM {$this->_table} AS s FINAL
                WHERE $where
                GROUP BY id_rate_room
                HAVING count() = " . ($n + 1) . " AND " . $this->_having($c);
        $rateRooms = array();
        foreach($this->_client->fetchAll($sql) as $row) {
            $rr = array();
            foreach(array('id_rate_room', 'id_room', 'id_rate', 'id_parent', 'id_board_type', 'id_cancel_policy', 'refundable', 'id_room_type',
                        'base_occupancy', 'max_guests', 'id_currency', 'rooms_left', 'gmask', 'max_children', 'max_occupancy', 'max_infants',
                        'infants_excluded', 'children_min_age', 'children_as_adults', 'extra_beds', 'cots', 'cots_and_extra_beds') as $f) {
                $rr[$f] = (int)$row[$f];
            }
            $rr['nights'] = $n;
            $rr['age_group'] = '' === $row['age_group'] ? array() : array_map('intval', explode(',', $row['age_group']));
            $rr['child_type'] = $this->_groupList($row['child_type']);
            $rr['child_value'] = $this->_groupList($row['child_value']);
            // нарастающие суммы по датам проживания -> цена номера за g гостей за проживание и по ночам
            $cum = array();
            foreach(explode(',', $row['cum']) as $day) {
                $cum[] = array_map('intval', explode(':', $day));
            }
            $rr['adults'] = array();
            $rr['nightly_adults'] = array();
            for($g = $gMin; $g <= $gMax; $g++) {
                $rr['adults'][$g] = $cum[$n][$g - $gMin] - $cum[0][$g - $gMin];
                for($i = 0; $i < $n; $i++) {
                    $rr['nightly_adults'][$g][] = $cum[$i + 1][$g - $gMin] - $cum[$i][$g - $gMin];
                }
            }
            $rateRooms[] = $rr;
        }
        $result = array('rooms' => array(), 'best' => null);
        $options = array();
        foreach($c['rooms'] as $i => $room) {
            $items = array();
            foreach($rateRooms as $rr) {
                $p = Search_Occupancy::price($rr, $room['adults'], $room['children']);
                if(is_null($p)) {
                    continue;
                }
                $item = array();
                foreach(array('id_rate_room', 'id_room', 'id_rate', 'id_parent', 'id_board_type', 'id_cancel_policy', 'refundable', 'id_room_type',
                            'base_occupancy', 'max_guests', 'id_currency', 'rooms_left') as $f) {
                    $item[$f] = $rr[$f];
                }
                $item['price_minor'] = $p['total'];
                $item['price'] = $p['total'] / 100;
                $item['price_adults'] = $p['price_adults'] / 100;         // цена номера за guests гостей
                $item['price_children'] = $p['price_children'] / 100;
                $item['adults'] = $p['adults'];                           // с детьми, которые по возрасту считаются взрослыми
                $item['guests'] = $p['guests'];                           // за сколько гостей взята цена номера
                $item['capped'] = $p['capped'];                           // дети посчитаны как взрослые (так дешевле)
                $item['children'] = array();
                foreach($p['children'] as $child) {
                    $child['price'] = $child['price'] / 100;
                    $item['children'][] = $child;
                }
                $item['nightly'] = array();                               // цена номера по ночам
                foreach($rr['nightly_adults'][$p['guests']] as $v) {
                    $item['nightly'][] = $v / 100;
                }
                // условия проживания: доп. кровати и детские кроватки (в цене и вместимости не участвуют)
                $item['extra_beds'] = $rr['extra_beds'];
                $item['cots'] = $rr['cots'];
                $item['cots_and_extra_beds'] = $rr['cots_and_extra_beds'];
                $items[] = $item;
            }
            usort($items, function($x, $y) {
                return $x['price_minor'] == $y['price_minor'] ? $x['id_rate_room'] - $y['id_rate_room'] : ($x['price_minor'] < $y['price_minor'] ? -1 : 1);
            });
            $result['rooms'][$i] = array('adults' => $room['adults'], 'children' => $room['children'], 'items' => $items);
            $options[$i] = array();
            foreach($items as $item) {
                $options[$i][] = array('price' => $item['price_minor'], 'id_room' => $item['id_room'], 'rooms_left' => $item['rooms_left'], 'item' => $item);
            }
        }
        $best = Search_Occupancy::cheapestCombination($options);
        if($best) {
            $result['best'] = array('price_minor' => $best['total'], 'price' => $best['total'] / 100, 'items' => array());
            foreach($best['picks'] as $i => $pick) {
                $result['best']['items'][$i] = $pick['item'];
            }
        }
        return $result;
    }

    /**
     * Один номер: все доступные рум-рейты отеля (по возрастанию цены).
     */
    public function hotelRates($idHotel, array $criteria) {
        $offer = $this->hotelRooms($idHotel, $criteria);
        if(count($offer['rooms']) > 1) {
            throw new Search_ClickHouse_Exception('hotelRates() is for one room, use hotelRooms()', 400);
        }
        return $offer['rooms'][0]['items'];
    }

    /**
     * Строки «рум-рейт × номер запроса i» только с допустимыми вариантами:
     * id_hotel, id_rate_room, i, id_room, id_rate, id_board_type, id_cancel_policy, refundable, id_currency, stars, cap, total.
     * cap — сколько номеров этого типа свободно на все ночи (до 4, для нескольких номеров в запросе).
     *
     * Запрос намеренно плоский и одинаковый по размеру при любом числе детей: ClickHouse тратит заметное время на анализ
     * каждого вложенного уровня и каждого выражения, поэтому дети запроса — это строки (ARRAY JOIN списка детей),
     * а не выражения SQL. Уровни: 1) группировка двух строк рум-рейта (заезд, выезд) — разности нарастающих сумм;
     * 2) ARRAY JOIN детей и группировка по (рум-рейт, номер запроса) — состав, цена детей и итоговая цена.
     * Логика та же, что в Search_Occupancy::price().
     */
    protected function _roomPricesSql(array $c, $where) {
        $in = $c['in'];
        $out = $c['out'];
        $n = $c['nights'];
        $k = count($c['rooms']);
        $adults = array();
        $kids = array();
        $hasKids = false;
        foreach($c['rooms'] as $i => $room) {
            $adults[] = $room['adults'];
            foreach($room['children'] as $age) {
                $kids[] = '(' . ($i + 1) . ", $age)";
                $hasKids = true;
            }
            if(empty($room['children'])) {
                $kids[] = '(' . ($i + 1) . ', 255)';                   // номер без детей: строка-заглушка
            }
        }
        // цена номера за g гостей нужна для g от min(adults) до max(adults + дети)
        $gMin = min($adults);
        $gMax = $gMin;
        foreach($c['rooms'] as $room) {
            $gMax = max($gMax, min(Search_Occupancy::MAX_ADULTS, $room['adults'] + count($room['children'])));
        }
        $cols = array();
        for($g = $gMin; $g <= $gMax; $g++) {
            $cols[] = "toInt64(maxIf(s.c$g, s.d = $out) - maxIf(s.c$g, s.d = $in)) AS a$g";
        }
        if($k > 1) {
            $cap = array();
            for($q = 2; $q <= Search_Occupancy::MAX_ROOMS; $q++) {
                $cap[] = "(maxIf(s.k$q, s.d = $out) - maxIf(s.k$q, s.d = $in) = $n)";
            }
            $cols[] = '1 + ' . implode(' + ', $cap) . ' AS cap';
        } else {
            $cols[] = '1 AS cap';
        }
        // на g гостей продаётся, если бит в gmask и ни одна ночь проживания не закрыта на g гостей (дневная цена 0 добавляет
        // к нарастающей сумме 2^40): gm — gmask без закрытых на эти даты g
        $closed = Search_Occupancy::CLOSED;
        if($k > 1 || $hasKids) {
            $bits = array();
            for($g = $gMin; $g <= $gMax; $g++) {
                $bits[] = "if(a$g >= $closed, " . (1 << $g) . ', 0)';
            }
            $cols[] = 'bitAnd(any(s.gmask), bitNot(toUInt16(' . implode(' + ', $bits) . '))) AS gm';
        }
        if($hasKids) {
            $cols[] = 'any(s.max_children) AS mch, any(s.max_occupancy) AS mocc, any(s.max_infants) AS minf,
                       any(s.infants_excluded) AS infx, any(s.children_min_age) AS mage, any(s.children_as_adults) AS caa,
                       any(s.age_group) AS ag, any(s.child_type) AS ct, any(s.child_value) AS cv';
        }
        $rateRooms = "SELECT s.id_hotel AS id_hotel, s.id_rate_room AS id_rate_room,
                       any(s.id_room) AS id_room, any(s.id_rate) AS id_rate, any(s.id_board_type) AS id_board_type,
                       any(s.id_cancel_policy) AS id_cancel_policy, any(s.refundable) AS refundable,
                       any(s.id_currency) AS id_currency, any(s.stars) AS stars,
                       " . implode(",\n                       ", $cols) . "
                FROM {$this->_table} AS s FINAL
                WHERE $where
                  AND " . $this->_roomsPrefilter($c) . "
                GROUP BY id_hotel, id_rate_room
                HAVING count() = 2 AND " . $this->_having($c) . ($k > 1 || $hasKids ? '' : "
                   AND a$gMin < $closed");
        $keep = 'id_hotel, id_rate_room, id_room, id_rate, id_board_type, id_cancel_policy, refundable, id_currency, stars, cap';
        $prices = array();
        for($g = $gMin; $g <= $gMax; $g++) {
            $prices[] = "a$g";
        }
        $adultsList = '[' . implode(', ', $adults) . ']';
        if(!$hasKids && 1 == $k) {
            // один номер без детей: цена номера за adults гостей (gmask проверен в WHERE)
            return "SELECT $keep, 1 AS i, a$gMin AS total FROM ($rateRooms)";
        }
        if(!$hasKids) {
            // только взрослые: цена номера за adults_i гостей
            return "SELECT $keep, i, arrayElement([" . implode(', ', $prices) . "], {$adultsList}[i] - $gMin + 1) AS total
                    FROM ($rateRooms)
                    ARRAY JOIN range(1, " . ($k + 1) . ") AS i
                    WHERE bitTest(gm, {$adultsList}[i])";
        }
        $minAges = array();
        foreach($c['rooms'] as $room) {
            $minAges[] = empty($room['children']) ? 255 : min($room['children']);
        }
        $infant = Search_Occupancy::INFANT_AGE_MAX;
        $maxGuests = Search_Occupancy::MAX_ADULTS;
        $priceList = '[any(' . implode('), any(', $prices) . ')]';
        $pr = "pa_[gd - $gMin + 1]";                                    // цена номера за gd гостей за проживание
        // Дети как строки: kid = (номер запроса, возраст); grp — группа (0 = взрослый); младенец (не `inf` — это литерал
        // бесконечности в ClickHouse) при is_without_infants места не занимает.
        // g — за сколько гостей цена номера: взрослые a2x или ближайшее большее продаваемое (места займут дети).
        // Итог считается вложенными лямбдами: значения передаются параметрами ([x])[1] — «let». Цепочки алиасов
        // (total -> t0 -> cov -> kp -> g) ClickHouse при разборе раскрывает многократно: такой запрос разбирался 0,5 с.
        //   kp — цены детей от цены номера за gd гостей; cov — самые дорогие дети с местом на свободных местах цены (бесплатно);
        //   итог — не дороже, чем цена номера за gd + платящих детей (если на столько продаётся и children_as_adults = 1:
        //   при 0 в лямбду вместо gmask идёт 0 — правило не срабатывает).
        return "WITH kid.2 AS age, ag[age + 1] AS grp, (age < 255 AND grp > 0) AS ch,
                     (ch AND age <= $infant) AS infant, (ch AND NOT (infant AND infx = 1)) AS pl
                SELECT id_hotel, id_rate_room, kid.1 AS i,
                       any(id_room) AS id_room, any(id_rate) AS id_rate, any(id_board_type) AS id_board_type,
                       any(id_cancel_policy) AS id_cancel_policy, any(refundable) AS refundable,
                       any(id_currency) AS id_currency, any(stars) AS stars, any(cap) AS cap, any(gm) AS gmk,
                       toInt64({$adultsList}[i]) + toInt64(countIf(grp = 0 AND age < 255)) AS a2x,
                       toInt64(countIf(pl)) AS np,
                       if(bitTest(gmk, least(a2x, 15)), a2x,
                          toInt64(arrayFirst(x -> bitTest(gmk, x), range(toUInt8(least(a2x + 1, 15)), toUInt8(least(a2x + np + 1, $maxGuests + 1)))))) AS g,
                       $priceList AS pa,
                       groupArrayIf((ct[grp], cv[grp], pl), ch) AS kv,
                       arrayMap((gd, a2, gm_, pa_, kv_) -> arrayMap(kp -> arrayMap(cov ->
                           arrayMap((paying, t0) -> if(paying > 0 AND gd + paying <= $maxGuests AND bitTest(gm_, gd + paying),
                                   least(t0, pa_[gd + paying - $gMin + 1]), t0),
                               [toInt64(arrayCount(p -> p > 0, kp)) - toInt64(arrayCount(p -> p > 0, cov))], [$pr + arraySum(kp) - arraySum(cov)])[1],
                           [arraySlice(arrayReverseSort(arrayFilter((p, x) -> x.3, kp, kv_)), 1, gd - a2)])[1],
                         [arrayMap(x -> multiIf(x.1 = 2, toInt64(x.2) * $n, x.1 = 3, intDiv($pr * x.2 + 5000, 10000),
                             x.1 = 4, intDiv($pr * x.2 + 5000 * gd, 10000 * gd), x.1 = 5, intDiv($pr + intDiv(gd, 2), gd), toInt64(0)), kv_)])[1],
                       [greatest(g, 1)], [a2x], [if(any(caa) = 1, gmk, toUInt16(0))], [pa], [kv])[1] AS total
                FROM ($rateRooms)
                ARRAY JOIN [" . implode(', ', $kids) . "] AS kid
                GROUP BY id_hotel, id_rate_room, i
                HAVING a2x <= $maxGuests AND g > 0 AND any(mage) <= [" . implode(', ', $minAges) . "][i]
                   AND np <= any(mch) AND a2x + np <= any(mocc) AND countIf(infant) <= any(minf)";
    }

    /**
     * Грубый фильтр строк до группировки: рум-рейт подходит хотя бы одному номеру запроса — продаётся на a гостей
     * (a = взрослые + дети вне групп) или, если в номере есть дети, на большее число гостей до взрослых + детей
     * (места займут дети); и минимальный возраст детей. Точные проверки — после группировки.
     */
    protected function _roomsPrefilter(array $c) {
        $or = array();
        foreach($c['rooms'] as $room) {
            if(empty($room['children'])) {
                $cond = 'bitTest(s.gmask, ' . $room['adults'] . ')';
            } else {
                // есть бит в [a; adults + дети]: биты gmask до верхней границы, их сумма >= 2^a
                $upper = min(Search_Occupancy::MAX_ADULTS, $room['adults'] + count($room['children']));
                $cond = 'bitAnd(s.gmask, ' . ((1 << ($upper + 1)) - 1) . ') >= bitShiftLeft(toUInt64(1), ' . $room['adults']
                    . ' + (s.age_group[' . implode(' + 1] = 0) + (s.age_group[', $room['children']) . ' + 1] = 0))'
                    . ' AND s.children_min_age <= ' . min($room['children']);
            }
            $or[] = '(' . $cond . ')';
        }
        return '(' . implode(' OR ', array_unique($or)) . ')';
    }

    protected function _groupList($csv) {
        $list = array();
        if('' !== $csv) {
            foreach(explode(',', $csv) as $j => $v) {
                $list[$j + 1] = (int)$v;
            }
        }
        return $list;
    }

    protected function _cacheSettings() {
        return $this->_queryCacheTtl ? "\n                    SETTINGS use_query_cache = 1, query_cache_ttl = " . $this->_queryCacheTtl : '';
    }

    /**
     * Нормализация и проверка входных параметров.
     */
    protected function _criteria(array $criteria) {
        if(empty($criteria['checkin'])) {
            throw new Search_ClickHouse_Exception('checkin is required', 400);
        }
        foreach(array('checkin', 'checkout') as $k) {
            if(!empty($criteria[$k]) && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $criteria[$k]) || false === strtotime($criteria[$k]))) {
                throw new Search_ClickHouse_Exception('Invalid ' . $k . ', expected YYYY-MM-DD', 400);
            }
        }
        $in = strtotime($criteria['checkin'] . ' 12:00:00');
        if(!empty($criteria['checkout'])) {
            $nights = (int)round((strtotime($criteria['checkout'] . ' 12:00:00') - $in) / 86400);
        } else {
            $nights = isset($criteria['nights']) ? (int)$criteria['nights'] : 1;
        }
        if($nights < 1 || $nights > self::MAX_NIGHTS) {
            throw new Search_ClickHouse_Exception('nights must be 1..' . self::MAX_NIGHTS, 400);
        }
        $today = isset($criteria['today']) ? $criteria['today'] : date('Y-m-d');
        $c = array(
            'in'     => $this->_date(date('Y-m-d', $in)),
            'out'    => $this->_date(date('Y-m-d', $in + $nights * 86400)),
            'nights' => $nights,
            'rooms'  => Search_Occupancy::rooms($criteria),
            'adv'    => (int)floor(($in - strtotime($today . ' 12:00:00')) / 86400),
            'channel'=> isset($criteria['channel']) ? (int)$criteria['channel'] : 1,
            'raw'    => $criteria,
        );
        if($c['adv'] < 0) {
            throw new Search_ClickHouse_Exception('checkin is in the past', 400);
        }
        return $c;
    }

    /**
     * Условия WHERE. Колонки квалифицированы алиасом таблицы s: во внутреннем SELECT есть алиасы
     * с теми же именами (any(stars) AS stars и т.п.), без префикса ClickHouse подставил бы агрегат в WHERE.
     */
    protected function _where(array $c, $dateCondition) {
        $r = $c['raw'];
        $where = array($dateCondition);
        if(!empty($r['id_hotel'])) {
            $where[] = 's.id_hotel IN (' . $this->_intList($r['id_hotel']) . ')';
        }
        foreach(array('id_region', 'id_country', 'id_city') as $k) {
            if(!empty($r[$k])) {
                $where[] = 's.' . $k . ' IN (' . $this->_intList($r[$k]) . ')';
            }
        }
        if(empty($r['id_hotel']) && empty($r['id_region']) && empty($r['id_country']) && empty($r['id_city'])) {
            throw new Search_ClickHouse_Exception('id_hotel, id_region, id_country or id_city is required', 400);
        }
        $where[] = 'bitAnd(s.channel_mask, ' . $c['channel'] . ') != 0';
        if(!empty($r['id_access_group'])) {
            $where[] = '(s.is_public = 1 OR s.id_access_group = ' . (int)$r['id_access_group'] . ')';
        } else {
            $where[] = 's.is_public = 1';
        }
        if(!empty($r['stars'])) {
            $where[] = 's.stars IN (' . $this->_intList($r['stars']) . ')';
        }
        if(!empty($r['id_board_type'])) {
            $where[] = 's.id_board_type IN (' . $this->_intList($r['id_board_type']) . ')';
        }
        if(isset($r['refundable']) && '' !== $r['refundable']) {
            $where[] = 's.refundable = ' . ($r['refundable'] ? 1 : 0);
        }
        return implode("\n                          AND ", $where);
    }

    /**
     * Условия продаваемости проживания: все ночи продаются, ограничения даты заезда и выезда,
     * обе строки из одной сборки отеля (min(ver) = max(ver)).
     */
    protected function _having(array $c) {
        $n = $c['nights'];
        $adv = $c['adv'];
        return "min(ver) = max(ver)
                           AND maxIf(k, d = {$c['out']}) - maxIf(k, d = {$c['in']}) = $n
                           AND maxIf(cta = 1 OR min_los > $n OR max_los < $n OR min_adv > $adv OR max_adv < $adv, d = {$c['in']}) = 0
                           AND maxIf(ctd, d = {$c['out']}) = 0";
    }
}
