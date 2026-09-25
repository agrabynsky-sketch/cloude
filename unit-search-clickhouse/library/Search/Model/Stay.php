<?php

/**
 * Поиск по кэшу unit_search.search_stay.
 *
 * 1) Минимальная цена по отелям (выдача 500-1000 отелей):
 *
 *   $result = Search_Model_Stay::getInstance()->search(array(
 *       'checkin'   => '2026-12-10',
 *       'checkout'  => '2026-12-17',       // или 'nights' => 7
 *       'guests'    => 2,                  // 1..8
 *       'hotel_ids' => $ids,               // и/или 'region_id' / 'country_id' / 'city_id'
 *       'channel'   => 1,                  // бит канала продаж (hotels_sales_channels.bit), по умолчанию 1 = B2C
 *       'stars'     => array(4, 5),        // необязательные фильтры
 *       'board_ids' => array(4, 7),
 *       'refundable'=> 1,
 *       'price_min' => 5000, 'price_max' => 20000,   // за всё проживание, в валюте отеля
 *       'order'     => 'price',            // price | -price | stars | -stars
 *       'limit'     => 30, 'offset' => 0,
 *   ));
 *   // $result = array('total' => 812, 'items' => array(array('hotel_id' => .., 'price' => 12345.5, 'currency_id' => ..,
 *   //            'rate_room_id' => .., 'room_id' => .., 'rate_id' => .., 'board_id' => .., 'refundable' => .., 'stars' => ..), ...))
 *
 * 2) Все доступные рум-рейты отеля:
 *
 *   $rates = Search_Model_Stay::getInstance()->hotelRates(1005, array('checkin' => '2026-12-10', 'nights' => 7, 'guests' => 2));
 *   // список по возрастанию цены: rate_room_id, room_id, rate_id, parent_rate_id, board_id, refundable, room_type_id,
 *   //   max_guests, currency_id, price, rooms_left, nightly (цены по ночам)
 *
 * Все цены — в валюте отеля (currency_id). Перед бронированием цену и наличие обязательно перепроверять в MySQL.
 */
class Search_Model_Stay extends Search_Model_Abstract {
    protected $_table = 'search_stay';

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

        $sql = "SELECT hotel_id, price_minor, currency_id, stars, rate_room_id, room_id, rate_id, board_id, refundable,
                       count() OVER () AS total_hotels
                FROM (
                    SELECT hotel_id,
                           min(total) AS price_minor,
                           argMin(rate_room_id, total) AS rate_room_id,
                           argMin(room_id, total) AS room_id,
                           argMin(rate_id, total) AS rate_id,
                           argMin(board_id, total) AS board_id,
                           argMin(refundable, total) AS refundable,
                           any(currency_id) AS currency_id,
                           any(stars) AS stars
                    FROM (
                        SELECT hotel_id, rate_room_id,
                               any(room_id) AS room_id, any(rate_id) AS rate_id, any(board_id) AS board_id,
                               any(refundable) AS refundable, any(currency_id) AS currency_id, any(stars) AS stars,
                               maxIf($p, d = {$c['out']}) - maxIf($p, d = {$c['in']}) AS total
                        FROM {$this->_table} AS s FINAL
                        WHERE $where
                        GROUP BY hotel_id, rate_room_id
                        HAVING count() = 2 AND $having
                    )
                    GROUP BY hotel_id
                )
                ORDER BY $order, hotel_id
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

    public function hotelRates($hotelId, array $criteria) {
        $criteria['hotel_ids'] = array((int)$hotelId);
        unset($criteria['region_id'], $criteria['country_id'], $criteria['city_id']);
        $c = $this->_criteria($criteria);
        $p = $c['c'];
        // явный список дат вместо BETWEEN: для каждой даты ClickHouse отсекает гранулы по hotel_id (ORDER BY d, hotel_id)
        $where = $this->_where($c, 's.d IN (' . $this->_dateList(trim($c['in'], "'"), trim($c['out'], "'")) . ')');
        $sql = "SELECT rate_room_id, any(room_id) AS room_id, any(rate_id) AS rate_id, any(parent_rate_id) AS parent_rate_id,
                       any(board_id) AS board_id, any(refundable) AS refundable, any(room_type_id) AS room_type_id,
                       any(max_guests) AS max_guests, any(currency_id) AS currency_id,
                       maxIf($p, d = {$c['out']}) - maxIf($p, d = {$c['in']}) AS total,
                       minIf(avail, d < {$c['out']}) AS rooms_left,
                       arrayStringConcat(arrayPopFront(arrayDifference(arrayMap(x -> toInt64(x.2), arraySort(groupArray((d, $p)))))), ',') AS nightly
                FROM {$this->_table} AS s FINAL
                WHERE $where
                GROUP BY rate_room_id
                HAVING count() = " . ($c['nights'] + 1) . " AND " . $this->_having($c) . "
                ORDER BY total, rate_room_id";
        $result = array();
        foreach($this->_client->fetchAll($sql) as $row) {
            $item = array();
            foreach(array('rate_room_id', 'room_id', 'rate_id', 'parent_rate_id', 'board_id', 'refundable', 'room_type_id',
                        'max_guests', 'currency_id', 'rooms_left') as $k) {
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
        if(!empty($r['hotel_ids'])) {
            $where[] = 's.hotel_id IN (' . $this->_intList($r['hotel_ids']) . ')';
        }
        foreach(array('region_id', 'country_id', 'city_id') as $k) {
            if(!empty($r[$k])) {
                $where[] = 's.' . $k . ' IN (' . $this->_intList($r[$k]) . ')';
            }
        }
        if(empty($r['hotel_ids']) && empty($r['region_id']) && empty($r['country_id']) && empty($r['city_id'])) {
            throw new Search_ClickHouse_Exception('hotel_ids, region_id, country_id or city_id is required', 400);
        }
        $where[] = 'bitTest(s.gmask, ' . $c['guests'] . ')';
        $where[] = 'bitAnd(s.channel_mask, ' . $c['channel'] . ') != 0';
        if(!empty($r['access_group_id'])) {
            $where[] = '(s.is_public = 1 OR s.access_group_id = ' . (int)$r['access_group_id'] . ')';
        } else {
            $where[] = 's.is_public = 1';
        }
        if(!empty($r['stars'])) {
            $where[] = 's.stars IN (' . $this->_intList($r['stars']) . ')';
        }
        if(!empty($r['board_ids'])) {
            $where[] = 's.board_id IN (' . $this->_intList($r['board_ids']) . ')';
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
