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
 *  Тариф номера — только за взрослых (g = 1..8), дети считаются по детской политике.
 *  Какие g продаются (gmask): g <= max_adults (0 -> max(max_occupancy, base_occupancy)), g <= R + E, g <= max_occupancy, и
 *   pricing_model = 2 (per guest): нет строки hotels_rates_occupancy(rate_room, g) с active = 0;
 *   pricing_model = 1 (per room):  g <= base_occupancy или у отеля задана extra_bed_adult_price (NULL = доп. взрослые не принимаются).
 *  Цена ночи за g взрослых:
 *   per guest: строка hotels_rates_occupancy(rate_room, g) active = 1 -> база × (10000 + amount×100) / 10000, нет строки -> база;
 *              hotels_rates_occupancy_daily(rate_room, g, дата) с price > 0 -> эта price (приоритет над процентом).
 *              Дневная строка действует, только если ночь продаётся и g не выключено; дубли -> максимальный id; price = 0 — "не задано".
 *              Доплата за доп. кровать взрослому не начисляется: цена «за g» её уже включает.
 *   per room:  g <= base_occupancy -> база; иначе база + (g - base_occupancy) × extra_bed_adult_price.
 *  Места: R = places_regular (NULL/0 -> max(base_occupancy, max_adults)), E = extra_beds, S = share_slots.
 *  Детская политика (hotels_children_groups / hotels_children_prices):
 *   группы рум-рейта = активные группы его тарифа (id_rate), если есть хотя бы одна, иначе группы отеля (id_rate = 0);
 *   age_group[возраст] = номер группы по порядку (age_from, id); при пересечении групп побеждает больший id; 0 = взрослый;
 *   правило ночи для группы: исключение hotels_children_prices (тариф важнее «всех», с датами важнее «без дат», больший id),
 *   иначе цены группы; цена: 0 нельзя (+2^40), 1 бесплатно, 2 фикс, 3 % от одноместной цены ночи (half up);
 *   одноместная цена ночи = цена за 1 взрослого, если продаётся на 1, иначе за base_occupancy, иначе база.
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
    const MAX_GUESTS = 8;       // взрослых в номере (колонки c1..c8)
    const MAX_ROOMS = 4;        // k2..k4: хватает ли номеров, когда в запросе до 4 номеров
    const MAX_CHILD_AGE = 17;   // age_group: возраста 0..17
    const NOT_ALLOWED = 1099511627776;   // 2^40: ночь, где размещение ребёнка запрещено (разность нарастающих сумм >= 2^40 = нельзя)

    // типы детских цен (hotels_children_groups.bed_type / extra_type)
    const CHILD_NO = 0;
    const CHILD_FREE = 1;
    const CHILD_FIXED = 2;
    const CHILD_PERCENT = 3;

    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;
    protected $_start;
    protected $_days = array();   // 'Y-m-d' => index

    public static $columns = array(
        'd', 'id_hotel', 'id_rate_room', 'id_room', 'id_rate', 'id_parent',
        'id_country', 'id_region', 'id_city', 'stars', 'id_currency',
        'id_board_type', 'id_cancel_policy', 'refundable', 'channel_mask', 'is_public', 'id_access_group',
        'id_room_type', 'max_guests', 'gmask',
        'places_regular', 'extra_beds', 'share_slots', 'max_children', 'max_occupancy', 'infants_excluded', 'children_min_age', 'age_group',
        'c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'k', 'k2', 'k3', 'k4', 'cb', 'ce',
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

        $hotels = $this->_assoc($db->fetchAll('SELECT id, stars, id_country, id_region, id_city, id_currency,
            allow_children, children_min_age, extra_bed_adult_price
            FROM hotels WHERE active = 1 AND id IN (' . implode(',', $hotelIds) . ')'));
        if(empty($hotels)) {
            return $built;
        }
        $hotelList = implode(',', array_keys($hotels));
        $rooms = $this->_assoc($db->fetchAll('SELECT id, id_hotel, id_type, allotment, base_occupancy, max_occupancy, max_adults, max_children,
            is_without_infants, pricing_model, places_regular, extra_beds, share_slots
            FROM hotels_rooms WHERE active = 1 AND id_hotel IN (' . $hotelList . ')'));
        $rates = $this->_assoc($db->fetchAll('SELECT id, id_hotel, id_parent, id_board_type, id_cancel_policy, min_los, min_adv,
            derive_value, channel_mask, visibility, access_group_id
            FROM hotels_rates WHERE active = 1 AND id_hotel IN (' . $hotelList . ')'));
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

        // детская политика: группы по отелю и тарифу, исключения цен по группам
        $groups = array();          // id_hotel => id_rate (0 = отель) => array(группа, ...)
        $groupHotel = array();      // id группы => id_hotel
        foreach($db->fetchAll('SELECT id, id_hotel, id_rate, age_from, age_to, bed_type, bed_value, extra_type, extra_value
            FROM hotels_children_groups WHERE active = 1 AND id_hotel IN (' . $hotelList . ') ORDER BY id') as $g) {
            $groups[$g['id_hotel']][(int)$g['id_rate']][] = $g;
            $groupHotel[$g['id']] = (int)$g['id_hotel'];
        }
        $overrides = array();       // id группы => array(исключение, ...) в порядке id
        foreach($db->fetchAll('SELECT id, id_hotel, id_group, id_rate, date_from, date_to, bed_type, bed_value, extra_type, extra_value
            FROM hotels_children_prices WHERE active = 1 AND id_hotel IN (' . $hotelList . ') ORDER BY id') as $o) {
            if(isset($groupHotel[$o['id_group']]) && $groupHotel[$o['id_group']] == $o['id_hotel']) {
                $overrides[$o['id_group']][] = $o;
            }
        }
        $policies = array();        // id_rate => детская политика рум-рейтов этого тарифа (одинаковая для всех номеров)

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

            // места и лимиты номера
            $baseOcc = max(1, (int)$room['base_occupancy']);
            $maxAdults = (int)$room['max_adults'] > 0 ? (int)$room['max_adults'] : max((int)$room['max_occupancy'], $baseOcc);
            $placesRegular = (int)$room['places_regular'] > 0 ? (int)$room['places_regular'] : max($baseOcc, $maxAdults);
            $extraBeds = (int)$room['extra_beds'];
            $maxOccupancy = (int)$room['max_occupancy'] > 0 ? (int)$room['max_occupancy'] : $maxAdults + (int)$room['max_children'];
            $adultsLimit = min(self::MAX_GUESTS, $maxAdults, $placesRegular + $extraBeds, $maxOccupancy);
            $extraAdult = self::toMinor($hotel['extra_bed_adult_price']);   // per room: доплата за взрослого сверх base_occupancy

            // какие g взрослых продаются и множители цены (статические для рум-рейта)
            $gmask = 0;
            $mult = array();   // g => basis points (10000 = base price); per room: 10000, доплата добавляется отдельно
            for($g = 1; $g <= $adultsLimit; $g++) {
                if($occBased) {
                    if(isset($occupancy[$rrId][$g])) {
                        if(!$occupancy[$rrId][$g][1]) {
                            continue;
                        }
                        $mult[$g] = 10000 + $occupancy[$rrId][$g][0];
                    } else {
                        $mult[$g] = 10000;
                    }
                } elseif($g <= $baseOcc || !is_null($extraAdult)) {
                    $mult[$g] = 10000;
                } else {
                    continue;
                }
                $gmask |= 1 << $g;
            }
            $maxGuests = empty($mult) ? 0 : max(array_keys($mult));
            if(!isset($policies[$rr['id_rate']])) {
                $policies[$rr['id_rate']] = $this->_childPolicy($rate, $hotel, isset($groups[$rate['id_hotel']]) ? $groups[$rate['id_hotel']] : array(), $overrides);
            }
            $policy = $policies[$rr['id_rate']];
            $nGroups = count($policy['groups']);

            $static = array(
                (int)$room['id_hotel'], (int)$rrId, (int)$rr['id_room'], (int)$rr['id_rate'], $parentId,
                (int)$hotel['id_country'], (int)$hotel['id_region'], (int)$hotel['id_city'], (int)$hotel['stars'], (int)$hotel['id_currency'],
                (int)$rate['id_board_type'], (int)$rate['id_cancel_policy'], empty($rate['id_cancel_policy']) ? 0 : 1, (int)$rate['channel_mask'],
                'public' == $rate['visibility'] ? 1 : 0, (int)$rate['access_group_id'],
                (int)$room['id_type'], $maxGuests, $gmask,
                min(255, $placesRegular), min(255, $extraBeds), min(255, (int)$room['share_slots']), min(255, max(0, (int)$room['max_children'])),
                min(255, $maxOccupancy), $room['is_without_infants'] ? 1 : 0, $policy['min_age'], '[' . implode(',', $policy['age_group']) . ']',
            );
            $staticTsv = implode("\t", $static);
            $tail = "\t" . $ver . "\t0\n";
            $rateMinLos = (int)$rate['min_los'];
            $rateMinAdv = (int)$rate['min_adv'];
            $rateDerive = -(int)$rate['derive_value'];
            $sum = array(1 => 0, 0, 0, 0, 0, 0, 0, 0);
            $k = 0;
            $kq = array(2 => 0, 0, 0);                        // ночей, где свободно >= 2 / 3 / 4 номеров
            $cb = $nGroups ? array_fill(0, $nGroups, 0) : array();
            $ce = $cb;
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
                call_user_func($emit, $dates[$i] . "\t" . $staticTsv . "\t" . implode("\t", $sum) . "\t" . $k . "\t" . implode("\t", $kq)
                    . "\t[" . implode(',', $cb) . "]\t[" . implode(',', $ce) . ']'
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
                    for($q = 2; $q <= self::MAX_ROOMS; $q++) {
                        $kq[$q] += $free >= $q ? 1 : 0;
                    }
                    // цены ночи за g взрослых
                    $dayPrices = isset($daily[$i]) ? $daily[$i] : null;
                    $p = array();
                    foreach($mult as $g => $m) {
                        if(!$occBased) {
                            $p[$g] = $base + max(0, $g - $baseOcc) * $extraAdult;
                        } elseif($dayPrices && isset($dayPrices[$g]) && $dayPrices[$g] > 0) {
                            $p[$g] = $dayPrices[$g];
                        } else {
                            $p[$g] = max(0, 10000 == $m ? $base : self::roundDiv($base * $m, 10000));
                        }
                        $sum[$g] += $p[$g];
                    }
                    // дети: цена ночи по группам (одноместная цена — база для «% от взрослого»);
                    // то же, что self::childNightPrice(), развёрнуто без вызова функции — это самый горячий цикл сборщика
                    if($nGroups) {
                        $single = isset($p[1]) ? $p[1] : (isset($p[$baseOcc]) ? $p[$baseOcc] : $base);
                        foreach($policy['groups'] as $j => $group) {
                            $rule = isset($group['days'][$i]) ? $group['days'][$i] : $group['rule'];
                            if(3 == $rule[0]) {
                                $x = $single * $rule[1] + 5000;
                                $cb[$j] += ($x - $x % 10000) / 10000;
                            } else {
                                $cb[$j] += 1 == $rule[0] ? 0 : (2 == $rule[0] ? $rule[1] : self::NOT_ALLOWED);
                            }
                            if(3 == $rule[2]) {
                                $x = $single * $rule[3] + 5000;
                                $ce[$j] += ($x - $x % 10000) / 10000;
                            } else {
                                $ce[$j] += 1 == $rule[2] ? 0 : (2 == $rule[2] ? $rule[3] : self::NOT_ALLOWED);
                            }
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
        $zeros = array();   // everything between id_rate_room and ver: 0, для массивов []
        foreach(array_slice(self::$columns, 3, count(self::$columns) - 5) as $column) {
            $zeros[] = in_array($column, array('age_group', 'cb', 'ce')) ? '[]' : '0';
        }
        $zeros = implode("\t", $zeros);
        foreach($rateRoomIds as $rrId) {
            foreach($this->_days as $date => $i) {
                call_user_func($emit, $date . "\t" . (int)$hotelId . "\t" . (int)$rrId . "\t" . $zeros . "\t" . $ver . "\t1\n");
            }
        }
    }

    /**
     * Цена ребёнка за одну ночь в копейках.
     * @param int $type self::CHILD_*
     * @param int $value фикс — копейки; процент — базисные пункты (20.00% = 2000)
     * @param int $single одноместная цена ночи
     */
    public static function childNightPrice($type, $value, $single) {
        switch($type) {
            case self::CHILD_FREE:
                return 0;
            case self::CHILD_FIXED:
                return max(0, $value);
            case self::CHILD_PERCENT:
                return self::roundDiv($single * max(0, $value), 10000);
            default:
                return self::NOT_ALLOWED;
        }
    }

    /**
     * Детская политика тарифа: минимальный возраст, возраст -> группа и правило каждой группы по дням горизонта.
     * @return array('min_age' => int, 'age_group' => array(18), 'groups' => array(j => array('rule' => array(4), 'days' => array(i => array(4)))))
     */
    protected function _childPolicy(array $rate, array $hotel, array $hotelGroups, array $overrides) {
        $minAge = (isset($hotel['allow_children']) && !is_null($hotel['allow_children']) && !$hotel['allow_children'])
            ? 255 : min(254, max(0, (int)$hotel['children_min_age']));
        // свои группы тарифа заменяют группы отеля
        $list = !empty($hotelGroups[(int)$rate['id']]) ? $hotelGroups[(int)$rate['id']] : (isset($hotelGroups[0]) ? $hotelGroups[0] : array());
        usort($list, function($a, $b) {
            return $a['age_from'] != $b['age_from'] ? $a['age_from'] - $b['age_from'] : $a['id'] - $b['id'];
        });
        $ageGroup = array_fill(0, self::MAX_CHILD_AGE + 1, 0);
        $ageGroupId = array_fill(0, self::MAX_CHILD_AGE + 1, 0);
        $groups = array();
        foreach($list as $j => $g) {
            for($age = (int)$g['age_from']; $age <= min(self::MAX_CHILD_AGE, (int)$g['age_to']); $age++) {
                if($g['id'] > $ageGroupId[$age]) {           // пересечение групп: побеждает больший id
                    $ageGroupId[$age] = (int)$g['id'];
                    $ageGroup[$age] = $j + 1;
                }
            }
            // исключения цен этой группы, применимые к тарифу: от менее точного к более точному
            $rows = array();
            if(isset($overrides[$g['id']])) {
                foreach($overrides[$g['id']] as $o) {
                    if(0 == $o['id_rate'] || $o['id_rate'] == $rate['id']) {
                        $rows[] = $o;
                    }
                }
            }
            usort($rows, function($a, $b) use ($rate) {
                $pa = array($a['id_rate'] == $rate['id'] ? 1 : 0, (is_null($a['date_from']) && is_null($a['date_to'])) ? 0 : 1, (int)$a['id']);
                $pb = array($b['id_rate'] == $rate['id'] ? 1 : 0, (is_null($b['date_from']) && is_null($b['date_to'])) ? 0 : 1, (int)$b['id']);
                return $pa < $pb ? -1 : ($pa > $pb ? 1 : 0);
            });
            $days = array();
            foreach($rows as $o) {
                $rule = self::_childRule($o);
                foreach($this->_days as $date => $i) {
                    if((is_null($o['date_from']) || $date >= $o['date_from']) && (is_null($o['date_to']) || $date <= $o['date_to'])) {
                        $days[$i] = $rule;
                    }
                }
            }
            $groups[$j] = array('rule' => self::_childRule($g), 'days' => $days);
        }
        return array('min_age' => $minAge, 'age_group' => $ageGroup, 'groups' => $groups);
    }

    /**
     * array(bed_type, bed_value, extra_type, extra_value); value: фикс — копейки, процент — базисные пункты.
     */
    protected static function _childRule(array $row) {
        return array((int)$row['bed_type'], max(0, (int)self::toMinor($row['bed_value'])),
            (int)$row['extra_type'], max(0, (int)self::toMinor($row['extra_value'])));
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
