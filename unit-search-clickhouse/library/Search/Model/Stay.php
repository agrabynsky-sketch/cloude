<?php

/**
 * Поиск по кэшу unit_search.hotels_search_stay.
 *
 * 1) Минимальная цена по отелям (выдача 500-1000 отелей):
 *
 *   $result = Search_Model_Stay::getInstance()->search(array(
 *       'checkin'   => '2026-12-10',
 *       'checkout'  => '2026-12-17',       // или 'nights' => 7
 *       'guests'    => 2,                  // 1..8
 *       'id_hotel'  => $ids,               // и/или 'id_region' / 'id_country' / 'id_city' (число или массив)
 *       'channel'   => 1,                  // бит канала продаж (hotels_sales_channels.bit), по умолчанию 1 = B2C
 *       'stars'     => array(4, 5),        // необязательные фильтры
 *       'id_board_type' => array(4, 7),    // hotels_board_types.id
 *       'refundable'=> 1,
 *       'price_min' => 5000, 'price_max' => 20000,   // за всё проживание, в валюте отеля
 *       'order'     => 'price',            // price | -price | stars | -stars
 *       'limit'     => 30, 'offset' => 0,
 *   ));
 *   // $result = array('total' => 812, 'items' => array(array('id_hotel' => .., 'price' => 12345.5, 'id_currency' => ..,
 *   //            'id_rate_room' => .., 'id_room' => .., 'id_rate' => .., 'id_board_type' => .., 'id_cancel_policy' => ..,
 *   //            'refundable' => .., 'stars' => ..), ...))
 *
 * 2) Все доступные рум-рейты отеля:
 *
 *   $rates = Search_Model_Stay::getInstance()->hotelRates(1005, array('checkin' => '2026-12-10', 'nights' => 7, 'guests' => 2));
 *   // список по возрастанию цены: id_rate_room, id_room, id_rate, id_parent, id_board_type, id_cancel_policy, refundable, id_room_type,
 *   //   max_guests, id_currency, price, rooms_left, nightly (цены по ночам)
 *
 * Все цены — в валюте отеля (id_currency). Перед бронированием цену и наличие обязательно перепроверять в MySQL.
 */
class Search_Model_Stay extends Search_Model_Abstract {
    protected $_table = 'hotels_search_stay';

    const MAX_GUESTS = 8;
    const MAX_NIGHTS = 365;

    public function search(array $criteria) {
        $c = $this->_criteria($criteria);
        $p = $c['c'];
        $where = $this->_where($c, 's.d IN (' . $c['in'] . ', ' . $c['out'] . ')');
        $having = $this->_having($c);
        if(isset($criteria['price_min']) && '' !== $criteria['price_min']) {
            $having .= ' AND total >= ' . (int)round($criteria['price_min'] * 100);
        }
        if(isset($criteria['price_max']) && '' !== $criteria['price_max']) {
            $having .= ' AND total <= ' . (int)round($criteria['price_max'] * 100);
        }
        $orders = array('price' => 'price_minor ASC', '-price' => 'price_minor DESC', 'stars' => 'stars ASC, price_minor ASC', '-stars' => 'stars DESC, price_minor ASC');
        $order = isset($criteria['order'], $orders[$criteria['order']]) ? $orders[$criteria['order']] : $orders['price'];
        $limit = isset($criteria['limit']) ? max(1, min(1000, (int)$criteria['limit'])) : 30;
        $offset = isset($criteria['offset']) ? max(0, (int)$criteria['offset']) : 0;

        $sql = "SELECT id_hotel, price_minor, id_currency, stars, id_rate_room, id_room, id_rate, id_board_type, id_cancel_policy, refundable,
                       count() OVER () AS total_hotels
                FROM (
                    SELECT id_hotel,
                           min(total) AS price_minor,
                           argMin(id_rate_room, total) AS id_rate_room,
                           argMin(id_room, total) AS id_room,
                           argMin(id_rate, total) AS id_rate,
                           argMin(id_board_type, total) AS id_board_type,
                           argMin(id_cancel_policy, total) AS id_cancel_policy,
                           argMin(refundable, total) AS refundable,
                           any(id_currency) AS id_currency,
                           any(stars) AS stars
                    FROM (
                        SELECT id_hotel, id_rate_room,
                               any(id_room) AS id_room, any(id_rate) AS id_rate, any(id_board_type) AS id_board_type,
                               any(id_cancel_policy) AS id_cancel_policy,
                               any(refundable) AS refundable, any(id_currency) AS id_currency, any(stars) AS stars,
                               maxIf($p, d = {$c['out']}) - maxIf($p, d = {$c['in']}) AS total
                        FROM {$this->_table} AS s FINAL
                        WHERE $where
                        GROUP BY id_hotel, id_rate_room
                        HAVING count() = 2 AND $having
                    )
                    GROUP BY id_hotel
                )
                ORDER BY $order, id_hotel
                LIMIT $offset, $limit";
        $rows = $this->_client->fetchAll($sql);
        $result = array('total' => empty($rows) ? 0 : (int)$rows[0]['total_hotels'], 'items' => array());
        foreach($rows as $row) {
            unset($row['total_hotels']);
            foreach($row as $k => $v) {
                $row[$k] = (int)$v;
            }
            $row['price'] = $row['price_minor'] / 100;
            $row['nights'] = $c['nights'];
            $result['items'][] = $row;
        }
        return $result;
    }

    public function hotelRates($idHotel, array $criteria) {
        $criteria['id_hotel'] = array((int)$idHotel);
        unset($criteria['id_region'], $criteria['id_country'], $criteria['id_city']);
        $c = $this->_criteria($criteria);
        $p = $c['c'];
        // явный список дат вместо BETWEEN: для каждой даты ClickHouse отсекает гранулы по id_hotel (ORDER BY d, id_hotel)
        $where = $this->_where($c, 's.d IN (' . $this->_dateList(trim($c['in'], "'"), trim($c['out'], "'")) . ')');
        $sql = "SELECT id_rate_room, any(id_room) AS id_room, any(id_rate) AS id_rate, any(id_parent) AS id_parent,
                       any(id_board_type) AS id_board_type, any(id_cancel_policy) AS id_cancel_policy,
                       any(refundable) AS refundable, any(id_room_type) AS id_room_type,
                       any(max_guests) AS max_guests, any(id_currency) AS id_currency,
                       maxIf($p, d = {$c['out']}) - maxIf($p, d = {$c['in']}) AS total,
                       minIf(avail, d < {$c['out']}) AS rooms_left,
                       arrayStringConcat(arrayPopFront(arrayDifference(arrayMap(x -> toInt64(x.2), arraySort(groupArray((d, $p)))))), ',') AS nightly
                FROM {$this->_table} AS s FINAL
                WHERE $where
                GROUP BY id_rate_room
                HAVING count() = " . ($c['nights'] + 1) . " AND " . $this->_having($c) . "
                ORDER BY total, id_rate_room";
        $result = array();
        foreach($this->_client->fetchAll($sql) as $row) {
            $item = array();
            foreach(array('id_rate_room', 'id_room', 'id_rate', 'id_parent', 'id_board_type', 'id_cancel_policy', 'refundable', 'id_room_type',
                        'max_guests', 'id_currency', 'rooms_left') as $k) {
                $item[$k] = (int)$row[$k];
            }
            $item['price_minor'] = (int)$row['total'];
            $item['price'] = $item['price_minor'] / 100;
            $item['nightly'] = array();
            foreach(explode(',', $row['nightly']) as $v) {
                $item['nightly'][] = (int)$v / 100;
            }
            $result[] = $item;
        }
        return $result;
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
        $guests = isset($criteria['guests']) ? (int)$criteria['guests'] : 2;
        if($guests < 1 || $guests > self::MAX_GUESTS) {
            throw new Search_ClickHouse_Exception('guests must be 1..' . self::MAX_GUESTS, 400);
        }
        $today = isset($criteria['today']) ? $criteria['today'] : date('Y-m-d');
        $c = array(
            'in'     => $this->_date(date('Y-m-d', $in)),
            'out'    => $this->_date(date('Y-m-d', $in + $nights * 86400)),
            'nights' => $nights,
            'guests' => $guests,
            'c'      => 'c' . $guests,   // колонка нарастающей суммы для числа гостей (белый список 1..8)
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
        $where[] = 'bitTest(s.gmask, ' . $c['guests'] . ')';
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
