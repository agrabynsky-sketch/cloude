<?php
class Test_Fixtures
{
    const HOTEL = 'HTL1.1';

    public static function rooms()
    {
        return array(
            array('id' => 4500, 'code' => 'DLX', 'name' => 'Deluxe Room', 'base_occupancy' => 2, 'pricing_model' => 'per_room',
                'max_adults' => 3, 'max_occupancy' => 4, 'max_infants' => 1),
            array('id' => 4501, 'code' => 'STD', 'name' => 'Стандарт', 'base_occupancy' => 2, 'pricing_model' => 'per_guest',
                'max_adults' => 3),
        );
    }

    public static function ratePlans()
    {
        return array(
            array('id' => 13500, 'code' => 'DLX-BB', 'name' => 'Deluxe BB Flexible', 'room_id' => 4500, 'board' => 'BB',
                'currency' => 'EUR', 'is_refundable' => true),
            array('id' => 13501, 'code' => 'DLX-RO-NR', 'name' => 'Deluxe RO Non-refundable', 'room_id' => 4500, 'board' => 'RO',
                'currency' => 'EUR', 'is_refundable' => false),
            array('id' => 13510, 'code' => 'STD-RO', 'name' => 'Стандарт без питания', 'room_id' => 4501, 'board' => 'RO',
                'currency' => 'EUR', 'is_refundable' => true),
        );
    }

    /**
     * Bakuun GetPropertyInfo propertyData of HTL1.1.
     */
    public static function bakuunProperty()
    {
        return array(
            'propertyName' => 'Hotel Test', 'country' => 'Ukraine', 'region' => 'Kyiv', 'city' => 'Kyiv', 'area' => 'Podil',
            'zipCode' => '04070', 'address' => 'Street 1', 'latitude' => '50.46', 'longitude' => '30.52', 'prefixPhone' => '+380',
            'phoneNum' => '441234567', 'currency' => 'EUR',
            'roomTypes' => array(
                array('roomTypeId' => 'RX.1.1', 'roomName' => 'Deluxe room', 'type' => 'DailyRoom', 'rateTypes' => array(
                    array('ratePlanId' => 'RX.1.1.1', 'authPerson' => '3', 'cancelType' => '1', 'defaultMeal' => 'BB', 'rateCategory' => '', 'restrictedCountry' => ''),
                    array('ratePlanId' => 'RX.1.1.2', 'authPerson' => '3', 'cancelType' => '0', 'defaultMeal' => 'RO', 'rateCategory' => '', 'restrictedCountry' => ''),
                )),
                array('roomTypeId' => 'RX.1.2', 'roomName' => 'СТАНДАРТ', 'type' => 'DailyRoom', 'rateTypes' => array(
                    array('ratePlanId' => 'RX.1.2.1', 'authPerson' => '3', 'cancelType' => '1', 'defaultMeal' => '0', 'rateCategory' => '', 'restrictedCountry' => ''),
                )),
            ),
        );
    }

    /**
     * Bakuun code → Unit.Travel id of HTL1.1, as onboarding stores them.
     */
    public static function mappings()
    {
        return array(
            array('room', 'RX.1.1', 4500, null, 'Deluxe room'),
            array('room', 'RX.1.2', 4501, null, 'СТАНДАРТ'),
            array('rate_plan', 'RX.1.1.1', 13500, 4500, null),
            array('rate_plan', 'RX.1.1.2', 13501, 4500, null),
            array('rate_plan', 'RX.1.2.1', 13510, 4501, null),
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
        return array_merge(array(
            'reservation_id' => 'UT-9F3A21',
            'property_id' => 123,
            'status' => 'new',
            'created_at' => '2026-07-01T09:59:00Z',
            'modified_at' => '2026-07-01T10:00:00Z',
            'checkin' => $arrival->format('Y-m-d'),
            'checkout' => $departure->format('Y-m-d'),
            'currency' => 'EUR',
            'total_price' => 675,
            'rate_type' => 'gross',
            'commission' => '15%',
            'channel' => 'web',
            'special_request' => 'Late arrival ~23:00',
            'rooms' => array(array(
                'room_code' => 'RX.1.1', 'room_id' => 4500, 'rate_plan_code' => 'RX.1.1.1', 'rate_plan_id' => 13500, 'board' => 'BB',
                'is_refundable' => true, 'cancellation_policy' => 'Free cancellation until 18:00 the day before arrival',
                'occupancy' => array('adults' => 2, 'children_ages' => array(6), 'infants' => 1), 'price' => 675,
            )),
            'primary_guest' => array(
                'first_name' => 'Ivan', 'last_name' => 'Petrenko', 'email' => 'ivan@example.com', 'phone' => '+380671234567', 'nationality' => 'UA',
            ),
            'guests' => array(
                array('first_name' => 'Ivan', 'last_name' => 'Petrenko', 'type' => 'adult', 'room_index' => 0),
                array('first_name' => 'Taras', 'last_name' => 'Petrenko', 'type' => 'child', 'room_index' => 0),
            ),
            'payment' => array(
                'type' => 'guarantee', 'method' => 'VCC',
                'card' => array('holder' => 'IVAN PETRENKO', 'number' => '4111111111111111', 'expiry_month' => 11, 'expiry_year' => 2027, 'cvc' => '123', 'brand' => 'VISA'),
            ),
        ), $overrides);
    }
}
