<?php
class ServiceTest extends Test_TestCase
{
    protected static function update()
    {
        return array(
            'hotelId' => '123',
            'updates' => array(
                array('startDateYmd' => '2026-07-23', 'endDateYmd' => '2026-07-23', 'roomTypeId' => '4500', 'quota' => '6'),
                array(
                    'startDateYmd' => '2026-07-23',
                    'endDateYmd' => '2026-07-25',
                    'roomTypeId' => '4500',
                    'ratePlanId' => '13500',
                    'currencyCode' => 'EUR',
                    'prices' => array(array('price' => '15,500.00', 'code' => '4500_room')),
                    'closed' => 'false',
                    'minLos' => 2,
                ),
            ),
        );
    }

    // Envelope

    public function testRejectsWrongCredentials()
    {
        $response = $this->exely('get-bookings', array(), array('password' => 'wrong'));

        $this->assertFalse($response['success']);
        $this->assertSame(array(Exely_Code::INVALID_CREDENTIALS), self::errorCodes($response));
    }

    public function testRejectsEverythingWhenNoPasswordIsConfigured()
    {
        $this->service = $this->createService(array('exelyPassword' => ''));

        $response = $this->exely('get-bookings', array(), array('password' => ''));

        $this->assertSame(array(Exely_Code::INVALID_CREDENTIALS), self::errorCodes($response));
    }

    public function testRejectsUnknownAction()
    {
        $this->assertSame(array(Exely_Code::UNKNOWN_ACTION), self::errorCodes($this->exely('get-prices')));
    }

    public function testRejectsMalformedPayload()
    {
        $response = $this->service->handle(null);

        $this->assertFalse($response['success']);
        $this->assertSame(array(Exely_Code::INVALID_REQUEST), self::errorCodes($response));
    }

    // get-rooms-and-rate-plans

    public function testGetRoomsRequestsTheConnectionUntilTheHotelConfirms()
    {
        $pending = $this->exely('get-rooms-and-rate-plans', array('hotelId' => '123'));

        $this->assertSame(array(Exely_Code::HOTEL_NOT_CONNECTED), self::errorCodes($pending));
        $this->assertSame('pending', $this->fake->connections[123]['status']);

        $this->fake->activate(123);
        $response = $this->exely('get-rooms-and-rate-plans', array('hotelId' => '123'));

        $this->assertTrue($response['success']);
        $this->assertSame('123', $response['data']['hotelId']);
        $ids = array();
        foreach ($response['data']['roomsAndRatePlans'] as $room) {
            $ids[] = $room['roomTypeId'];
        }
        $this->assertSame(array('4500', '4501'), $ids);
        $this->assertSame(array(Exely_Code::ROOM_SKIPPED), self::warningCodes($response));
    }

    public function testGetRoomsForUnknownProperty()
    {
        $this->assertSame(
            array(Exely_Code::HOTEL_NOT_FOUND), self::errorCodes($this->exely('get-rooms-and-rate-plans', array('hotelId' => '999')))
        );
        $this->assertSame(
            array(Exely_Code::HOTEL_NOT_FOUND), self::errorCodes($this->exely('get-rooms-and-rate-plans', array('hotelid' => 'abc')))
        );
    }

    // update

    public function testUpdateIsSentAsOneAriMessage()
    {
        $this->fake->activate(123);

        $response = $this->exely('update', self::update());

        $this->assertSame(array('success' => true, 'errors' => array(), 'warnings' => array()), $response);
        $this->assertCount(1, $this->fake->ariMessages);
        $message = $this->fake->ariMessages[0];
        $this->assertSame(123, $message['property_id']);
        $this->assertStringStartsWith('exely-123-', $message['message_uid']);
        $this->assertInternalType('int', $message['revision']);
        $this->assertSame(
            array(array('room_id' => 4500, 'date_from' => '2026-07-23', 'date_to' => '2026-07-23', 'units' => 6)),
            $message['availability']
        );
        $this->assertEquals(15500, $message['rates'][0]['price']);
        $this->assertSame(array(array(
            'rate_plan_id' => 13500, 'date_from' => '2026-07-23', 'date_to' => '2026-07-25', 'stop_sell' => false, 'min_stay' => 2,
        )), $message['restrictions']);
    }

    public function testUpdateRevisionsIncrease()
    {
        $this->fake->activate(123);

        $this->exely('update', self::update());
        $this->exely('update', self::update());

        $this->assertGreaterThan($this->fake->ariMessages[0]['revision'], $this->fake->ariMessages[1]['revision']);
        $this->assertNotEquals($this->fake->ariMessages[0]['message_uid'], $this->fake->ariMessages[1]['message_uid']);
    }

    public function testUpdateIsSplitIntoSeveralMessages()
    {
        $this->fake->activate(123);
        $this->service = $this->createService(array('ariMaxItemsPerMessage' => 1));

        $this->assertTrue(Test_TestCase::isSuccess($this->exely('update', self::update())));
        $this->assertCount(3, $this->fake->ariMessages);
    }

    public function testUpdateReportsFailedProcessing()
    {
        $this->fake->activate(123);
        $this->fake->ariOutcome = array('status' => 'failed', 'errors' => array(array(
            'code' => 'unknown_code', 'message' => 'unknown rate plan',
            'details' => array(array('path' => 'rates[0].rate_plan_id', 'reason' => 'unknown')),
        )));

        $response = $this->exely('update', self::update());

        $this->assertFalse($response['success']);
        $this->assertSame(array(Exely_Code::INVALID_RATE_PLAN), self::errorCodes($response));
    }

    public function testUpdateMapsSynchronousValidationErrors()
    {
        $this->fake->activate(123);
        $this->fake->ariError = array(422, array(
            'code' => 'currency_mismatch', 'message' => 'currency differs', 'details' => array(array('path' => 'rates[0].currency')),
        ));

        $this->assertSame(array(Exely_Code::INVALID_CURRENCY), self::errorCodes($this->exely('update', self::update())));
    }

    public function testUpdatePassesRateLimitingOnToExely()
    {
        $this->fake->activate(123);
        $this->fake->ariError = array(429, array('code' => 'rate_limited', 'message' => 'slow down'), array('Retry-After' => '30'));

        $response = $this->exely('update', self::update());

        $this->assertSame(array(Exely_Code::RATE_LIMITED), self::errorCodes($response));
        $this->assertContains('30s', $response['errors'][0]['message']);
    }

    public function testUpdateSucceedsWithAWarningWhileStillProcessing()
    {
        $this->fake->activate(123);
        $this->fake->ariOutcome = array('status' => 'processing');

        $response = $this->exely('update', self::update());

        $this->assertTrue($response['success']);
        $this->assertSame(array(Exely_Code::STILL_PROCESSING), self::warningCodes($response));
    }

    public function testUpdateForAHotelThatIsNotConnected()
    {
        $this->fake->connections[123] = array('property_id' => 123, 'status' => 'pending');

        $this->assertSame(array(Exely_Code::HOTEL_NOT_CONNECTED), self::errorCodes($this->exely('update', self::update())));
        $this->assertSame(array(), $this->fake->ariMessages);
    }

    public function testUpdateDropsPartsTheConnectionMayNotSend()
    {
        $this->fake->activate(123, array('availability' => false));

        $response = $this->exely('update', self::update());

        $this->assertSame(array(Exely_Code::NOT_PERMITTED), self::warningCodes($response));
        $this->assertArrayNotHasKey('availability', $this->fake->ariMessages[0]);
    }

    public function testUpdateWhenUnitTravelIsUnreachable()
    {
        $this->fake->activate(123);
        $client = new Exely_UnitTravel_Client('http://127.0.0.1:9/partner/v1', 'key', array('timeout' => 2));
        $this->service = new Exely_Service(
            array('exelyUsername' => self::USERNAME, 'exelyPassword' => self::PASSWORD), $client, $this->store
        );

        $this->assertSame(array(Exely_Code::UPSTREAM_ERROR), self::errorCodes($this->exely('update', self::update())));
    }

    // Bookings

    public function testBookingFlowThroughTheWebhookStore()
    {
        $this->service = $this->createService(array('pullReservations' => false));

        $this->assertTrue($this->service->acceptReservation(Test_Fixtures::reservation()));
        $this->assertFalse($this->service->acceptReservation(Test_Fixtures::reservation())); // the same version again

        $bookings = $this->bookings();
        $this->assertCount(1, $bookings);
        $this->assertSame('UT-9F3A21', $bookings[0]['number']);

        $this->assertTrue(Test_TestCase::isSuccess($this->confirm($bookings[0])));
        $this->assertSame(array(array('UT-9F3A21', null)), $this->fake->acks);
        $this->assertSame(array(), $this->bookings());
    }

    public function testModifiedBookingIsQueuedAgain()
    {
        $this->service = $this->createService(array('pullReservations' => false));
        $this->service->acceptReservation(Test_Fixtures::reservation());
        $bookings = $this->bookings();
        $original = $bookings[0];
        $this->confirm($original);

        $this->service->acceptReservation(Test_Fixtures::reservation(array('status' => 'cancelled', 'modified_at' => '2026-07-02T08:00:00Z')));
        $this->service->acceptReservation(Test_Fixtures::reservation(array('status' => 'modified', 'modified_at' => '2026-07-01T12:00:00Z')));

        $queued = $this->bookings();
        $this->assertCount(1, $queued);
        $this->assertSame('cancelled', $queued[0]['status']); // the older, out-of-order modification was ignored
        $this->assertNotEquals($original['echoToken'], $queued[0]['echoToken']);
    }

    public function testConfirmationOfAnOlderVersionKeepsTheNewOneQueued()
    {
        $this->service = $this->createService(array('pullReservations' => false));
        $this->service->acceptReservation(Test_Fixtures::reservation());
        $bookings = $this->bookings();
        $this->service->acceptReservation(Test_Fixtures::reservation(array('status' => 'modified', 'modified_at' => '2026-07-02T08:00:00Z')));

        $this->confirm($bookings[0]);

        $queued = $this->bookings();
        $this->assertCount(1, $queued);
        $this->assertSame('modified', $queued[0]['status']);
    }

    public function testBookingsForAHotelIncludeConfirmedOnes()
    {
        $this->service = $this->createService(array('pullReservations' => false));
        $this->service->acceptReservation(Test_Fixtures::reservation());
        $bookings = $this->bookings();
        $this->confirm($bookings[0]);

        $forHotel = $this->bookings(array('hotelId' => '123'));
        $this->assertCount(1, $forHotel);
        $this->assertSame('UT-9F3A21', $forHotel[0]['number']);
        $this->assertSame(array(), $this->bookings(array('hotelId' => '456')));
    }

    public function testOldBookingsAreNoLongerRelevant()
    {
        $this->service = $this->createService(array('pullReservations' => false));
        $this->service->acceptReservation(Test_Fixtures::reservation(array(), '-70 days'));

        $this->assertSame(array(), $this->bookings());
    }

    public function testBookingsArePulledFromUnitTravel()
    {
        $this->fake->activate(123);
        $this->fake->reservations[123] = array(Test_Fixtures::reservation());

        $bookings = $this->bookings();
        $this->assertCount(1, $bookings);

        $this->confirm($bookings[0]);
        $this->assertSame('UT-9F3A21', $this->fake->acks[0][0]);
        $this->assertSame(array(), $this->bookings());
    }

    public function testCardDataIsNotStored()
    {
        $this->fake->activate(123);
        $this->fake->reservations[123] = array(Test_Fixtures::reservation());

        $this->bookings();

        $payload = $this->db->fetchOne('SELECT payload FROM exely_bookings');
        $this->assertNotContains('4111111111111111', $payload);
        $this->assertNotContains('"cvc"', $payload);
    }

    public function testAcknowledgeCarriesExelyDetailsWhenEnabled()
    {
        $this->service = $this->createService(array('pullReservations' => false, 'ackWithDetails' => true));
        $this->service->acceptReservation(Test_Fixtures::reservation());
        $bookings = $this->bookings();

        $this->confirm($bookings[0]);

        $this->assertSame(array(array('UT-9F3A21', array(
            'external_reservation_code' => '20241111-111111-22548963', 'status' => 'new', 'result' => 'accepted',
        ))), $this->fake->acks);
    }

    public function testBookingExelyDidNotCreateIsReportedAsRejected()
    {
        $this->service = $this->createService(array('pullReservations' => false, 'ackWithDetails' => true));
        $this->service->acceptReservation(Test_Fixtures::reservation());
        $bookings = $this->bookings();

        $this->confirm($bookings[0], null);

        $this->assertSame(array(array('UT-9F3A21', array(
            'external_reservation_code' => null, 'status' => 'new', 'result' => 'rejected',
        ))), $this->fake->acks);
        $this->assertSame(array(), $this->bookings());
    }

    public function testConfirmationOfAnUnknownBookingIsAWarning()
    {
        $response = $this->exely('confirm-bookings', array('confirmBookings' => array(
            array('number' => 'nope', 'externalNumber' => '1', 'status' => 'new'),
        )));

        $this->assertTrue($response['success']);
        $this->assertSame(array(Exely_Code::UNKNOWN_BOOKING), self::warningCodes($response));
    }
}
