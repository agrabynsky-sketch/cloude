<?php
/**
 * Helpers for reservations as Unit.Travel sends them (Reservation in openapi_3.json).
 */
class UnitTravel_Reservation
{
    /** UUIDv5 namespace for versions; the same as the Exely adapter uses. */
    const VERSION_NAMESPACE = '6d54ebb4-fcb1-4ff6-9ced-b4dc46858db7';

    protected static $_required = array('reservation_id', 'property_id', 'status', 'checkin', 'checkout', 'currency');

    /**
     * @param  mixed $reservation
     * @throws UnitTravel_InvalidReservationException
     */
    public static function assertValid($reservation)
    {
        if (!is_array($reservation)) {
            throw new UnitTravel_InvalidReservationException('reservation must be an object');
        }
        foreach (self::$_required as $field) {
            if (!isset($reservation[$field]) || !is_scalar($reservation[$field]) || (string) $reservation[$field] === '') {
                throw new UnitTravel_InvalidReservationException("reservation field $field is required");
            }
        }
        if (!ctype_digit((string) $reservation['property_id'])) {
            throw new UnitTravel_InvalidReservationException('reservation property_id must be an integer');
        }
        self::date($reservation['checkin']);
        self::date($reservation['checkout']);
    }

    /**
     * A token that changes whenever Unit.Travel changes the reservation.
     */
    public static function version(array $reservation)
    {
        $stamp = self::field($reservation, 'modified_at', self::field($reservation, 'created_at', ''));
        $key = $reservation['reservation_id'] . '|' . self::field($reservation, 'status', '') . '|' . $stamp;
        return UnitTravel_Uuid::v5(self::VERSION_NAMESPACE, $key);
    }

    /**
     * The reservation without card data: what may be stored.
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
     * @param  string $value Y-m-d (a time part is ignored)
     * @return DateTime midnight UTC
     * @throws UnitTravel_InvalidReservationException
     */
    public static function date($value)
    {
        $value = substr((string) $value, 0, 10);
        $date = DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new UnitTravel_InvalidReservationException("invalid date '$value'");
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

    public static function field(array $data, $name, $default)
    {
        return isset($data[$name]) ? $data[$name] : $default;
    }
}
