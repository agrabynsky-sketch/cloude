<?php
class BookingsTest extends PHPUnit_Framework_TestCase
{
    protected static function booking(array $overrides = array())
    {
        $raw = Exely_Bookings::stripCard(Test_Fixtures::reservation($overrides, '2026-07-10'));
        return Exely_Bookings::toExely($raw, Exely_Bookings::version($raw));
    }

    public function testMapsAReservation()
    {
        $result = self::booking();

        $this->assertSame('UT-9F3A21', $result['number']);
        $this->assertSame('new', $result['status']);
        $this->assertSame('123', $result['hotelId']);
        $this->assertSame('2026-07-01 09:59:00', $result['created']);
        $this->assertSame('2026-07-10', $result['arrivalDate']);
        $this->assertSame('2026-07-13', $result['departureDate']);
        $this->assertSame('EUR', $result['currencyCode']);
        $this->assertSame('CREDIT', $result['paymentMethod']);
        $this->assertSame('Guarantee, VCC', $result['paymentMethodComment']);
        $this->assertArrayNotHasKey('paymentInfo', $result);
        $this->assertSame('Late arrival ~23:00', $result['guestComment']);
        $this->assertSame(array(
            'firstName' => 'Ivan', 'lastName' => 'Petrenko', 'email' => 'ivan@example.com', 'phone' => '+380671234567',
        ), $result['customer']);

        $this->assertCount(1, $result['roomStays']);
        $stay = $result['roomStays'][0];
        $this->assertSame('4500', $stay['roomTypeId']);
        $this->assertSame('13500', $stay['ratePlanId']);
        $this->assertSame(2, $stay['adults']);
        $this->assertSame(1, $stay['children']);
        $this->assertSame(array('firstName' => 'Taras', 'lastName' => 'Petrenko', 'isChild' => true, 'age' => 6), $stay['guests'][2]);
        $this->assertSame(array(
            array('dateYmd' => '2026-07-10', 'price' => '225.00'),
            array('dateYmd' => '2026-07-11', 'price' => '225.00'),
            array('dateYmd' => '2026-07-12', 'price' => '225.00'),
        ), $stay['bookingPerDayPrices']);
        $this->assertSame(array('amountAfterTaxes' => '675.00'), $stay['total']);
        $this->assertSame('0.00', $stay['commission']); // net rate

        $this->assertContains('Guest nationality: UA', $result['additionalInfo']);
        $this->assertContains('Room 1 cancellation: Free cancellation', $result['additionalInfo']);
        $this->assertContains('card details are not transmitted', $result['additionalInfo']);
    }

    public function testCardDataIsStripped()
    {
        $raw = Exely_Bookings::stripCard(Test_Fixtures::reservation());

        $this->assertSame(array('brand' => 'VISA'), $raw['payment']['card']);
    }

    public function testNightlyPricesAddUpToTheRoomTotal()
    {
        $result = self::booking(array('rooms' => array(Test_Fixtures::room(array('price' => 100))), 'total_price' => 100));

        $prices = array();
        foreach ($result['roomStays'][0]['bookingPerDayPrices'] as $night) {
            $prices[] = $night['price'];
        }
        $this->assertSame(array('33.33', '33.33', '33.34'), $prices);
    }

    public function testGrossCommission()
    {
        $percent = self::booking(array('rate_type' => 'gross', 'commission' => '15%'));
        $this->assertSame('101.25', $percent['roomStays'][0]['commission']);

        $amount = self::booking(array('rate_type' => 'gross', 'commission' => '101.25 EUR'));
        $this->assertSame('101.25', $amount['roomStays'][0]['commission']);

        $unparseable = self::booking(array('rate_type' => 'gross', 'commission' => 'per contract'));
        $this->assertArrayNotHasKey('commission', $unparseable['roomStays'][0]);
        $this->assertContains('commission per contract', $unparseable['additionalInfo']);
    }

    public function testPrepaidBookingReportsThePrepayment()
    {
        $result = self::booking(array('payment' => array('type' => 'prepaid', 'method' => 'card online')));

        $this->assertSame('PREPAY', $result['paymentMethod']);
        $this->assertSame(array('prepayment' => array('value' => '675.00')), $result['paymentInfo']);
    }

    public function testMultiRoomBookingSplitsGuestsAndMissingPrices()
    {
        $room = Test_Fixtures::room(array('price' => null, 'occupancy' => array('adults' => 1)));
        $result = self::booking(array(
            'total_price' => 500,
            'rooms' => array($room, $room),
            'guests' => array(
                array('first_name' => 'A', 'last_name' => 'One', 'room_index' => 0),
                array('first_name' => 'B', 'last_name' => 'Two', 'room_index' => 1),
            ),
        ));

        $this->assertSame(array(array('firstName' => 'A', 'lastName' => 'One', 'isChild' => false)), $result['roomStays'][0]['guests']);
        $this->assertSame(array(array('firstName' => 'B', 'lastName' => 'Two', 'isChild' => false)), $result['roomStays'][1]['guests']);
        $this->assertSame(array('amountAfterTaxes' => '250.00'), $result['roomStays'][0]['total']);
        $this->assertSame(array('amountAfterTaxes' => '250.00'), $result['roomStays'][1]['total']);
    }

    public function testVersionChangesWithTheReservation()
    {
        $original = Test_Fixtures::reservation();

        $this->assertSame('7ba51887-cf31-50e5-b5a7-0a76ee4f50d9', Exely_Bookings::version($original));
        $this->assertNotEquals(
            Exely_Bookings::version($original),
            Exely_Bookings::version(array_merge($original, array('modified_at' => '2026-07-02T10:00:00Z')))
        );
        $this->assertNotEquals(
            Exely_Bookings::version($original),
            Exely_Bookings::version(array_merge($original, array('status' => 'cancelled')))
        );
    }

    public function testInvalidReservationsAreRejected()
    {
        $this->setExpectedException('Exely_InvalidReservationException');
        Exely_Bookings::assertValid(array_merge(Test_Fixtures::reservation(), array('checkout' => 'soon')));
    }
}
