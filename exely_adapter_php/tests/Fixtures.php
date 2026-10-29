<?php
class Test_Fixtures
{
    public static function rooms()
    {
        return array(
            array('id' => 4500, 'code' => 'DLX', 'name' => 'Deluxe Room', 'base_occupancy' => 2,
                'pricing_model' => 'per_room', 'max_adults' => 3),
            array('id' => 4501, 'code' => 'STD', 'name' => 'Standard Room', 'base_occupancy' => 2,
                'pricing_model' => 'per_guest', 'max_adults' => 3),
            array('id' => 4502, 'code' => 'NEW', 'name' => 'Room without rates', 'pricing_model' => 'per_room'),
        );
    }

    public static function ratePlans()
    {
        return array(
            array('id' => 13500, 'code' => 'DLX-BB', 'name' => 'Deluxe BB Flexible', 'room_id' => 4500,
                'board' => 'BB', 'currency' => 'EUR'),
            array('id' => 13510, 'code' => 'STD-RO', 'name' => 'Standard RO', 'room_id' => 4501,
                'board' => 'RO', 'currency' => 'EUR'),
        );
    }

    /**
     * A reservation as Unit.Travel sends it; check-in 10 days from now unless given.
     */
    public static function reservation(array $overrides = array(), $checkin = null, $nights = 3)
    {
        $arrival = new DateTime($checkin ? $checkin : '+10 days', new DateTimeZone('UTC'));
        $departure = clone $arrival;
        $departure->modify("+$nights days");
        $reservation = array(
            'reservation_id' => 'UT-9F3A21',
            'property_id' => 123,
            'status' => 'new',
            'created_at' => '2026-07-01T09:59:00Z',
            'modified_at' => '2026-07-01T10:00:00Z',
            'checkin' => $arrival->format('Y-m-d'),
            'checkout' => $departure->format('Y-m-d'),
            'currency' => 'EUR',
            'total_price' => 675,
            'rate_type' => 'net',
            'channel' => 'web',
            'special_request' => 'Late arrival ~23:00',
            'rooms' => array(self::room()),
            'primary_guest' => array(
                'first_name' => 'Ivan', 'last_name' => 'Petrenko', 'email' => 'ivan@example.com',
                'phone' => '+380671234567', 'nationality' => 'UA',
            ),
            'guests' => array(
                array('first_name' => 'Ivan', 'last_name' => 'Petrenko', 'type' => 'adult', 'room_index' => 0),
                array('first_name' => 'Olena', 'last_name' => 'Petrenko', 'type' => 'adult', 'room_index' => 0),
                array('first_name' => 'Taras', 'last_name' => 'Petrenko', 'type' => 'child', 'room_index' => 0),
            ),
            'payment' => array(
                'type' => 'guarantee',
                'method' => 'VCC',
                'card' => array('holder' => 'IVAN PETRENKO', 'number' => '4111111111111111', 'cvc' => '123', 'brand' => 'VISA'),
            ),
        );
        return array_merge($reservation, $overrides);
    }

    public static function room(array $overrides = array())
    {
        return array_merge(array(
            'room_code' => 'DLX',
            'room_id' => 4500,
            'rate_plan_code' => 'DLX-BB',
            'rate_plan_id' => 13500,
            'board' => 'BB',
            'is_refundable' => true,
            'cancellation_policy' => 'Free cancellation until 18:00 the day before arrival',
            'occupancy' => array('adults' => 2, 'children_ages' => array(6), 'infants' => 0),
            'price' => 675,
        ), $overrides);
    }
}
