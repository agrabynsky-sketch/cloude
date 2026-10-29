<?php
/**
 * Unit.Travel reservations → Exely get-bookings entries.
 */
class Exely_Bookings
{
    /** Same namespace as the Python adapter, so both produce the same echoTokens. */
    const VERSION_NAMESPACE = '6d54ebb4-fcb1-4ff6-9ced-b4dc46858db7';
    const MAX_PAYMENT_COMMENT = 70;

    protected static $_paymentMethods = array(
        'prepaid' => 'PREPAY', 'deposit' => 'PREPAY', 'guarantee' => 'CREDIT', 'pay_at_hotel' => 'CASH',
    );

    protected static $_paymentLabels = array(
        'prepaid' => 'Prepaid', 'deposit' => 'Deposit', 'guarantee' => 'Guarantee', 'pay_at_hotel' => 'Pay at hotel',
    );

    protected static $_required = array('reservation_id', 'property_id', 'status', 'checkin', 'checkout', 'currency');

    /**
     * @param  mixed $reservation
     * @throws Exely_InvalidReservationException
     */
    public static function assertValid($reservation)
    {
        if (!is_array($reservation)) {
            throw new Exely_InvalidReservationException('reservation must be an object');
        }
        foreach (self::$_required as $field) {
            if (!isset($reservation[$field]) || !is_scalar($reservation[$field]) || (string) $reservation[$field] === '') {
                throw new Exely_InvalidReservationException("reservation field $field is required");
            }
        }
        if (!ctype_digit((string) $reservation['property_id'])) {
            throw new Exely_InvalidReservationException('reservation property_id must be an integer');
        }
        self::date($reservation['checkin']);
        self::date($reservation['checkout']);
    }

    /**
     * The echoToken: changes whenever Unit.Travel changes the reservation.
     */
    public static function version(array $reservation)
    {
        $stamp = self::_field($reservation, 'modified_at', self::_field($reservation, 'created_at', ''));
        $key = $reservation['reservation_id'] . '|' . self::_field($reservation, 'status', '') . '|' . $stamp;
        return Exely_Uuid::v5(self::VERSION_NAMESPACE, $key);
    }

    /**
     * Drop card data except the brand: Exely has no card fields, so the adapter never needs it.
     */
    public static function stripCard(array $reservation)
    {
        if (!isset($reservation['payment']) || !is_array($reservation['payment'])
            || empty($reservation['payment']['card'])
        ) {
            return $reservation;
        }
        $card = $reservation['payment']['card'];
        $reservation['payment']['card'] = array('brand' => is_array($card) && isset($card['brand']) ? $card['brand'] : null);
        return $reservation;
    }

    /**
     * @param  array  $res     a validated reservation
     * @param  string $version
     * @return array
     */
    public static function toExely(array $res, $version)
    {
        $checkin = self::date($res['checkin']);
        $checkout = self::date($res['checkout']);
        $nights = max((int) $checkin->diff($checkout)->format('%r%a'), 1);
        $rooms = isset($res['rooms']) && is_array($res['rooms']) ? array_values($res['rooms']) : array();
        $totals = self::_roomTotals($res, $rooms);
        $commissions = self::_commissions($res, $totals);
        $payment = isset($res['payment']) && is_array($res['payment']) ? $res['payment'] : null;
        $paymentType = $payment ? self::_field($payment, 'type', null) : null;

        $stays = array();
        foreach ($rooms as $index => $room) {
            $stays[] = self::_roomStay($res, $index, $room, $totals[$index], $commissions[$index], $nights, $checkin);
        }
        $booking = array(
            'number' => (string) $res['reservation_id'],
            'echoToken' => $version,
            'status' => $res['status'],
            'hotelId' => (string) $res['property_id'],
            'arrivalDate' => $checkin->format('Y-m-d'),
            'departureDate' => $checkout->format('Y-m-d'),
            'currencyCode' => $res['currency'],
            'paymentMethod' => isset(self::$_paymentMethods[$paymentType]) ? self::$_paymentMethods[$paymentType] : 'CASH',
            'paymentMethodComment' => self::_paymentComment($payment),
            'roomStays' => $stays,
            'customer' => self::_customer($res),
            'additionalInfo' => self::_additionalInfo($res, $rooms, $payment),
        );
        $created = self::utcDateTime(self::_field($res, 'created_at', self::_field($res, 'modified_at', null)));
        if ($created !== null) {
            $booking['created'] = $created;
        }
        if (!empty($res['special_request'])) {
            $booking['guestComment'] = $res['special_request'];
        }
        if ($paymentType === 'prepaid' && isset($res['total_price'])) {
            $booking['paymentInfo'] = array(
                'prepayment' => array('value' => Exely_Values::formatCents(Exely_Values::parseCents($res['total_price']))),
            );
        }
        return $booking;
    }

    /**
     * @param  string $value Y-m-d (a time part is ignored)
     * @return DateTime midnight UTC
     * @throws Exely_InvalidReservationException
     */
    public static function date($value)
    {
        $value = substr((string) $value, 0, 10);
        $date = DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new Exely_InvalidReservationException("invalid date '$value'");
        }
        return $date;
    }

    /**
     * @param  string|null $value ISO 8601 timestamp
     * @return string|null "Y-m-d H:i:s" in UTC
     */
    public static function utcDateTime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $moment = new DateTime((string) $value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
        $moment->setTimezone(new DateTimeZone('UTC'));
        return $moment->format('Y-m-d H:i:s');
    }

    protected static function _roomTotals(array $res, array $rooms)
    {
        $totals = array();
        $complete = true;
        foreach ($rooms as $room) {
            $complete = $complete && isset($room['price']);
            $totals[] = isset($room['price']) ? Exely_Values::parseCents($room['price']) : 0;
        }
        if ($complete || !isset($res['total_price']) || !$rooms) {
            return $totals;
        }
        return Exely_Values::splitCents(Exely_Values::parseCents($res['total_price']), count($rooms));
    }

    /**
     * The fee the property owes the channel, per room; null when it cannot be determined.
     */
    protected static function _commissions(array $res, array $totals)
    {
        $rateType = self::_field($res, 'rate_type', null);
        $unknown = array_fill(0, count($totals), null);
        if ($rateType === 'net') {
            return array_fill(0, count($totals), 0);
        }
        $commission = trim((string) self::_field($res, 'commission', ''));
        if ($rateType !== 'gross' || $commission === '' || !$totals) {
            return $unknown;
        }
        try {
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*%$/', $commission, $m)) {
                $hundredthsOfPercent = Exely_Values::parseCents($m[1]);
                $result = array();
                foreach ($totals as $total) {
                    $result[] = Exely_Values::divideRounded($total * $hundredthsOfPercent, 10000);
                }
                return $result;
            }
            if (preg_match('/^([\d\s.,]+?)\s*([A-Za-z]{3})?$/', $commission, $m)
                && (empty($m[2]) || strtoupper($m[2]) === strtoupper($res['currency']))
            ) {
                return Exely_Values::allocateCents(Exely_Values::parseCents($m[1]), $totals);
            }
        } catch (InvalidArgumentException $e) {
            return $unknown;
        }
        return $unknown;
    }

    protected static function _roomStay(array $res, $index, array $room, $total, $commission, $nights, DateTime $checkin)
    {
        $occupancy = isset($room['occupancy']) && is_array($room['occupancy']) ? $room['occupancy'] : array();
        $childrenAges = isset($occupancy['children_ages']) && is_array($occupancy['children_ages'])
            ? array_values($occupancy['children_ages'])
            : array();
        $guests = self::_stayGuests($res, $index, $childrenAges);
        if (isset($occupancy['adults'])) {
            $adults = (int) $occupancy['adults'];
        } else {
            $adults = 0;
            foreach ($guests as $guest) {
                $adults += $guest['isChild'] ? 0 : 1;
            }
            $adults = max($adults, 1);
        }
        // Unit.Travel has no nightly prices; Exely asks to split the total evenly then.
        $perDay = array();
        $night = clone $checkin;
        foreach (Exely_Values::splitCents($total, $nights) as $cents) {
            $perDay[] = array('dateYmd' => $night->format('Y-m-d'), 'price' => Exely_Values::formatCents($cents));
            $night->modify('+1 day');
        }
        $stay = array(
            'roomTypeId' => self::_reference($room, 'room_id', 'room_code'),
            'ratePlanId' => self::_reference($room, 'rate_plan_id', 'rate_plan_code'),
            'guests' => $guests,
            'adults' => $adults,
            'children' => count($childrenAges) + (isset($occupancy['infants']) ? (int) $occupancy['infants'] : 0),
            'bookingPerDayPrices' => $perDay,
            'total' => array('amountAfterTaxes' => Exely_Values::formatCents($total)),
        );
        if ($commission !== null) {
            $stay['commission'] = Exely_Values::formatCents($commission);
        }
        return $stay;
    }

    protected static function _stayGuests(array $res, $index, array $childrenAges)
    {
        $guests = array();
        $listed = isset($res['guests']) && is_array($res['guests']) ? $res['guests'] : array();
        foreach ($listed as $guest) {
            if (!is_array($guest) || (int) self::_field($guest, 'room_index', 0) !== $index) {
                continue;
            }
            $isChild = self::_field($guest, 'type', null) === 'child';
            $entry = array(
                'firstName' => (string) self::_field($guest, 'first_name', ''),
                'lastName' => (string) self::_field($guest, 'last_name', ''),
                'isChild' => $isChild,
            );
            if ($isChild && $childrenAges) {
                $entry['age'] = (int) array_shift($childrenAges);
            }
            $guests[] = $entry;
        }
        if (!$guests && !empty($res['primary_guest']) && is_array($res['primary_guest'])) {
            $guests[] = array(
                'firstName' => (string) self::_field($res['primary_guest'], 'first_name', ''),
                'lastName' => (string) self::_field($res['primary_guest'], 'last_name', ''),
                'isChild' => false,
            );
        }
        return $guests;
    }

    protected static function _customer(array $res)
    {
        $person = array();
        if (!empty($res['primary_guest']) && is_array($res['primary_guest'])) {
            $person = $res['primary_guest'];
        } elseif (!empty($res['guests'][0]) && is_array($res['guests'][0])) {
            $person = $res['guests'][0];
        }
        $customer = array(
            'firstName' => (string) self::_field($person, 'first_name', ''),
            'lastName' => (string) self::_field($person, 'last_name', ''),
        );
        foreach (array('email' => 'email', 'phone' => 'phone') as $from => $to) {
            if (!empty($person[$from])) {
                $customer[$to] = $person[$from];
            }
        }
        return $customer;
    }

    protected static function _paymentComment($payment)
    {
        $type = $payment ? self::_field($payment, 'type', null) : null;
        if (!$type) {
            return self::$_paymentLabels['pay_at_hotel'];
        }
        $parts = array(isset(self::$_paymentLabels[$type]) ? self::$_paymentLabels[$type] : $type);
        if (!empty($payment['method'])) {
            $parts[] = $payment['method'];
        }
        return self::_truncate(implode(', ', $parts), self::MAX_PAYMENT_COMMENT);
    }

    /**
     * What Exely has no field for, shown at the bottom of the property's confirmation.
     */
    protected static function _additionalInfo(array $res, array $rooms, $payment)
    {
        $channel = self::_field($res, 'channel', null);
        $lines = array('Unit.Travel reservation ' . $res['reservation_id'] . ($channel ? " ($channel)" : ''));
        $rateType = self::_field($res, 'rate_type', null);
        if ($rateType) {
            $commission = self::_field($res, 'commission', null);
            $lines[] = "Rate type: $rateType" . ($rateType === 'gross' && $commission ? ", commission $commission" : '');
        }
        foreach ($rooms as $index => $room) {
            $terms = self::_field($room, 'cancellation_policy', null);
            if (!$terms && isset($room['is_refundable']) && $room['is_refundable'] === false) {
                $terms = 'non-refundable';
            }
            if ($terms) {
                $lines[] = 'Room ' . ($index + 1) . " cancellation: $terms";
            }
        }
        if (isset($res['primary_guest']) && is_array($res['primary_guest']) && !empty($res['primary_guest']['nationality'])) {
            $lines[] = 'Guest nationality: ' . $res['primary_guest']['nationality'];
        }
        if ($payment && self::_field($payment, 'type', null) === 'deposit') {
            $lines[] = 'Deposit: the amount is not transmitted via Exely, see Unit.Travel';
        }
        if ($payment && isset($payment['card']) && is_array($payment['card'])) {
            $brand = !empty($payment['card']['brand']) ? $payment['card']['brand'] : 'card';
            $lines[] = "Payment card ($brand) provided: card details are not transmitted via Exely, see Unit.Travel";
        }
        return implode("\n", $lines);
    }

    protected static function _reference(array $room, $idField, $codeField)
    {
        if (isset($room[$idField])) {
            return (string) $room[$idField];
        }
        return (string) self::_field($room, $codeField, '');
    }

    protected static function _field(array $data, $name, $default)
    {
        return isset($data[$name]) ? $data[$name] : $default;
    }

    protected static function _truncate($text, $length)
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
