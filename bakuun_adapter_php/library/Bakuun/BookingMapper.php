<?php
/**
 * Unit.Travel reservation → Bakuun hotelReservation (Pre-Booking / Modify).
 */
class Bakuun_BookingMapper
{
    protected static $_defaults = array(
        'checkInTime' => '14:00',
        'checkOutTime' => '12:00',
        // Force one Bakuun payment method (B-MarketPlace bookings must be BANK); empty: derive it.
        'paymentMethod' => '',
        // Unit.Travel sends no tax breakdown; this is what Bakuun is told instead.
        'taxType' => 'VAT',
        'taxIncluded' => '0',
        'taxPrice' => '0.00',
        'taxDescription' => 'Taxes are not included in the price',
    );

    protected $_mappings;
    protected $_options;

    public function __construct(Bakuun_Store_Mappings $mappings, array $options = array())
    {
        $this->_mappings = $mappings;
        $this->_options = array_merge(self::$_defaults, $options);
    }

    /**
     * @param  array  $res       the Unit.Travel reservation (with card data when there is any)
     * @param  array  $property  the bakuun_properties row
     * @param  string $resStatus New or Modify
     * @return array
     * @throws Bakuun_Failure when a room or rate plan has no Bakuun code
     */
    public function hotelReservation(array $res, array $property, $resStatus)
    {
        $hotelId = $property['bakuun_hotel_id'];
        $checkin = UnitTravel_Reservation::date($res['checkin']);
        $checkout = UnitTravel_Reservation::date($res['checkout']);
        $nights = max((int) $checkin->diff($checkout)->format('%r%a'), 1);
        $rooms = isset($res['rooms']) && is_array($res['rooms']) ? array_values($res['rooms']) : array();
        $totals = $this->_roomTotals($res, $rooms);
        $rateType = UnitTravel_Reservation::field($res, 'rate_type', 'net') === 'gross' ? 'BAR' : 'NET';
        $payment = isset($res['payment']) && is_array($res['payment']) ? $res['payment'] : array();
        $paymentMethod = $this->_paymentMethod($payment);

        $stays = array();
        $guests = array();
        $allChildren = array();
        $allBabies = array();
        $totalAdults = 0;
        foreach ($rooms as $index => $room) {
            $occupancy = isset($room['occupancy']) && is_array($room['occupancy']) ? $room['occupancy'] : array();
            $children = isset($occupancy['children_ages']) && is_array($occupancy['children_ages']) ? array_map('intval', array_values($occupancy['children_ages'])) : array();
            $babies = isset($occupancy['infants']) ? array_fill(0, max(0, (int) $occupancy['infants']), 0) : array();
            $adults = isset($occupancy['adults']) ? (int) $occupancy['adults'] : 1;
            $rph = (string) ($index + 1);
            $stays[] = array(
                'roomTypeId' => $this->_code($hotelId, Bakuun_Store_Mappings::ROOM, $room, 'room_id', 'room_code'),
                'roomName' => $this->_roomName($hotelId, $room),
                'roomType' => 'Daily Room',
                'ratePlanId' => $this->_code($hotelId, Bakuun_Store_Mappings::RATE_PLAN, $room, 'rate_plan_id', 'rate_plan_code'),
                'cancelPolicy' => isset($room['is_refundable']) && $room['is_refundable'] === false ? 'Non Refundable' : 'Refundable',
                'numberOfUnits' => '1',
                'adultCount' => (string) $adults,
                'childCount' => (string) count($children),
                'child_ages' => $children,
                'babyCount' => (string) count($babies),
                'baby_ages' => $babies,
                'commission' => $this->_commission($res, $totals[$index]),
                'roomIndex' => $rph,
                'ResGuestRPH' => $rph,
                'bookingPerDayPrices' => self::_perDay($checkin, $totals[$index], $nights),
                'total' => array(
                    'amountAfterTaxes' => UnitTravel_Values::formatCents($totals[$index]),
                    'amountBeforeTaxes' => UnitTravel_Values::formatCents($totals[$index]),
                ),
                'roomIncServices' => array(),
            );
            $guests[] = $this->_guest($res, $index, $rph);
            $totalAdults += $adults;
            $allChildren = array_merge($allChildren, $children);
            $allBabies = array_merge($allBabies, $babies);
        }
        $total = UnitTravel_Values::formatCents(array_sum($totals));
        $reservation = array(
            'hotelCode' => $hotelId,
            'hotelName' => (string) $property['hotel_name'],
            'resStatus' => $resStatus,
            'checkIn' => $checkin->format('Y-m-d') . 'T' . $this->_options['checkInTime'],
            'checkOut' => $checkout->format('Y-m-d') . 'T' . $this->_options['checkOutTime'],
            'paymentMethod' => $paymentMethod,
            'rateType' => $rateType,
            'currencyCode' => $res['currency'],
            'totalAdult' => (string) $totalAdults,
            'totalChild' => (string) count($allChildren),
            'child_ages' => $allChildren,
            'totalBaby' => (string) count($allBabies),
            'baby_ages' => $allBabies,
            'roomStays' => $stays,
            'guestDetails' => $guests,
            'Comment' => array('Text' => $this->_comment($res)),
            'paymentCard' => self::_card($payment, $paymentMethod),
            'totalCharge' => array(array(
                'taxDetails' => array(
                    'typeTax' => $this->_options['taxType'],
                    'includes' => (string) $this->_options['taxIncluded'],
                    'taxPrice' => (string) $this->_options['taxPrice'],
                    'taxDetails' => $this->_options['taxDescription'],
                ),
                'priceDetails' => array(array('rateType' => $rateType, 'amountAfterTaxes' => $total, 'amountBeforeTaxes' => $total)),
            )),
        );
        if ($resStatus === 'Modify') {
            $reservation['resID'] = (string) $res['reservation_id'];
        }
        return $reservation;
    }

    /**
     * The Bakuun code of a room or rate plan: the reservation's own code when it is a mapped
     * Bakuun code (Unit.Travel echoes the codes set through /mappings), else the mapping of the id.
     */
    protected function _code($hotelId, $type, array $room, $idField, $codeField)
    {
        $code = isset($room[$codeField]) ? trim((string) $room[$codeField]) : '';
        if ($code !== '' && $this->_mappings->find($hotelId, $type, $code)) {
            return $code;
        }
        $mapped = isset($room[$idField]) ? $this->_mappings->bakuunCode($hotelId, $type, $room[$idField]) : null;
        if ($mapped === null) {
            $what = $type === Bakuun_Store_Mappings::ROOM ? 'room' : 'rate plan';
            throw Bakuun_Failure::of(
                $type === Bakuun_Store_Mappings::ROOM ? Bakuun_Code::INVALID_ROOM_MAPPING : Bakuun_Code::INVALID_RATE_PLAN_MAPPING,
                "Unit.Travel $what " . (isset($room[$idField]) ? $room[$idField] : $code) . " has no Bakuun mapping"
            );
        }
        return $mapped;
    }

    protected function _roomName($hotelId, array $room)
    {
        $code = $this->_code($hotelId, Bakuun_Store_Mappings::ROOM, $room, 'room_id', 'room_code');
        $row = $this->_mappings->find($hotelId, Bakuun_Store_Mappings::ROOM, $code);
        return $row && $row['name'] !== null ? (string) $row['name'] : $code;
    }

    protected function _roomTotals(array $res, array $rooms)
    {
        $totals = array();
        $complete = true;
        foreach ($rooms as $room) {
            $complete = $complete && isset($room['price']);
            $totals[] = isset($room['price']) ? UnitTravel_Values::parseCents($room['price']) : 0;
        }
        if ($complete || !isset($res['total_price']) || !$rooms) {
            return $totals;
        }
        return UnitTravel_Values::splitCents(UnitTravel_Values::parseCents($res['total_price']), count($rooms));
    }

    protected static function _perDay(DateTime $checkin, $total, $nights)
    {
        $prices = array();
        $night = clone $checkin;
        foreach (UnitTravel_Values::splitCents($total, $nights) as $cents) {
            $prices[] = array('dateYmd' => $night->format('Y-m-d'), 'price' => UnitTravel_Values::formatCents($cents));
            $night->modify('+1 day');
        }
        return $prices;
    }

    /**
     * Bakuun takes the commission as a percentage (0–99), or empty when there is none.
     */
    protected function _commission(array $res, $roomTotal)
    {
        if (UnitTravel_Reservation::field($res, 'rate_type', null) !== 'gross') {
            return '';
        }
        $text = trim((string) UnitTravel_Reservation::field($res, 'commission', ''));
        try {
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*%$/', $text, $m)) {
                return UnitTravel_Values::formatCents(UnitTravel_Values::parseCents($m[1]));
            }
            if (preg_match('/^([\d\s.,]+?)\s*([A-Za-z]{3})?$/', $text, $m) && $roomTotal > 0
                && (empty($m[2]) || strtoupper($m[2]) === strtoupper($res['currency']))
            ) {
                $amount = UnitTravel_Values::parseCents($m[1]);
                $total = isset($res['total_price']) ? UnitTravel_Values::parseCents($res['total_price']) : $roomTotal;
                return $total > 0 ? UnitTravel_Values::formatCents(UnitTravel_Values::divideRounded($amount * 10000, $total)) : '';
            }
        } catch (InvalidArgumentException $e) {
            return '';
        }
        return '';
    }

    protected function _paymentMethod(array $payment)
    {
        if ($this->_options['paymentMethod'] !== '') {
            return strtoupper($this->_options['paymentMethod']);
        }
        $method = strtoupper((string) UnitTravel_Reservation::field($payment, 'method', ''));
        $type = UnitTravel_Reservation::field($payment, 'type', null);
        if (strpos($method, 'VCC') !== false) {
            return 'VCC';
        }
        if ($type === 'guarantee' && !empty($payment['card'])) {
            return 'CC';
        }
        if ($type === 'pay_at_hotel') {
            return 'CASH';
        }
        return 'BANK';
    }

    protected static function _card(array $payment, $paymentMethod)
    {
        $card = isset($payment['card']) && is_array($payment['card']) ? $payment['card'] : array();
        $fields = array(
            'cardHolderName' => '', 'cardType' => '', 'cardCategory' => '', 'cardNumber' => '', 'ExpiryMonth' => '',
            'ExpiryYear' => '', 'StartDayMonth' => '', 'StartDayYear' => '', 'IssueNum' => '', 'seriesCode' => '',
            'vccBalance' => '', 'vccCurrencyCode' => '', 'vccDeactivationDate' => '', 'vccActivationDate' => '',
        );
        if (!in_array($paymentMethod, array('CC', 'VCC'), true) || empty($card['number'])) {
            return $fields;
        }
        $fields['cardHolderName'] = (string) UnitTravel_Reservation::field($card, 'holder', '');
        $fields['cardType'] = (string) UnitTravel_Reservation::field($card, 'brand', '');
        $fields['cardCategory'] = $paymentMethod;
        $fields['cardNumber'] = (string) $card['number'];
        $fields['ExpiryMonth'] = isset($card['expiry_month']) ? sprintf('%02d', (int) $card['expiry_month']) : '';
        $fields['ExpiryYear'] = isset($card['expiry_year']) ? (string) $card['expiry_year'] : '';
        return $fields;
    }

    protected function _guest(array $res, $index, $rph)
    {
        $person = null;
        foreach (isset($res['guests']) && is_array($res['guests']) ? $res['guests'] : array() as $guest) {
            if (is_array($guest) && (int) UnitTravel_Reservation::field($guest, 'room_index', 0) === $index
                && UnitTravel_Reservation::field($guest, 'type', 'adult') !== 'child'
            ) {
                $person = $guest;
                break;
            }
        }
        $primary = isset($res['primary_guest']) && is_array($res['primary_guest']) ? $res['primary_guest'] : array();
        if ($person === null || $index === 0) {
            $person = array_merge($person ? $person : array(), $primary);
        }
        return array(
            'ResGuestRPH' => $rph,
            'firstName' => (string) UnitTravel_Reservation::field($person, 'first_name', ''),
            'lastName' => (string) UnitTravel_Reservation::field($person, 'last_name', ''),
            'middleName' => '',
            'email' => (string) UnitTravel_Reservation::field($primary, 'email', ''),
            'phone' => (string) UnitTravel_Reservation::field($primary, 'phone', ''),
            'address' => '',
            'country' => (string) UnitTravel_Reservation::field($primary, 'nationality', ''),
        );
    }

    protected function _comment(array $res)
    {
        $lines = array();
        if (!empty($res['special_request'])) {
            $lines[] = $res['special_request'];
        }
        $lines[] = 'Unit.Travel reservation ' . $res['reservation_id'];
        foreach (isset($res['rooms']) && is_array($res['rooms']) ? array_values($res['rooms']) : array() as $index => $room) {
            if (!empty($room['cancellation_policy'])) {
                $lines[] = 'Room ' . ($index + 1) . ' cancellation: ' . $room['cancellation_policy'];
            }
        }
        return implode("\n", $lines);
    }
}
