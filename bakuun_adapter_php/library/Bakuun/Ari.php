<?php
/**
 * Bakuun ARI pushes → Unit.Travel ARI message parts.
 *
 * Codes are translated through bakuun_mappings. Restrictions are stored per level (room or
 * rate plan) and date, and what goes to Unit.Travel is their combination for each rate plan,
 * because Unit.Travel only has rate-plan-level restrictions:
 *   stop sell, CTA, CTD     closed on either level
 *   min stay, min advance   the larger value
 *   max stay, max advance   the smaller limit (0 = none)
 * Only the fields a push touches are sent; Unit.Travel leaves the others unchanged.
 */
class Bakuun_Ari
{
    protected static $_flags = array(
        'masterclosedrq' => 'stop_sell',
        'arrivalclosedrq' => 'closed_to_arrival',
        'departureclosedrq' => 'closed_to_departure',
        'mastercondclosed' => 'stop_sell',
        'arrivalcondclosed' => 'closed_to_arrival',
        'departurecondclosed' => 'closed_to_departure',
    );

    protected static $_limitActions = array('setlosrq', 'setloscondrq', 'setadvbookingcondrq');

    protected static $_limitTypes = array(
        'setminlos' => 'min_stay',
        'setmaxlos' => 'max_stay',
        'minadvbooking' => 'min_advance_days',
        'minadvbookingarrival' => 'min_advance_days', // sic, Bakuun's name at rate plan level
        'maxadvbooking' => 'max_advance_days',
    );

    protected static $_priceActions = array('priceupdate' => false, 'hurdlespriceupdate' => false, 'occpriceupdate' => true);

    protected static $_unsupported = array(
        'sellthresholdupdate' => 'Sell thresholds are not supported by Unit.Travel',
        'setarrivallosrq' => 'Arrival-based length of stay is not supported by Unit.Travel',
        'setarrivalloscondrq' => 'Arrival-based length of stay is not supported by Unit.Travel',
        'getcancellation' => 'Cancellation and deposit policies cannot be updated through the Unit.Travel ARI API',
        'lospriceupdate' => 'Length-of-stay prices are not supported by Unit.Travel',
    );

    protected static $_defaults = array(
        // Unit.Travel ARI prices are net of taxes.
        'priceField' => 'amountBeforeTax',
        'rejectBarRates' => false,
    );

    protected $_mappings;
    protected $_restrictions;
    protected $_catalog;
    protected $_hotelId;
    protected $_options;
    /** @var array rate plan id => field => array('room' => room id, 'dates' => array(date => true)) */
    protected $_affected = array();
    protected $_warned = array();

    public function __construct(
        Bakuun_Store_Mappings $mappings, Bakuun_Store_Restrictions $restrictions, Bakuun_Catalog $catalog,
        $bakuunHotelId, array $options = array()
    ) {
        $this->_mappings = $mappings;
        $this->_restrictions = $restrictions;
        $this->_catalog = $catalog;
        $this->_hotelId = (string) $bakuunHotelId;
        $this->_options = array_merge(self::$_defaults, $options);
    }

    public static function isAriAction($action)
    {
        $key = strtolower((string) $action);
        return $key === 'invcountupdate' || isset(self::$_flags[$key]) || in_array($key, self::$_limitActions, true)
            || isset(self::$_priceActions[$key]) || isset(self::$_unsupported[$key]);
    }

    /**
     * @param  string $action  Bakuun action
     * @param  array  $payload normalized request body
     * @return UnitTravel_AriParts with Bakuun_Issue warnings
     * @throws Bakuun_Failure
     */
    public function translate($action, array $payload)
    {
        $parts = new UnitTravel_AriParts();
        $key = strtolower((string) $action);
        if (isset(self::$_unsupported[$key])) {
            $parts->warnings[] = new Bakuun_Issue(Bakuun_Code::UNSUPPORTED_VALUE, self::$_unsupported[$key] . '; ignored');
            return $parts;
        }
        if (isset(self::$_priceActions[$key])) {
            $this->_prices($payload, $parts, self::$_priceActions[$key]);
            return $parts;
        }
        $items = Bakuun_Payload::items($payload, 'data');
        if (!$items) {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, 'data is empty');
        }
        foreach ($items as $index => $item) {
            $where = "data[$index]";
            if ($key === 'invcountupdate') {
                $this->_availability($item, $where, $parts);
            } elseif (isset(self::$_flags[$key]) || in_array($key, self::$_limitActions, true)) {
                $this->_restriction($key, $item, $where, $parts);
            } else {
                throw Bakuun_Failure::of(Bakuun_Code::INVALID_ACTION, "Unknown action '$action'");
            }
        }
        $this->_flushRestrictions($parts);
        return $parts;
    }

    // Availability

    protected function _availability($item, $where, UnitTravel_AriParts $parts)
    {
        list($from, $to) = Bakuun_Payload::period($item);
        $roomId = $this->_room(Bakuun_Payload::string($item, 'roomCode'), $where, $parts);
        if ($roomId === null) {
            return;
        }
        $count = isset($item['roomCount']) ? $item['roomCount'] : null;
        if ($count === null) {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, "$where.roomCount is required");
        }
        if (Bakuun_Payload::isClear($count)) {
            // Unit.Travel has no "unset"; nothing to sell is the safe reading of a cleared count.
            $units = 0;
            $parts->warnings[] = new Bakuun_Issue(Bakuun_Code::UNSUPPORTED_VALUE, "$where: cleared roomCount sent as 0 rooms");
        } else {
            $units = $this->_int($count, "$where.roomCount");
        }
        $parts->availability[] = array('room_id' => $roomId, 'date_from' => $from, 'date_to' => $to, 'units' => $units);
    }

    // Restrictions

    protected function _restriction($key, $item, $where, UnitTravel_AriParts $parts)
    {
        list($from, $to) = Bakuun_Payload::period($item);
        if (isset(self::$_flags[$key])) {
            $field = self::$_flags[$key];
            $raw = isset($item['status']) ? $item['status'] : null;
            $value = $raw === null || Bakuun_Payload::isClear($raw) ? null : ($this->_bool($raw, "$where.status") ? 1 : 0);
        } else {
            $type = strtolower(str_replace(' ', '', (string) Bakuun_Payload::string($item, 'minMaxMessageType')));
            if (!isset(self::$_limitTypes[$type])) {
                throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATA, "$where.minMaxMessageType '$type' is not supported");
            }
            $field = self::$_limitTypes[$type];
            $raw = isset($item['Time']) ? $item['Time'] : (isset($item['time']) ? $item['time'] : null);
            $value = $raw === null || Bakuun_Payload::isClear($raw) ? null : $this->_int($raw, "$where.Time");
        }

        $condCode = Bakuun_Payload::string($item, 'condCode');
        if ($condCode !== null) {
            $plan = $this->_ratePlan(Bakuun_Payload::string($item, 'roomCode'), $condCode, $where, $parts);
            if ($plan === null) {
                return;
            }
            $this->_restrictions->set($this->_catalog->propertyId(), Bakuun_Store_Restrictions::RATE, $plan[1], $field, $from, $to, $value);
            $this->_touch($plan[1], $plan[0], $field, $from, $to);
            return;
        }
        $roomId = $this->_room(Bakuun_Payload::string($item, 'roomCode'), $where, $parts);
        if ($roomId === null) {
            return;
        }
        $this->_restrictions->set($this->_catalog->propertyId(), Bakuun_Store_Restrictions::ROOM, $roomId, $field, $from, $to, $value);
        foreach (array_keys($this->_catalog->ratePlansOfRoom($roomId)) as $ratePlanId) {
            $this->_touch($ratePlanId, $roomId, $field, $from, $to);
        }
    }

    protected function _touch($ratePlanId, $roomId, $field, $from, $to)
    {
        if (!isset($this->_affected[$ratePlanId][$field])) {
            $this->_affected[$ratePlanId][$field] = array('room' => $roomId, 'dates' => array());
        }
        foreach (Bakuun_Store_Restrictions::dates($from, $to) as $date) {
            $this->_affected[$ratePlanId][$field]['dates'][$date] = true;
        }
    }

    /**
     * Turn the touched rate plans and dates into Unit.Travel restriction items of combined values.
     */
    protected function _flushRestrictions(UnitTravel_AriParts $parts)
    {
        foreach ($this->_affected as $ratePlanId => $fields) {
            foreach ($fields as $field => $info) {
                $dates = array_keys($info['dates']);
                sort($dates);
                $first = $dates[0];
                $last = $dates[count($dates) - 1];
                $roomValues = $this->_restrictions->values(Bakuun_Store_Restrictions::ROOM, $info['room'], $field, $first, $last);
                $rateValues = $this->_restrictions->values(Bakuun_Store_Restrictions::RATE, $ratePlanId, $field, $first, $last);
                $run = null;
                foreach ($dates as $date) {
                    $value = self::_combine(
                        $field,
                        isset($roomValues[$date]) ? $roomValues[$date] : null,
                        isset($rateValues[$date]) ? $rateValues[$date] : null
                    );
                    if ($run !== null && $run['value'] === $value && self::_nextDay($run['to']) === $date) {
                        $run['to'] = $date;
                        continue;
                    }
                    if ($run !== null) {
                        $parts->restrictions[] = self::_restrictionItem($ratePlanId, $field, $run);
                    }
                    $run = array('from' => $date, 'to' => $date, 'value' => $value);
                }
                $parts->restrictions[] = self::_restrictionItem($ratePlanId, $field, $run);
            }
        }
        $this->_affected = array();
    }

    protected static function _combine($field, $room, $rate)
    {
        $values = array();
        foreach (array($room, $rate) as $value) {
            if ($value !== null) {
                $values[] = (int) $value;
            }
        }
        switch ($field) {
            case 'stop_sell':
            case 'closed_to_arrival':
            case 'closed_to_departure':
                return $values && max($values) > 0 ? 1 : 0;
            case 'min_stay':
                return $values ? max(1, max($values)) : 1;
            case 'min_advance_days':
                return $values ? max(0, max($values)) : 0;
            default: // max_stay, max_advance_days: the tighter of the set limits
                $limits = array();
                foreach ($values as $value) {
                    if ($value > 0) {
                        $limits[] = $value;
                    }
                }
                return $limits ? min($limits) : 0;
        }
    }

    protected static function _restrictionItem($ratePlanId, $field, array $run)
    {
        $isFlag = in_array($field, array('stop_sell', 'closed_to_arrival', 'closed_to_departure'), true);
        return array(
            'rate_plan_id' => (int) $ratePlanId,
            'date_from' => $run['from'],
            'date_to' => $run['to'],
            $field => $isFlag ? $run['value'] === 1 : $run['value'],
        );
    }

    protected static function _nextDay($date)
    {
        $day = new DateTime($date, new DateTimeZone('UTC'));
        $day->modify('+1 day');
        return $day->format('Y-m-d');
    }

    // Prices

    protected function _prices(array $payload, UnitTravel_AriParts $parts, $byOccupancy)
    {
        list($from, $to) = Bakuun_Payload::period($payload);
        $rates = Bakuun_Payload::items($payload, 'dailyRates');
        if (!$rates) {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, 'dailyRates is empty');
        }
        foreach ($rates as $index => $rate) {
            $where = "dailyRates[$index]";
            $condCode = Bakuun_Payload::string($rate, 'condCode');
            if ($condCode === null) {
                throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, "$where.condCode is required");
            }
            $plan = $this->_ratePlan(Bakuun_Payload::string($rate, 'roomCode'), $condCode, $where, $parts);
            if ($plan === null) {
                continue;
            }
            $room = $this->_catalog->room($plan[0]);
            if ($room === null) {
                $parts->warnings[] = new Bakuun_Issue(Bakuun_Code::INVALID_ROOM_MAPPING, "$where: room {$plan[0]} no longer exists in Unit.Travel; skipped");
                continue;
            }
            $item = array(
                'room_id' => $plan[0], 'rate_plan_id' => $plan[1], 'date_from' => $from, 'date_to' => $to,
                'currency' => $this->_currency($rate, $plan[1], $where),
            );
            $prices = $byOccupancy ? $this->_occupancyAmounts($rate, $where, $parts) : $this->_roomAmount($rate, $where, $parts);
            if (!$prices) {
                continue;
            }
            if (Bakuun_Catalog::isPerGuest($room)) {
                $occupancyPrices = $this->_perGuest($prices, $room, $byOccupancy, $where, $parts);
                if (!$occupancyPrices) {
                    continue;
                }
                $item['pricing_model'] = 'per_guest';
                $item['occupancy_prices'] = $occupancyPrices;
            } else {
                $base = Bakuun_Catalog::baseOccupancy($room);
                $cents = $byOccupancy ? (isset($prices[$base]) ? $prices[$base] : null) : $prices[0];
                if ($cents === null) {
                    $parts->warnings[] = new Bakuun_Issue(
                        Bakuun_Code::OCCUPANCY_NOT_ALLOWED, "$where: no price for $base adults, the room's base occupancy; skipped"
                    );
                    continue;
                }
                $item['pricing_model'] = 'per_room';
                $item['price'] = UnitTravel_Values::centsToNumber($cents);
            }
            $parts->rates[] = $item;
        }
    }

    /**
     * @return int[] array(0 => cents) or an empty array when the price is cleared
     */
    protected function _roomAmount(array $rate, $where, UnitTravel_AriParts $parts)
    {
        $this->_checkRateType(Bakuun_Payload::string($rate, 'rateType'), $where);
        $cents = $this->_amount($rate, $where, $parts);
        return $cents === null ? array() : array($cents);
    }

    /**
     * @return int[] adults => cents, for the plain adult occupancies
     */
    protected function _occupancyAmounts(array $rate, $where, UnitTravel_AriParts $parts)
    {
        $amounts = array();
        foreach (Bakuun_Payload::items($rate, 'priceDetails') as $index => $detail) {
            $at = "$where.priceDetails[$index]";
            $this->_checkRateType(Bakuun_Payload::string($detail, 'rateType'), $at);
            $occupancy = isset($detail['occupancy']) && is_array($detail['occupancy']) ? $detail['occupancy'] : array();
            $adults = $this->_int(isset($occupancy['adultCount']) ? $occupancy['adultCount'] : null, "$at.occupancy.adultCount");
            if ($adults < 1) {
                throw Bakuun_Failure::of(Bakuun_Code::OCCUPANCY_NOT_ALLOWED, "$at.occupancy.adultCount must be at least 1");
            }
            if (self::_hasExtraGuests($occupancy)) {
                $this->_warnOnce($parts, 'extras', new Bakuun_Issue(
                    Bakuun_Code::UNSUPPORTED_VALUE, 'Child, infant and extra adult prices are not supported by Unit.Travel ARI; ignored'
                ));
                continue;
            }
            $cents = $this->_amount($detail, $at, $parts);
            if ($cents !== null) {
                $amounts[$adults] = $cents;
            }
        }
        ksort($amounts);
        return $amounts;
    }

    /**
     * Occupancy prices for a per_guest room; a per-room price applies to every occupancy.
     */
    protected function _perGuest(array $prices, array $room, $byOccupancy, $where, UnitTravel_AriParts $parts)
    {
        $most = Bakuun_Catalog::maxAdults($room);
        $result = array();
        if (!$byOccupancy) {
            for ($adults = 1; $adults <= $most; $adults++) {
                $result[] = array('occupancy' => $adults, 'price' => UnitTravel_Values::centsToNumber($prices[0]));
            }
            return $result;
        }
        foreach ($prices as $adults => $cents) {
            if ($adults > $most) {
                $parts->warnings[] = new Bakuun_Issue(
                    Bakuun_Code::OCCUPANCY_NOT_ALLOWED, "$where: $adults adults exceed the room's maximum of $most; skipped"
                );
                continue;
            }
            $result[] = array('occupancy' => $adults, 'price' => UnitTravel_Values::centsToNumber($cents));
        }
        return $result;
    }

    protected static function _hasExtraGuests(array $occupancy)
    {
        foreach (array('childCount', 'infantCount', 'additionalAdultCount') as $key) {
            if (!isset($occupancy[$key])) {
                continue;
            }
            if (is_scalar($occupancy[$key]) && (int) $occupancy[$key] > 0) {
                return true;
            }
            foreach (Bakuun_Payload::items($occupancy, $key) as $group) {
                if (is_array($group) && isset($group['count']) && (int) $group['count'] > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return int|null cents, or null when Bakuun clears the price (Unit.Travel cannot)
     */
    protected function _amount(array $data, $where, UnitTravel_AriParts $parts)
    {
        $field = $this->_options['priceField'];
        $raw = isset($data[$field]) ? $data[$field] : null;
        if ($raw === null || $raw === '') {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, "$where.$field is required");
        }
        if (Bakuun_Payload::isClear($raw)) {
            $parts->warnings[] = new Bakuun_Issue(Bakuun_Code::UNSUPPORTED_VALUE, "$where: a price cannot be cleared in Unit.Travel; skipped");
            return null;
        }
        try {
            $cents = UnitTravel_Values::parseCents($raw);
        } catch (InvalidArgumentException $e) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATA, "$where.$field: " . $e->getMessage());
        }
        if ($cents < 0) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATA, "$where.$field must not be negative");
        }
        return $cents;
    }

    protected function _checkRateType($rateType, $where)
    {
        if ($this->_options['rejectBarRates'] && $rateType !== null && strpos(strtoupper($rateType), 'BAR') !== false) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_RATE_TYPE, "$where: BAR rates are not accepted, send NET rates");
        }
    }

    protected function _currency(array $rate, $ratePlanId, $where)
    {
        $currency = Bakuun_Payload::string($rate, 'currencyCode');
        if ($currency !== null) {
            return strtoupper($currency);
        }
        $plan = $this->_catalog->ratePlan($ratePlanId);
        if ($plan === null || empty($plan['currency'])) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATA, "$where.currencyCode is required");
        }
        return $plan['currency'];
    }

    // Mapping

    /**
     * @return int|null Unit.Travel room id, or null when the code is not mapped (skipped with a warning)
     */
    protected function _room($roomCode, $where, UnitTravel_AriParts $parts)
    {
        if ($roomCode === null) {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, "$where.roomCode is required");
        }
        $row = $this->_mappings->find($this->_hotelId, Bakuun_Store_Mappings::ROOM, $roomCode);
        if ($row === null) {
            $parts->warnings[] = new Bakuun_Issue(Bakuun_Code::INVALID_ROOM_MAPPING, "$where: room code '$roomCode' is not mapped; skipped");
            return null;
        }
        return (int) $row['ut_id'];
    }

    /**
     * @return int[]|null array(room id, rate plan id), or null when not mapped (skipped with a warning)
     */
    protected function _ratePlan($roomCode, $condCode, $where, UnitTravel_AriParts $parts)
    {
        $row = $this->_mappings->find($this->_hotelId, Bakuun_Store_Mappings::RATE_PLAN, $condCode);
        if ($row === null) {
            $parts->warnings[] = new Bakuun_Issue(Bakuun_Code::INVALID_RATE_PLAN_MAPPING, "$where: rate plan code '$condCode' is not mapped; skipped");
            return null;
        }
        $ratePlanId = (int) $row['ut_id'];
        $roomId = $row['ut_room_id'] !== null ? (int) $row['ut_room_id'] : null;
        if ($roomId === null) {
            $plan = $this->_catalog->ratePlan($ratePlanId);
            $roomId = $plan && isset($plan['room_id']) ? (int) $plan['room_id'] : null;
        }
        if ($roomId === null) {
            $parts->warnings[] = new Bakuun_Issue(Bakuun_Code::INVALID_RATE_PLAN_MAPPING, "$where: rate plan $ratePlanId no longer exists in Unit.Travel; skipped");
            return null;
        }
        if ($roomCode !== null) {
            $room = $this->_mappings->find($this->_hotelId, Bakuun_Store_Mappings::ROOM, $roomCode);
            if ($room !== null && (int) $room['ut_id'] !== $roomId) {
                throw Bakuun_Failure::of(
                    Bakuun_Code::INVALID_RATE_PLAN_MAPPING, "$where: rate plan '$condCode' is mapped to another room than '$roomCode'"
                );
            }
        }
        return array($roomId, $ratePlanId);
    }

    // Values

    protected function _int($value, $where)
    {
        try {
            $parsed = UnitTravel_Values::parseInt($value);
        } catch (InvalidArgumentException $e) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATA, "$where: " . $e->getMessage());
        }
        if ($parsed < 0) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATA, "$where must not be negative");
        }
        return $parsed;
    }

    protected function _bool($value, $where)
    {
        try {
            return UnitTravel_Values::parseBool($value);
        } catch (InvalidArgumentException $e) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATA, "$where: " . $e->getMessage());
        }
    }

    protected function _warnOnce(UnitTravel_AriParts $parts, $key, Bakuun_Issue $issue)
    {
        if (!isset($this->_warned[$key])) {
            $this->_warned[$key] = true;
            $parts->warnings[] = $issue;
        }
    }
}
