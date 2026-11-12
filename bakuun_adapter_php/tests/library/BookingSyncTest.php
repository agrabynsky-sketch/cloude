<?php
class BookingSyncTest extends Test_TestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->givenMappedProperty();
    }

    protected function stored($id = 'UT-9F3A21')
    {
        return $this->bookings->find($id);
    }

    public function testNewBookingIsPreBookedConfirmedAndAcknowledged()
    {
        $result = $this->sync(array('ackWithDetails' => true))->process(Test_Fixtures::reservation(array(), '2026-07-10'));

        $this->assertSame(Bakuun_BookingSync::DELIVERED, $result);
        $prebook = $this->bakuun->requestsOf('getBooking');
        $this->assertCount(2, $prebook);
        $reservation = $prebook[0]['body']['hotelReservation'][0];
        $this->assertSame('HTL1.1', $reservation['hotelCode']);
        $this->assertSame('New', $reservation['resStatus']);
        $this->assertSame('2026-07-10T14:00', $reservation['checkIn']);
        $this->assertSame('2026-07-13T12:00', $reservation['checkOut']);
        $this->assertSame('VCC', $reservation['paymentMethod']);
        $this->assertSame('BAR', $reservation['rateType']);
        $this->assertSame(array('2', '1', array(6), '1', array(0)), array(
            $reservation['totalAdult'], $reservation['totalChild'], $reservation['child_ages'], $reservation['totalBaby'], $reservation['baby_ages'],
        ));
        $stay = $reservation['roomStays'][0];
        $this->assertSame(array('RX.1.1', 'Deluxe room', 'RX.1.1.1', 'Refundable', '15.00'), array(
            $stay['roomTypeId'], $stay['roomName'], $stay['ratePlanId'], $stay['cancelPolicy'], $stay['commission'],
        ));
        $this->assertSame(array(
            array('dateYmd' => '2026-07-10', 'price' => '225.00'),
            array('dateYmd' => '2026-07-11', 'price' => '225.00'),
            array('dateYmd' => '2026-07-12', 'price' => '225.00'),
        ), $stay['bookingPerDayPrices']);
        $this->assertSame(array('amountAfterTaxes' => '675.00', 'amountBeforeTaxes' => '675.00'), $stay['total']);
        $this->assertSame(array('ResGuestRPH' => '1', 'firstName' => 'Ivan', 'lastName' => 'Petrenko', 'middleName' => '',
            'email' => 'ivan@example.com', 'phone' => '+380671234567', 'address' => '', 'country' => 'UA'), $reservation['guestDetails'][0]);
        $this->assertSame('4111111111111111', $reservation['paymentCard']['cardNumber']);
        $this->assertSame('11', $reservation['paymentCard']['ExpiryMonth']);
        $this->assertArrayNotHasKey('cvc', $reservation['paymentCard']);
        $this->assertContains('Unit.Travel reservation UT-9F3A21', $reservation['Comment']['Text']);

        $confirm = $prebook[1]['body']['hotelReservation'][0];
        $this->assertSame(array('HTL1.1', 'BTC1', 'UT-9F3A21'), array($confirm['hotelCode'], $confirm['BookingTokenCode'], $confirm['resID']));

        $row = $this->stored();
        $this->assertSame(Bakuun_Store_Bookings::DELIVERED, $row['state']);
        $this->assertSame(array('UT-9F3A21-1'), Bakuun_Store_Bookings::bakuunResIds($row));
        $this->assertSame(array(array('UT-9F3A21', array(
            'external_reservation_code' => 'UT-9F3A21-1', 'status' => 'new', 'result' => 'accepted',
        ))), $this->ut->acks);
        $this->assertNotContains('4111111111111111', $row['payload']);
    }

    public function testModificationAndCancellation()
    {
        $sync = $this->sync();
        $sync->process(Test_Fixtures::reservation());

        $this->assertSame(Bakuun_BookingSync::DELIVERED, $sync->process(Test_Fixtures::reservation(array(
            'status' => 'modified', 'modified_at' => '2026-07-02T08:00:00Z', 'total_price' => 700,
            'rooms' => array(array(
                'room_code' => 'RX.1.1', 'room_id' => 4500, 'rate_plan_code' => 'RX.1.1.2', 'rate_plan_id' => 13501, 'price' => 700,
                'is_refundable' => false, 'occupancy' => array('adults' => 2),
            )),
        ))));
        $modify = $this->bakuun->requestsOf('pushModifyBooking');
        $this->assertCount(1, $modify);
        $reservation = $modify[0]['body']['hotelReservation'][0];
        $this->assertSame(array('Modify', 'UT-9F3A21', 'RX.1.1.2', 'Non Refundable'), array(
            $reservation['resStatus'], $reservation['resID'], $reservation['roomStays'][0]['ratePlanId'], $reservation['roomStays'][0]['cancelPolicy'],
        ));

        $this->assertSame(Bakuun_BookingSync::DELIVERED, $sync->process(Test_Fixtures::reservation(array(
            'status' => 'cancelled', 'modified_at' => '2026-07-03T08:00:00Z',
        ))));
        $cancel = $this->bakuun->requestsOf('pushCancelBooking');
        $this->assertSame(array('cancel', 'UT-9F3A21'), array($cancel[0]['body']['hotelReservation'][0]['resStatus'], $cancel[0]['body']['hotelReservation'][0]['resID']));
        $this->assertSame('cancelled', $this->bakuun->bookings['UT-9F3A21']['status']);
        $this->assertCount(3, $this->ut->acks);
    }

    public function testRepeatedDeliveryOfTheSameVersionIsIgnored()
    {
        $sync = $this->sync();
        $sync->process(Test_Fixtures::reservation());

        $this->assertSame(Bakuun_BookingSync::UNCHANGED, $sync->process(Test_Fixtures::reservation()));
        $this->assertCount(2, $this->bakuun->requestsOf('getBooking'));
    }

    public function testACancellationThatNeverReachedBakuunSendsNothing()
    {
        $result = $this->sync()->process(Test_Fixtures::reservation(array('status' => 'cancelled')));

        $this->assertSame(Bakuun_BookingSync::DELIVERED, $result);
        $this->assertCount(1, $this->bakuun->requestsOf('readSingleBookingRQ'));
        $this->assertSame(array(), $this->bakuun->requestsOf('pushCancelBooking'));
        $this->assertSame('UT-9F3A21', $this->ut->acks[0][0]);
    }

    public function testBakuunRefusingABookingIsFinal()
    {
        $this->bakuun->failures['prebook'] = array(
            array(400, array('success' => false, 'errors' => array(array('code' => 422, 'message' => 'Rooms not available')))),
        );

        $result = $this->sync(array('ackWithDetails' => true))->process(Test_Fixtures::reservation());

        $this->assertSame(Bakuun_BookingSync::REJECTED, $result);
        $row = $this->stored();
        $this->assertContains('Rooms not available', $row['last_error']);
        $this->assertSame('rejected', $this->ut->acks[0][1]['result']);
    }

    public function testBakuunBeingDownIsRetriedWithoutDoublingTheBooking()
    {
        $sync = $this->sync();
        $this->bakuun->loseConfirmAnswer = true;

        $this->assertSame(Bakuun_BookingSync::RETRY, $sync->process(Test_Fixtures::reservation()));
        $row = $this->stored();
        $this->assertSame(array(Bakuun_Store_Bookings::RETRY, '1'), array($row['state'], (string) $row['attempts']));
        $this->assertNotNull($row['next_attempt_at']);
        $this->assertSame(array(), $this->ut->acks);

        $this->bakuun->loseConfirmAnswer = false;
        $this->assertSame(Bakuun_BookingSync::DELIVERED, $sync->process(Test_Fixtures::reservation()));

        $this->assertCount(2, $this->bakuun->requestsOf('getBooking')); // no second pre-booking
        $this->assertCount(1, $this->bakuun->requestsOf('readSingleBookingRQ'));
        $this->assertSame(array('BK-UT-9F3A21'), Bakuun_Store_Bookings::bakuunResIds($this->stored()));
    }

    public function testAModificationOfABookingWhoseConfirmationWasLostIsSentAsAModification()
    {
        $sync = $this->sync();
        $this->bakuun->loseConfirmAnswer = true;
        $sync->process(Test_Fixtures::reservation());
        $this->bakuun->loseConfirmAnswer = false;

        $result = $sync->process(Test_Fixtures::reservation(array('status' => 'modified', 'modified_at' => '2026-07-02T08:00:00Z')));

        $this->assertSame(Bakuun_BookingSync::DELIVERED, $result);
        $this->assertCount(1, $this->bakuun->requestsOf('pushModifyBooking'));
    }

    public function testGivingUpAfterTooManyAttempts()
    {
        $this->bakuun->failures['prebook'] = array(
            array(503, array('success' => false, 'errors' => array(array('code' => 503, 'message' => 'Service unavailable')))),
            array(503, array('success' => false, 'errors' => array(array('code' => 503, 'message' => 'Service unavailable')))),
        );
        $sync = $this->sync(array('maxAttempts' => 2));

        $this->assertSame(Bakuun_BookingSync::RETRY, $sync->process(Test_Fixtures::reservation()));
        $this->assertSame(Bakuun_BookingSync::REJECTED, $sync->process(Test_Fixtures::reservation()));
        $row = $this->stored();
        $this->assertContains('gave up after 2 attempts', $row['last_error']);
    }

    public function testUnmappedRoomsAreRefused()
    {
        $result = $this->sync()->process(Test_Fixtures::reservation(array('rooms' => array(array(
            'room_id' => 7777, 'rate_plan_id' => 13500, 'price' => 675, 'occupancy' => array('adults' => 2),
        )))));

        $this->assertSame(Bakuun_BookingSync::REJECTED, $result);
        $row = $this->stored();
        $this->assertContains('room 7777 has no Bakuun mapping', $row['last_error']);
        $this->assertSame(array(), $this->bakuun->requestsOf('getBooking'));
    }

    public function testBookingsWaitForTheMappingToBeActivated()
    {
        $this->properties->save(Test_Fixtures::HOTEL, array('status' => Bakuun_Store_Properties::PENDING));

        $this->assertSame(Bakuun_BookingSync::RETRY, $this->sync()->process(Test_Fixtures::reservation()));
    }

    public function testRunPullsRetriesAndAcknowledges()
    {
        $this->ut->reservations[123] = array(Test_Fixtures::reservation(), Test_Fixtures::reservation(array('reservation_id' => 'UT-2')));
        $this->bakuun->failures['prebook'] = array(
            array(502, array('success' => false, 'errors' => array(array('code' => 502, 'message' => 'Bad gateway')))),
        );

        $counts = $this->sync()->run();

        $this->assertSame(1, $counts[Bakuun_BookingSync::DELIVERED]);
        $this->assertSame(1, $counts[Bakuun_BookingSync::RETRY]);
        $this->assertCount(1, $this->ut->acks);

        $counts = $this->sync()->run(); // the failed one is still unacknowledged, so it is pulled again

        $this->assertSame(1, $counts[Bakuun_BookingSync::DELIVERED]);
        $this->assertCount(2, $this->ut->acks);
    }

    public function testDueRetriesAreSentFromTheStoredCopyWithoutCardData()
    {
        $sync = $this->sync(array('pullReservations' => false));
        $this->bakuun->failures['prebook'] = array(
            array(502, array('success' => false, 'errors' => array(array('code' => 502, 'message' => 'Bad gateway')))),
        );
        $sync->process(Test_Fixtures::reservation());
        $this->db->update('bakuun_bookings', array('next_attempt_at' => '2000-01-01 00:00:00'), array('reservation_id = ?' => 'UT-9F3A21'));

        $counts = $sync->run();

        $this->assertSame(1, $counts[Bakuun_BookingSync::DELIVERED]);
        $prebooks = $this->bakuun->requestsOf('getBooking');
        $last = $prebooks[count($prebooks) - 2]['body']['hotelReservation'][0];
        $this->assertSame('', $last['paymentCard']['cardNumber']);
    }
}
