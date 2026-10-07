<?php

/**
 * Сборщик поискового кэша: MySQL (source of truth) -> строки unit_search.hotels_search_stay.
 *
 * Отель собирается ЦЕЛИКОМ: все его активные рум-рейты × все даты горизонта [start; start + HORIZON_DAYS].
 * Бизнес-правила (одинаковые с clickhouse/03_full_load_from_mysql.sql и эталоном tests/reference.php):
 *
 *  В поиск попадает рум-рейт: hotels.active = 1, hotels_rooms.active = 1, hotels_rates.active = 1, есть hotels_rates_rooms.
 *
 *  Базовая цена ночи (в копейках) — цена в календаре, она за base_occupancy гостей:
 *   - самостоятельный тариф (id_parent = 0): строка hotels_rates_prices с active = 1 и price IS NOT NULL, иначе ночь не продаётся;
 *   - производный тариф (id_parent > 0):
 *       своя строка с active = 0                      -> не продаётся;
 *       своя строка с price IS NOT NULL и derive_type = 0 -> своя цена («фиксированная сумма» в календаре);
 *       иначе цена родителя (тот же номер) × (100 + dv) / 100, где dv = своя derive_value при derive_type = 4,
 *       иначе -hotels_rates.derive_value (скидка тарифа); нет цены родителя -> не продаётся.
 *  Наличие номера: строка hotels_rooms_availability, иначе allotment номера, net_booked = 0, active = 1;
 *   ночь продаётся, если active = 1 и allotment - net_booked > 0.
 *  Цена номера за g гостей (g = 1..8) — окно «цены для другого числа гостей» (hotels_rates_occupancy, hotels_rates_occupancy_daily);
 *  hotels_rooms.pricing_model не используется: нет строк — одна цена за номер для любого числа гостей.
 *   Какие g продаются (gmask): g <= max_adults (0 -> max(max_occupancy, base_occupancy)), g <= max_occupancy, g <= 8
 *   и g не выключено (строка окна с active = 0).
 *   Самостоятельный тариф, цена ночи за g:
 *     дневная цена hotels_rates_occupancy_daily(rate_room, g, дата): price > 0 — цена ночи, price <= 0 — на g гостей
 *     в эту ночь не продаётся (в нарастающую сумму идёт CLOSED); нет дневной строки ->
 *     строка окна hotels_rates_occupancy(rate_room, g): amount_type 0 -> база × (100 + amount) / 100, 1 -> база + amount
 *     (не меньше 0); нет строки -> база.
 *   Производный тариф, цена ночи за g:
 *     своя дневная цена (0 — закрыто) > своя строка окна (от базовой цены производного) > как у родителя:
 *     цена родителя за g (его дневная цена, 0 — закрыто, или его строка окна от его базы) × (100 + dv) / 100. В ночь со
 *     своей ценой (derive_type = 0) строка окна родителя применяется к своей цене, дневные цены родителя не действуют.
 *     Выключенное у родителя g выключено и у производного, если у производного нет своей строки на это g.
 *   Закрытая ночь добавляет к c{g} CLOSED = 2^40: разность нарастающих сумм >= 2^40 — на g гостей проживание не продаётся.
 *   base_occupancy рум-рейта: hotels_rates_rooms.base_occupancy, NULL -> hotels_rooms.base_occupancy; у производного — как у родителя.
 *   Это только смысл цены в календаре (строки окна задаются относительно неё); в расчёт напрямую не входит.
 *  Вместимость: max_occupancy / max_adults / max_children — только существующие места; max_infants (NULL -> 255 = без лимита).
 *   Доп. кровати и детские кроватки (extra_beds, cots, cots_and_extra_beds) копируются для условий проживания и в расчёте не участвуют.
 *  Детская политика отеля (hotels_children_groups): age_group[возраст 0..17] = номер группы по порядку (age_from, id),
 *   при пересечении групп побеждает больший id, 0 = взрослый; child_type[j] = price_type (1..5), child_value[j] =
 *   price_value × 100 (фикс — копейки за ночь, процент — базисные пункты). Цену ребёнка считает поиск из цены номера.
 *   children_min_age: 255 = отель не принимает детей.
 *   children_as_adults («не дороже, чем взрослым»): hotels_rooms.children_as_adults, NULL -> hotels.children_as_adults
 *   (1 — платящие дети по цене взрослых, если так дешевле; 0 — всегда по детской политике).
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
    const MAX_GUESTS = 8;       // гостей в цене номера (колонки c1..c8)
    const MAX_ROOMS = 4;        // k2..k4: хватает ли номеров, когда в запросе до 4 номеров
    const MAX_CHILD_AGE = 17;   // age_group: возраста 0..17
    const NO_LIMIT = 255;       // max_infants без лимита / children_min_age «дети не принимаются»
    const CLOSED = 1099511627776;   // 2^40: ночь, где на g гостей не продаётся (дневная цена 0); сумма >= 2^40 = не продаётся

    // типы детской цены (hotels_children_groups.price_type)
    const CHILD_FREE = 1;
    const CHILD_FIXED = 2;           // фикс за ночь
    const CHILD_ROOM_PERCENT = 3;    // % тарифа на номер
    const CHILD_ADULT_PERCENT = 4;   // % цены взрослого (цена номера / число гостей)
    const CHILD_ADULT_FULL = 5;      // полная цена взрослого

    // тип строки окна по числу гостей (hotels_rates_occupancy.amount_type)
    const OCC_PERCENT = 0;
    const OCC_AMOUNT = 1;

    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;
    protected $_start;
    protected $_days = array();   // 'Y-m-d' => index

    public static $columns = array(
        'd', 'id_hotel', 'id_rate_room', 'id_room', 'id_rate', 'id_parent',
        'id_country', 'id_region', 'id_city', 'stars', 'id_currency',
        'id_board_type', 'id_cancel_policy', 'refundable', 'channel_mask', 'is_public', 'id_access_group',
        'id_room_type', 'base_occupancy', 'max_guests', 'gmask',
        'max_children', 'max_occupancy', 'max_infants', 'infants_excluded', 'children_min_age', 'age_group', 'child_type', 'child_value',
        'children_as_adults', 'extra_beds', 'cots', 'cots_and_extra_beds',
        'c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'k', 'k2', 'k3', 'k4',
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

        $hotels = $this->_assoc($db->fetchAll('SELECT id, stars, id_country, id_region, id_city, id_currency, allow_children, children_min_age,
            children_as_adults
            FROM hotels WHERE active = 1 AND id IN (' . implode(',', $hotelIds) . ')'));
        if(empty($hotels)) {
            return $built;
        }
        $hotelList = implode(',', array_keys($hotels));
        $rooms = $this->_assoc($db->fetchAll('SELECT id, id_hotel, id_type, allotment, base_occupancy, max_occupancy, max_adults, max_children,
            max_infants, is_without_infants, extra_beds, cots, cots_and_extra_beds, children_as_adults
            FROM hotels_rooms WHERE active = 1 AND id_hotel IN (' . $hotelList . ')'));
        $rates = $this->_assoc($db->fetchAll('SELECT id, id_hotel, id_parent, id_board_type, id_cancel_policy, min_los, min_adv,
            derive_value, channel_mask, visibility, access_group_id
            FROM hotels_rates WHERE active = 1 AND id_hotel IN (' . $hotelList . ')'));
        if(empty($rooms) || empty($rates)) {
            return $built;
        }
        $rateRooms = array();
        $byRateRoom = array();   // id_rate => id_room => id_rate_room
        foreach($db->fetchAll('SELECT id, id_rate, id_room, base_occupancy FROM hotels_rates_rooms
            WHERE id_room IN (' . implode(',', array_keys($rooms)) . ')') as $row) {
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
        // окно по числу гостей: [rate_room][guests] = array(active, amount_type, amount: проценты -> базисные пункты, сумма -> копейки)
        $occupancy = array();
        foreach($db->fetchAll('SELECT id_rate_room, guests, amount_type, amount, active FROM hotels_rates_occupancy
            WHERE id_rate_room IN (' . $rrIds . ') ORDER BY id') as $r) {
            $occupancy[$r['id_rate_room']][(int)$r['guests']] = array((int)$r['active'],
                self::OCC_AMOUNT == $r['amount_type'] ? self::OCC_AMOUNT : self::OCC_PERCENT, self::toMinor($r['amount']));
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

        // детская политика отеля
        $groups = array();          // id_hotel => array(группа, ...)
        foreach($db->fetchAll('SELECT id, id_hotel, age_from, age_to, price_type, price_value
            FROM hotels_children_groups WHERE active = 1 AND id_hotel IN (' . $hotelList . ') ORDER BY id') as $g) {
            $groups[$g['id_hotel']][] = $g;
        }
        $policies = array();        // id_hotel => TSV детской политики (одинаковая для всех рум-рейтов отеля)

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
            $daily = isset($occupancyDaily[$rrId]) ? $occupancyDaily[$rrId] : array();
            $parDaily = ($parentRr && isset($occupancyDaily[$parentRr])) ? $occupancyDaily[$parentRr] : array();

            // базовое размещение (у производного — как у родителя) и лимиты номера
            $rrBase = $parentId ? ($parentRr ? $rateRooms[$parentRr]['base_occupancy'] : null) : $rr['base_occupancy'];
            $baseOcc = max(1, (int)(is_null($rrBase) ? $room['base_occupancy'] : $rrBase));
            $maxAdults = (int)$room['max_adults'] > 0 ? (int)$room['max_adults'] : max((int)$room['max_occupancy'], $baseOcc);
            $maxOccupancy = (int)$room['max_occupancy'] > 0 ? (int)$room['max_occupancy'] : $maxAdults + (int)$room['max_children'];
            $guestsLimit = min(self::MAX_GUESTS, $maxAdults, $maxOccupancy);

            // какие g продаются и правило цены за g (статические для рум-рейта):
            //   array(true, тип, значение) — своя строка (или база) от базовой цены этого рум-рейта;
            //   array(false, тип, значение) — производный тариф без своей строки: как у родителя (тип/значение — строка родителя)
            $ownRows = isset($occupancy[$rrId]) ? $occupancy[$rrId] : array();
            $parRows = ($parentRr && isset($occupancy[$parentRr])) ? $occupancy[$parentRr] : array();
            $gmask = 0;
            $rules = array();
            for($g = 1; $g <= $guestsLimit; $g++) {
                if(isset($ownRows[$g])) {
                    if(!$ownRows[$g][0]) {
                        continue;
                    }
                    $rules[$g] = array(true, $ownRows[$g][1], $ownRows[$g][2]);
                } elseif($parentId) {
                    if(isset($parRows[$g]) && !$parRows[$g][0]) {
                        continue;
                    }
                    $rules[$g] = isset($parRows[$g]) ? array(false, $parRows[$g][1], $parRows[$g][2]) : array(false, self::OCC_PERCENT, 0);
                } else {
                    $rules[$g] = array(true, self::OCC_PERCENT, 0);
                }
                $gmask |= 1 << $g;
            }
            $maxGuests = empty($rules) ? 0 : max(array_keys($rules));
            if(!isset($policies[$room['id_hotel']])) {
                $policies[$room['id_hotel']] = $this->_childPolicy($hotel, isset($groups[$room['id_hotel']]) ? $groups[$room['id_hotel']] : array());
            }

            $static = array(
                (int)$room['id_hotel'], (int)$rrId, (int)$rr['id_room'], (int)$rr['id_rate'], $parentId,
                (int)$hotel['id_country'], (int)$hotel['id_region'], (int)$hotel['id_city'], (int)$hotel['stars'], (int)$hotel['id_currency'],
                (int)$rate['id_board_type'], (int)$rate['id_cancel_policy'], empty($rate['id_cancel_policy']) ? 0 : 1, (int)$rate['channel_mask'],
                'public' == $rate['visibility'] ? 1 : 0, (int)$rate['access_group_id'],
                (int)$room['id_type'], min(255, $baseOcc), $maxGuests, $gmask,
                min(255, max(0, (int)$room['max_children'])), min(255, $maxOccupancy),
                is_null($room['max_infants']) ? self::NO_LIMIT : min(254, max(0, (int)$room['max_infants'])),
                $room['is_without_infants'] ? 1 : 0, $policies[$room['id_hotel']],
                (is_null($room['children_as_adults']) ? (is_null($hotel['children_as_adults']) || $hotel['children_as_adults']) : $room['children_as_adults']) ? 1 : 0,
                min(255, max(0, (int)$room['extra_beds'])), min(255, max(0, (int)$room['cots'])), $room['cots_and_extra_beds'] ? 1 : 0,
            );
            $staticTsv = implode("\t", $static);
            $tail = "\t" . $ver . "\t0\n";
            $rateMinLos = (int)$rate['min_los'];
            $rateMinAdv = (int)$rate['min_adv'];
            $rateDerive = -(int)$rate['derive_value'];
            $sum = array(1 => 0, 0, 0, 0, 0, 0, 0, 0);
            $k = 0;
            $kq = array(2 => 0, 0, 0);                        // ночей, где свободно >= 2 / 3 / 4 номеров
            for($i = 0; $i < $nDates; $i++) {
                $o = isset($own[$i]) ? $own[$i] : null;
                // базовая цена ночи; у производного — ещё база родителя и процент (для цен по числу гостей «как у родителя»)
                $base = null;
                $parBase = null;
                $dv = 0;
                if(!$parentId) {
                    if($o && 1 == $o[9] && !is_null($o[0])) {
                        $base = $o[0];
                    }
                } elseif($o && 0 == $o[9]) {
                    $base = null;
                } elseif($o && !is_null($o[0]) && 0 == $o[1]) {
                    $base = $o[0];                                // своя цена ночи
                } elseif(isset($par[$i]) && 1 == $par[$i][9] && !is_null($par[$i][0])) {
                    $parBase = $par[$i][0];
                    $dv = ($o && 4 == $o[1]) ? $o[2] : $rateDerive;
                    $base = self::roundDiv($parBase * (100 + $dv), 100);
                }
                // room availability of the night
                $a = isset($av[$i]) ? $av[$i] : null;
                $free = ($a && !is_null($a[0]) ? $a[0] : (int)$room['allotment']) - ($a ? $a[1] : 0);
                $sell = (!$a || 1 == $a[2]) && $free > 0 && !is_null($base) && $base > 0;

                $minLos = ($o && !is_null($o[3])) ? $o[3] : $rateMinLos;
                $minAdv = ($o && !is_null($o[5])) ? $o[5] : $rateMinAdv;
                call_user_func($emit, $dates[$i] . "\t" . $staticTsv . "\t" . implode("\t", $sum) . "\t" . $k . "\t" . implode("\t", $kq)
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
                    // цены ночи за g гостей
                    $dayOwn = isset($daily[$i]) ? $daily[$i] : null;
                    $dayPar = (!is_null($parBase) && isset($parDaily[$i])) ? $parDaily[$i] : null;
                    foreach($rules as $g => $rule) {
                        if($dayOwn && isset($dayOwn[$g])) {
                            $p = $dayOwn[$g] > 0 ? $dayOwn[$g] : self::CLOSED;          // своя дневная цена, 0 — закрыто
                        } elseif($rule[0] || is_null($parBase)) {
                            $p = self::applyOccupancy($base, $rule[1], $rule[2]);     // своя строка / база; своя цена ночи + строка родителя
                        } elseif($dayPar && isset($dayPar[$g])) {
                            // как у родителя: его дневная цена × (100 + dv) / 100, 0 — закрыто и у производного
                            $p = $dayPar[$g] > 0 ? self::roundDiv($dayPar[$g] * (100 + $dv), 100) : self::CLOSED;
                        } else {
                            // как у родителя: его строка окна от его базы × (100 + dv) / 100
                            $p = self::roundDiv(self::applyOccupancy($parBase, $rule[1], $rule[2]) * (100 + $dv), 100);
                        }
                        $sum[$g] += $p;
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
            $zeros[] = in_array($column, array('age_group', 'child_type', 'child_value')) ? '[]' : '0';
        }
        $zeros = implode("\t", $zeros);
        foreach($rateRoomIds as $rrId) {
            foreach($this->_days as $date => $i) {
                call_user_func($emit, $date . "\t" . (int)$hotelId . "\t" . (int)$rrId . "\t" . $zeros . "\t" . $ver . "\t1\n");
            }
        }
    }

    /**
     * Цена ночи за g гостей по строке окна: процент (базисные пункты, 1500 = +15%) или сумма в копейках; не меньше 0.
     */
    public static function applyOccupancy($base, $type, $value) {
        if(self::OCC_AMOUNT == $type) {
            return max(0, $base + $value);
        }
        return 0 == $value ? $base : max(0, self::roundDiv($base * (10000 + $value), 10000));
    }

    /**
     * Детская политика отеля одной TSV-строкой колонок children_min_age, age_group, child_type, child_value.
     * Группы по порядку (age_from, id) — номер группы j = 1, 2, ...; пересечение возрастов: побеждает больший id.
     * Неизвестный тип цены считается «бесплатно».
     */
    protected function _childPolicy(array $hotel, array $groups) {
        $minAge = (isset($hotel['allow_children']) && !is_null($hotel['allow_children']) && !$hotel['allow_children'])
            ? self::NO_LIMIT : min(254, max(0, (int)$hotel['children_min_age']));
        usort($groups, function($a, $b) {
            return $a['age_from'] != $b['age_from'] ? $a['age_from'] - $b['age_from'] : $a['id'] - $b['id'];
        });
        $ageGroup = array_fill(0, self::MAX_CHILD_AGE + 1, 0);
        $ageGroupId = array_fill(0, self::MAX_CHILD_AGE + 1, 0);
        $types = array();
        $values = array();
        foreach($groups as $j => $g) {
            for($age = (int)$g['age_from']; $age <= min(self::MAX_CHILD_AGE, (int)$g['age_to']); $age++) {
                if($g['id'] > $ageGroupId[$age]) {
                    $ageGroupId[$age] = (int)$g['id'];
                    $ageGroup[$age] = $j + 1;
                }
            }
            $type = (int)$g['price_type'];
            $types[] = ($type >= self::CHILD_FREE && $type <= self::CHILD_ADULT_FULL) ? $type : self::CHILD_FREE;
            $values[] = min(4294967295, max(0, (int)self::toMinor($g['price_value'])));
        }
        return $minAge . "\t[" . implode(',', $ageGroup) . "]\t[" . implode(',', $types) . "]\t[" . implode(',', $values) . ']';
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
