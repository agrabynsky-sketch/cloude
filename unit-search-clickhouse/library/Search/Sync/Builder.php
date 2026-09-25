<?php

/**
 * Сборщик поискового кэша: MySQL (source of truth) -> строки unit_search.hotels_search_stay.
 *
 * Отель собирается ЦЕЛИКОМ: все его активные рум-рейты × все даты горизонта [start; start + HORIZON_DAYS].
 * Бизнес-правила (одинаковые с clickhouse/03_full_load_from_mysql.sql и tests/verify_reference.php):
 *
 *  В поиск попадает рум-рейт: hotels.active = 1, hotels_rooms.active = 1, hotels_rates.active = 1, есть hotels_rates_rooms.
 *
 *  Базовая цена ночи (в копейках):
 *   - самостоятельный тариф (id_parent = 0): строка hotels_rates_prices с active = 1 и price IS NOT NULL, иначе ночь не продаётся;
 *   - производный тариф (id_parent > 0):
 *       своя строка с active = 0                      -> не продаётся;
 *       своя строка с price IS NOT NULL и derive_type = 0 -> своя цена;
 *       иначе цена родителя (тот же номер) × (100 + dv) / 100, где dv = своя derive_value при derive_type = 4,
 *       иначе -hotels_rates.derive_value (скидка тарифа); нет цены родителя -> не продаётся.
 *  Наличие номера: строка hotels_rooms_availability, иначе allotment номера, net_booked = 0, active = 1;
 *   ночь продаётся, если active = 1 и allotment - net_booked > 0.
 *  Цена на g гостей (g = 1..min(8, max(max_occupancy, base_occupancy))):
 *   pricing_model = 2 и есть строка hotels_rates_occupancy(rate_room, g): active = 1 -> база × (10000 + amount×100) / 10000,
 *   active = 0 -> на g гостей не продаётся; иначе (нет строки или pricing_model = 1) -> базовая цена.
 *   pricing_model = 2 и есть строка hotels_rates_occupancy_daily(rate_room, g, дата) с price > 0 -> цена ночи на g гостей
 *   = эта price (приоритет над процентом). Строка действует, только если ночь продаётся (есть базовая цена, номер свободен)
 *   и g гостей не выключено; при дублях берётся строка с максимальным id; price = 0 — "не задано".
 *  Ограничения даты (из своей строки цены рум-рейта, иначе из тарифа):
 *   min_los = своя min_los, если не NULL, иначе hotels_rates.min_los; < 1 -> 1;  max_los: >0 или 999;
 *   min_adv = своя min_adv, если не NULL, иначе hotels_rates.min_adv;          max_adv: >0 или 9999;
 *   cta / ctd — из своей строки (иначе 0).
 *  Округление — целочисленное (half up), чтобы PHP и ClickHouse давали одинаковые копейки.
 *  Дубли (одна и та же ночь/номер/гости несколько раз — в MySQL нет UNIQUE): побеждает строка с максимальным id
 *  (запросы идут с ORDER BY id, последняя строка перезаписывает предыдущие).
 *
 * Память: строки не копятся — каждая отдаётся в callback готовой TSV-строкой ("...\n") в порядке self::$columns
 * и сразу пишется в буфер воркера.
 */
class Search_Sync_Builder {
    const HORIZON_DAYS = 365;   // ночей; строк на рум-рейт = HORIZON_DAYS + 1 (последняя дата нужна как дата выезда)
    const MAX_GUESTS = 8;

    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;
    protected $_start;
    protected $_days = array();   // 'Y-m-d' => index

    public static $columns = array(
        'd', 'id_hotel', 'id_rate_room', 'id_room', 'id_rate', 'id_parent',
        'id_country', 'id_region', 'id_city', 'stars', 'id_currency',
        'id_board_type', 'id_cancel_policy', 'refundable', 'channel_mask', 'is_public', 'id_access_group',
        'id_room_type', 'max_guests', 'gmask',
        'c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'k',
        'avail', 'cta', 'ctd', 'min_los', 'max_los', 'min_adv', 'max_adv',
        'ver', 'is_deleted',
    );

    /**
     * @param Zend_Db_Adapter_Abstract $db адаптер MySQL (по умолчанию — адаптер Zend_Db_Table)
     * @param string $startDate первая дата горизонта, по умолчанию сегодня
     */
    public function __construct($db = null, $startDate = null) {
        $this->_db = $db ? $db : Zend_Db_Table_Abstract::getDefaultAdapter();
        $this->setStartDate($startDate ? $startDate : date('Y-m-d'));
    }

    public function setStartDate($date) {
        $this->_start = date('Y-m-d', strtotime($date));
        $this->_days = array();
        $ts = strtotime($this->_start . ' 12:00:00');
        for($i = 0; $i <= self::HORIZON_DAYS; $i++) {
            $this->_days[date('Y-m-d', $ts + $i * 86400)] = $i;
        }
        return $this;
    }

    public function getStartDate() {
        return $this->_start;
    }

    public function getDates() {
        return array_keys($this->_days);
    }

    /**
     * Собрать отели. Для каждой строки вызывается $emit($tsvLine) — строка TabSeparated в порядке self::$columns.
     * @param array $hotelIds
     * @param int $ver версия сборки (одна на пачку)
     * @param callable $emit
     * @return array id_hotel => array(id_rate_room, ...) — какие рум-рейты собраны (для tombstone удалённых)
     */
    public function build(array $hotelIds, $ver, $emit) {
        $hotelIds = array_values(array_unique(array_map('intval', $hotelIds)));
        $built = array_fill_keys($hotelIds, array());
        if(empty($hotelIds)) {
            return $built;
        }
        $db = $this->_db;
        $end = date('Y-m-d', strtotime($this->_start . ' 12:00:00') + self::HORIZON_DAYS * 86400);

        $hotels = $this->_assoc($db->fetchAll('SELECT id, stars, id_country, id_region, id_city, id_currency FROM hotels
            WHERE active = 1 AND id IN (' . implode(',', $hotelIds) . ')'));
        if(empty($hotels)) {
            return $built;
        }
        $rooms = $this->_assoc($db->fetchAll('SELECT id, id_hotel, id_type, allotment, base_occupancy, max_occupancy, pricing_model
            FROM hotels_rooms WHERE active = 1 AND id_hotel IN (' . implode(',', array_keys($hotels)) . ')'));
        $rates = $this->_assoc($db->fetchAll('SELECT id, id_hotel, id_parent, id_board_type, id_cancel_policy, min_los, min_adv,
            derive_value, channel_mask, visibility, access_group_id
            FROM hotels_rates WHERE active = 1 AND id_hotel IN (' . implode(',', array_keys($hotels)) . ')'));
        if(empty($rooms) || empty($rates)) {
            return $built;
        }
        $rateRooms = array();
        $byRateRoom = array();   // id_rate => id_room => id_rate_room
        foreach($db->fetchAll('SELECT id, id_rate, id_room FROM hotels_rates_rooms WHERE id_room IN (' . implode(',', array_keys($rooms)) . ')') as $row) {
            if(!isset($rates[$row['id_rate']]) || $rates[$row['id_rate']]['id_hotel'] != $rooms[$row['id_room']]['id_hotel']) {
                continue;
            }
            $rateRooms[$row['id']] = $row;
            $byRateRoom[$row['id_rate']][$row['id_room']] = (int)$row['id'];
        }
        if(empty($rateRooms)) {
            return $built;
        }
        $rrIds = implode(',', array_keys($rateRooms));
        $between = ' BETWEEN ' . $db->quote($this->_start) . ' AND ' . $db->quote($end);

        // per-night rows, stored compactly: [rate_room][day] = array(price, derive_type, derive_value, min_los, max_los, min_adv, max_adv, cta, ctd, active)
        $prices = array();
        $stmt = $db->query('SELECT id_rate_room, date, price, derive_type, derive_value, min_los, max_los, min_adv, max_adv, cta, ctd, active
            FROM hotels_rates_prices WHERE id_rate_room IN (' . $rrIds . ') AND date' . $between . ' ORDER BY id');
        while($r = $stmt->fetch(Zend_Db::FETCH_NUM)) {
            if(!isset($this->_days[$r[1]])) {
                continue;
            }
            $prices[$r[0]][$this->_days[$r[1]]] = array(self::toMinor($r[2]), (int)$r[3], (int)$r[4],
                is_null($r[5]) ? null : (int)$r[5], (int)$r[6], is_null($r[7]) ? null : (int)$r[7], (int)$r[8], (int)$r[9], (int)$r[10], (int)$r[11]);
        }
        $avail = array();
        $stmt = $db->query('SELECT id_room, date, allotment, net_booked, active FROM hotels_rooms_availability
            WHERE id_room IN (' . implode(',', array_keys($rooms)) . ') AND date' . $between . ' ORDER BY id');
        while($r = $stmt->fetch(Zend_Db::FETCH_NUM)) {
            if(isset($this->_days[$r[1]])) {
                $avail[$r[0]][$this->_days[$r[1]]] = array(is_null($r[2]) ? null : (int)$r[2], (int)$r[3], (int)$r[4]);
            }
        }
        $occupancy = array();
        foreach($db->fetchAll('SELECT id_rate_room, guests, amount, active FROM hotels_rates_occupancy WHERE id_rate_room IN (' . $rrIds . ') ORDER BY id') as $r) {
            $occupancy[$r['id_rate_room']][(int)$r['guests']] = array(self::toMinor($r['amount']), (int)$r['active']);
        }
        // дневные цены на число гостей: [rate_room][day][guests] = копейки; ORDER BY id — при дублях побеждает последняя строка
        $occupancyDaily = array();
        $stmt = $db->query('SELECT id_rate_room, date, guests, price FROM hotels_rates_occupancy_daily
            WHERE id_rate_room IN (' . $rrIds . ') AND date' . $between . ' ORDER BY id');
        while($r = $stmt->fetch(Zend_Db::FETCH_NUM)) {
            if(isset($this->_days[$r[1]])) {
                $occupancyDaily[$r[0]][$this->_days[$r[1]]][(int)$r[2]] = self::toMinor($r[3]);
            }
        }

        $dates = array_keys($this->_days);
        $nDates = count($dates);
        foreach($rateRooms as $rrId => $rr) {
            $room = $rooms[$rr['id_room']];
            $rate = $rates[$rr['id_rate']];
            $hotel = $hotels[$room['id_hotel']];
            $parentId = (int)$rate['id_parent'];
            $parentRr = ($parentId && isset($byRateRoom[$parentId][$rr['id_room']])) ? $byRateRoom[$parentId][$rr['id_room']] : 0;
            $own = isset($prices[$rrId]) ? $prices[$rrId] : array();
            $par = ($parentRr && isset($prices[$parentRr])) ? $prices[$parentRr] : array();
            $av = isset($avail[$rr['id_room']]) ? $avail[$rr['id_room']] : array();
            $occBased = 2 == $room['pricing_model'];
            $daily = ($occBased && isset($occupancyDaily[$rrId])) ? $occupancyDaily[$rrId] : array();

            // guests allowed and occupancy multipliers (static per rate-room)
            $maxGuests = min(self::MAX_GUESTS, max((int)$room['max_occupancy'], (int)$room['base_occupancy'], 1));
            $gmask = 0;
            $mult = array();   // g => basis points (10000 = base price)
            for($g = 1; $g <= self::MAX_GUESTS; $g++) {
                if($g > $maxGuests) {
                    continue;
                }
                if(2 == $room['pricing_model'] && isset($occupancy[$rrId][$g])) {
                    if(!$occupancy[$rrId][$g][1]) {
                        continue;
                    }
                    $mult[$g] = 10000 + $occupancy[$rrId][$g][0];
                } else {
                    $mult[$g] = 10000;
                }
                $gmask |= 1 << $g;
            }

            $static = array(
                (int)$room['id_hotel'], (int)$rrId, (int)$rr['id_room'], (int)$rr['id_rate'], $parentId,
                (int)$hotel['id_country'], (int)$hotel['id_region'], (int)$hotel['id_city'], (int)$hotel['stars'], (int)$hotel['id_currency'],
                (int)$rate['id_board_type'], (int)$rate['id_cancel_policy'], empty($rate['id_cancel_policy']) ? 0 : 1, (int)$rate['channel_mask'],
                'public' == $rate['visibility'] ? 1 : 0, (int)$rate['access_group_id'],
                (int)$room['id_type'], $maxGuests, $gmask,
            );
            $staticTsv = implode("\t", $static);
            $tail = "\t" . $ver . "\t0\n";
            $rateMinLos = (int)$rate['min_los'];
            $rateMinAdv = (int)$rate['min_adv'];
            $rateDerive = -(int)$rate['derive_value'];
            $sum = array(1 => 0, 0, 0, 0, 0, 0, 0, 0);
            $k = 0;
            for($i = 0; $i < $nDates; $i++) {
                $o = isset($own[$i]) ? $own[$i] : null;
                // base price of the night
                $base = null;
                if(!$parentId) {
                    if($o && 1 == $o[9] && !is_null($o[0])) {
                        $base = $o[0];
                    }
                } elseif($o && 0 == $o[9]) {
                    $base = null;
                } elseif($o && !is_null($o[0]) && 0 == $o[1]) {
                    $base = $o[0];
                } elseif(isset($par[$i]) && 1 == $par[$i][9] && !is_null($par[$i][0])) {
                    $dv = ($o && 4 == $o[1]) ? $o[2] : $rateDerive;
                    $base = self::roundDiv($par[$i][0] * (100 + $dv), 100);
                }
                // room availability of the night
                $a = isset($av[$i]) ? $av[$i] : null;
                $free = ($a && !is_null($a[0]) ? $a[0] : (int)$room['allotment']) - ($a ? $a[1] : 0);
                $sell = (!$a || 1 == $a[2]) && $free > 0 && !is_null($base) && $base > 0;

                $minLos = ($o && !is_null($o[3])) ? $o[3] : $rateMinLos;
                $minAdv = ($o && !is_null($o[5])) ? $o[5] : $rateMinAdv;
                call_user_func($emit, $dates[$i] . "\t" . $staticTsv . "\t" . implode("\t", $sum) . "\t" . $k
                    . "\t" . ($sell ? min($free, 65535) : 0)
                    . "\t" . (($o && $o[7] > 0) ? 1 : 0)
                    . "\t" . (($o && $o[8] > 0) ? 1 : 0)
                    . "\t" . min(65535, max(1, $minLos))
                    . "\t" . (($o && $o[4] > 0) ? min(65535, $o[4]) : 999)
                    . "\t" . min(65535, max(0, $minAdv))
                    . "\t" . (($o && $o[6] > 0) ? min(65535, $o[6]) : 9999)
                    . $tail);

                if($sell) {
                    $k++;
                    $dayPrices = isset($daily[$i]) ? $daily[$i] : null;
                    foreach($mult as $g => $m) {
                        if($dayPrices && isset($dayPrices[$g]) && $dayPrices[$g] > 0) {
                            $sum[$g] += $dayPrices[$g];
                        } else {
                            $sum[$g] += max(0, 10000 == $m ? $base : self::roundDiv($base * $m, 10000));
                        }
                    }
                }
            }
            $built[(int)$room['id_hotel']][] = (int)$rrId;
        }
        return $built;
    }

    /**
     * Tombstone-строки (is_deleted = 1) для рум-рейтов, которых больше нет в MySQL.
     */
    public function buildTombstones($hotelId, array $rateRoomIds, $ver, $emit) {
        $zeros = implode("\t", array_fill(0, count(self::$columns) - 5, 0));   // everything between id_rate_room and ver
        foreach($rateRoomIds as $rrId) {
            foreach($this->_days as $date => $i) {
                call_user_func($emit, $date . "\t" . (int)$hotelId . "\t" . (int)$rrId . "\t" . $zeros . "\t" . $ver . "\t1\n");
            }
        }
    }

    /**
     * '1766.00' -> 176600, '-10.5' -> -1050, NULL -> NULL. Без float, чтобы не терять копейки.
     */
    public static function toMinor($value) {
        if(is_null($value) || '' === $value) {
            return null;
        }
        $value = (string)$value;
        $neg = '-' === $value[0];
        $parts = explode('.', ltrim($value, '-+'));
        $minor = (int)$parts[0] * 100 + (isset($parts[1]) ? (int)str_pad(substr($parts[1], 0, 2), 2, '0') : 0);
        return $neg ? -$minor : $minor;
    }

    /**
     * Целочисленное деление с округлением half up (для отрицательных — к нулю, как intDiv в ClickHouse).
     */
    public static function roundDiv($a, $b) {
        $a += (int)($b / 2);
        return ($a - $a % $b) / $b;
    }

    protected function _assoc(array $rows) {
        $result = array();
        foreach($rows as $row) {
            $result[$row['id']] = $row;
        }
        return $result;
    }
}
