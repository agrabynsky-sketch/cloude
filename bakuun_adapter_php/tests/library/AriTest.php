<?php
class AriTest extends Test_TestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->givenMappedProperty();
    }

    protected static function range(array $fields, $from = '2026-07-01', $to = '2026-07-03')
    {
        return array_merge(array('startDateYmd' => $from, 'endDateYmd' => $to), $fields);
    }

    protected function restrictionsOf($ratePlanId)
    {
        $found = array();
        foreach ($this->ut->ariItems('restrictions') as $item) {
            if ($item['rate_plan_id'] === $ratePlanId) {
                $found[] = $item;
            }
        }
        return $found;
    }

    // Envelope

    public function testRejectsWrongCredentials()
    {
        $response = $this->push(array('action' => 'InvCountUpdate', 'password' => 'wrong', 'data' => array()));

        $this->assertFalse($response['success']);
        $this->assertSame(array(Bakuun_Code::UNAUTHORISED), self::errorCodes($response));
        $this->assertSame(401, Bakuun_Response::httpStatus($response));
    }

    public function testRejectsUnknownActionHotelAndInactiveMapping()
    {
        $this->assertSame(array(Bakuun_Code::INVALID_ACTION), self::errorCodes($this->push(array('action' => 'doMagic'))));
        $this->assertSame(
            array(Bakuun_Code::INVALID_HOTEL),
            self::errorCodes($this->push(array('action' => 'InvCountUpdate', 'hotelId' => 'HTL9', 'data' => array(array()))))
        );

        $this->properties->save(Test_Fixtures::HOTEL, array('status' => Bakuun_Store_Properties::SUSPENDED));
        $response = $this->push(array('action' => 'InvCountUpdate', 'data' => array(self::range(array('roomCode' => 'RX.1.1', 'roomCount' => '5')))));
        $this->assertSame(array(Bakuun_Code::MAPPING_NOT_ACTIVE), self::errorCodes($response));
        $this->assertSame(array(), $this->ut->ariMessages);
    }

    // Availability

    public function testAvailability()
    {
        $response = $this->push(array('action' => 'InvCountUpdate', 'data' => array(
            self::range(array('roomCode' => 'RX.1.1', 'roomCount' => '5')),
            self::range(array('roomCode' => 'RX.1.2', 'roomCount' => 'N/A')),
            self::range(array('roomCode' => 'RX.9.9', 'roomCount' => '1')),
        )));

        $this->assertTrue($response['success']);
        $this->assertSame(array(Bakuun_Code::UNSUPPORTED_VALUE, Bakuun_Code::INVALID_ROOM_MAPPING), self::warningCodes($response));
        $this->assertSame(array(
            array('room_id' => 4500, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'units' => 5),
            array('room_id' => 4501, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'units' => 0),
        ), $this->ut->ariItems('availability'));
        $message = $this->ut->ariMessages[0];
        $this->assertSame(123, $message['property_id']);
        $this->assertStringStartsWith('bakuun-123-', $message['message_uid']);
    }

    public function testKeysWithStraySpacesAreAccepted()
    {
        $response = $this->push(array('action' => 'InvCountUpdate', 'data' => array(
            array('startDateYmd ' => '2026-07-01', 'endDateYmd' => '2026-07-01', 'roomCode ' => 'RX.1.1', 'roomCount' => '2'),
        )));

        $this->assertTrue($response['success']);
        $this->assertCount(1, $this->ut->ariItems('availability'));
    }

    public function testInvalidValuesFail()
    {
        $this->assertSame(array(Bakuun_Code::INVALID_DATE_RANGE), self::errorCodes($this->push(array('action' => 'InvCountUpdate', 'data' => array(
            self::range(array('roomCode' => 'RX.1.1', 'roomCount' => '5'), '2026-07-03', '2026-07-01'),
        )))));
        $this->assertSame(array(Bakuun_Code::INVALID_DATA), self::errorCodes($this->push(array('action' => 'InvCountUpdate', 'data' => array(
            self::range(array('roomCode' => 'RX.1.1', 'roomCount' => 'many')),
        )))));
        $this->assertSame(array(Bakuun_Code::DATA_MISSING), self::errorCodes($this->push(array('action' => 'InvCountUpdate', 'data' => array()))));
    }

    // Restrictions

    public function testRatePlanLevelStopSell()
    {
        $this->push(array('action' => 'masterCondClosed', 'data' => array(
            self::range(array('roomCode' => 'RX.1.1', 'condCode' => 'RX.1.1.2', 'restriction' => 'Master', 'status' => '1')),
        )));

        $this->assertSame(array(array('rate_plan_id' => 13501, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'stop_sell' => true)), $this->ut->ariItems('restrictions'));
    }

    public function testRoomLevelStopSellReachesEveryRatePlanOfTheRoom()
    {
        $this->push(array('action' => 'MasterClosedRQ', 'data' => array(
            self::range(array('roomCode' => 'RX.1.1', 'restriction' => 'Master', 'status' => '1')),
        )));

        $this->assertSame(array(
            array('rate_plan_id' => 13500, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'stop_sell' => true),
            array('rate_plan_id' => 13501, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'stop_sell' => true),
        ), $this->ut->ariItems('restrictions'));
    }

    public function testOpeningTheRoomKeepsARatePlanThatIsClosedOnItsOwn()
    {
        $this->push(array('action' => 'masterCondClosed', 'data' => array(
            self::range(array('roomCode' => 'RX.1.1', 'condCode' => 'RX.1.1.2', 'restriction' => 'Master', 'status' => '1'), '2026-07-02', '2026-07-02'),
        )));
        $this->push(array('action' => 'MasterClosedRQ', 'data' => array(self::range(array('roomCode' => 'RX.1.1', 'status' => '1')))));
        $this->ut->ariMessages = array();

        $this->push(array('action' => 'MasterClosedRQ', 'data' => array(self::range(array('roomCode' => 'RX.1.1', 'status' => '0')))));

        $this->assertSame(array(array('rate_plan_id' => 13500, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'stop_sell' => false)), $this->restrictionsOf(13500));
        $this->assertSame(array(
            array('rate_plan_id' => 13501, 'date_from' => '2026-07-01', 'date_to' => '2026-07-01', 'stop_sell' => false),
            array('rate_plan_id' => 13501, 'date_from' => '2026-07-02', 'date_to' => '2026-07-02', 'stop_sell' => true),
            array('rate_plan_id' => 13501, 'date_from' => '2026-07-03', 'date_to' => '2026-07-03', 'stop_sell' => false),
        ), $this->restrictionsOf(13501));
    }

    public function testMinimumStayIsTheLargerOfBothLevels()
    {
        $this->push(array('action' => 'SetLosRQ', 'data' => array(self::range(array('roomCode' => 'RX.1.2', 'minMaxMessageType' => 'SetMinLOS', 'Time' => '3')))));
        $this->push(array('action' => 'SetLosCondRQ', 'data' => array(
            self::range(array('roomCode' => 'RX.1.2', 'condCode' => 'RX.1.2.1', 'minMaxMessageType' => 'SetMinLOS', 'Time' => '5'), '2026-07-01', '2026-07-01'),
        )));
        $items = $this->restrictionsOf(13510);
        $this->assertSame(array('rate_plan_id' => 13510, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'min_stay' => 3), $items[0]);
        $this->assertSame(array('rate_plan_id' => 13510, 'date_from' => '2026-07-01', 'date_to' => '2026-07-01', 'min_stay' => 5), $items[1]);
        $this->ut->ariMessages = array();

        $this->push(array('action' => 'SetLosCondRQ', 'data' => array(
            self::range(array('roomCode' => 'RX.1.2', 'condCode' => 'RX.1.2.1', 'minMaxMessageType' => 'SetMinLOS', 'Time' => 'N/A'), '2026-07-01', '2026-07-01'),
        )));

        $this->assertSame(array(array('rate_plan_id' => 13510, 'date_from' => '2026-07-01', 'date_to' => '2026-07-01', 'min_stay' => 3)), $this->restrictionsOf(13510));
    }

    public function testMaximumLimitsAreTheTighterOfBothLevels()
    {
        $this->push(array('action' => 'SetAdvBookingCondRQ', 'data' => array(self::range(array('roomCode' => 'RX.1.2', 'minMaxMessageType' => 'MaxAdvBooking', 'Time' => '90')))));
        $this->push(array('action' => 'SetAdvBookingCondRQ', 'data' => array(
            self::range(array('roomCode' => 'RX.1.2', 'condCode' => 'RX.1.2.1', 'minMaxMessageType' => 'MaxAdvBooking', 'Time' => '30')),
        )));
        $this->push(array('action' => 'SetLosRQ', 'data' => array(self::range(array('roomCode' => 'RX.1.2', 'minMaxMessageType' => 'SetMaxLOS', 'Time' => 'N/A')))));

        $items = $this->restrictionsOf(13510);
        $this->assertSame(array('rate_plan_id' => 13510, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'max_advance_days' => 90), $items[0]);
        $this->assertSame(array('rate_plan_id' => 13510, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'max_advance_days' => 30), $items[1]);
        $this->assertSame(array('rate_plan_id' => 13510, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03', 'max_stay' => 0), $items[2]);
    }

    public function testArrivalBasedRestrictionsAndOthersAreAcknowledgedWithAWarning()
    {
        foreach (array('SetArrivalLosRQ', 'SetArrivalLosCondRQ', 'sellThresholdUpdate', 'getCancellation', 'losPriceUpdate') as $action) {
            $response = $this->push(array('action' => $action, 'data' => array(self::range(array('roomCode' => 'RX.1.1', 'Time' => '2')))));
            $this->assertTrue($response['success'], $action);
            $this->assertSame(array(Bakuun_Code::UNSUPPORTED_VALUE), self::warningCodes($response), $action);
        }
        $this->assertSame(array(), $this->ut->ariMessages);
    }

    public function testRatePlanMappedToAnotherRoomFails()
    {
        $response = $this->push(array('action' => 'arrivalCondClosed', 'data' => array(
            self::range(array('roomCode' => 'RX.1.2', 'condCode' => 'RX.1.1.1', 'status' => '1')),
        )));

        $this->assertSame(array(Bakuun_Code::INVALID_RATE_PLAN_MAPPING), self::errorCodes($response));
    }

    // Prices

    protected static function basicRate(array $fields)
    {
        return array_merge(array(
            'roomCode' => 'RX.1.1', 'condCode' => 'RX.1.1.1', 'rateType' => 'NET', 'rateModel' => 'Per Night',
            'amountBeforeTax' => '100.00', 'amountAfterTax' => '120.00', 'currencyCode' => 'eur',
        ), $fields);
    }

    public function testBasicPrices()
    {
        $response = $this->push(self::range(array('action' => 'priceUpdate', 'dailyRates' => array(
            self::basicRate(array()),
            self::basicRate(array('roomCode' => 'RX.1.2', 'condCode' => 'RX.1.2.1', 'amountBeforeTax' => '80.50')),
            self::basicRate(array('condCode' => 'RX.1.1.2', 'amountBeforeTax' => 'N/A')),
        ))));

        $this->assertTrue($response['success']);
        $this->assertSame(array(Bakuun_Code::UNSUPPORTED_VALUE), self::warningCodes($response));
        $this->assertEquals(array(
            array('room_id' => 4500, 'rate_plan_id' => 13500, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03',
                'currency' => 'EUR', 'pricing_model' => 'per_room', 'price' => 100),
            array('room_id' => 4501, 'rate_plan_id' => 13510, 'date_from' => '2026-07-01', 'date_to' => '2026-07-03',
                'currency' => 'EUR', 'pricing_model' => 'per_guest', 'occupancy_prices' => array(
                    array('occupancy' => 1, 'price' => 80.5), array('occupancy' => 2, 'price' => 80.5), array('occupancy' => 3, 'price' => 80.5),
                )),
        ), $this->ut->ariItems('rates'));
    }

    public function testPriceFieldAndBarRatesAreConfigurable()
    {
        $this->push(self::range(array('action' => 'priceUpdate', 'dailyRates' => array(self::basicRate(array())))), $this->service(array('priceField' => 'amountAfterTax')));
        $rates = $this->ut->ariItems('rates');
        $this->assertEquals(120, $rates[0]['price']);

        $response = $this->push(
            self::range(array('action' => 'priceUpdate', 'dailyRates' => array(self::basicRate(array('rateType' => 'BAR'))))),
            $this->service(array('rejectBarRates' => true))
        );
        $this->assertSame(array(Bakuun_Code::INVALID_RATE_TYPE), self::errorCodes($response));
    }

    public function testOccupancyPrices()
    {
        $plain = function ($adults, $amount) {
            return array('rateType' => 'NET', 'amountBeforeTax' => $amount, 'amountAfterTax' => $amount,
                'occupancy' => array('adultCount' => $adults, 'childCount' => array(), 'infantCount' => array()));
        };
        $withChild = $plain(2, '150.00');
        $withChild['occupancy']['childCount'] = array(array('count' => 1, 'minAge' => '3', 'maxAge' => '8'));

        $response = $this->push(self::range(array('action' => 'occPriceUpdate', 'dailyRates' => array(
            array('roomCode' => 'RX.1.2', 'condCode' => 'RX.1.2.1', 'currencyCode' => 'EUR', 'priceDetails' => array(
                $plain(2, '120.00'), $plain(1, '90.00'), $withChild,
            )),
            array('roomCode' => 'RX.1.1', 'condCode' => 'RX.1.1.1', 'currencyCode' => 'EUR', 'priceDetails' => array(
                $plain(1, '90.00'), $plain(2, '110.00'),
            )),
        ))));

        $this->assertSame(array(Bakuun_Code::UNSUPPORTED_VALUE), self::warningCodes($response));
        $rates = $this->ut->ariItems('rates');
        $this->assertEquals(array(array('occupancy' => 1, 'price' => 90), array('occupancy' => 2, 'price' => 120)), $rates[0]['occupancy_prices']);
        $this->assertSame('per_room', $rates[1]['pricing_model']);
        $this->assertEquals(110, $rates[1]['price']); // the room's base occupancy is 2
    }

    // Unit.Travel failures

    public function testUnitTravelFailuresAreMappedToBakuunCodes()
    {
        $availability = array('action' => 'InvCountUpdate', 'data' => array(self::range(array('roomCode' => 'RX.1.1', 'roomCount' => '5'))));

        $this->ut->ariOutcome = array('status' => 'failed', 'errors' => array(array(
            'code' => 'unknown', 'message' => 'unknown', 'details' => array(array('path' => 'availability[0].room_id', 'reason' => 'unknown')),
        )));
        $this->assertSame(array(Bakuun_Code::INVALID_ROOM_MAPPING), self::errorCodes($this->push($availability)));

        $this->ut->ariError = array(409, array('code' => 'connection_not_active', 'message' => 'not active'));
        $this->assertSame(array(Bakuun_Code::MAPPING_NOT_ACTIVE), self::errorCodes($this->push($availability)));

        $this->ut->ariError = array(429, array('code' => 'rate_limited', 'message' => 'slow down'), array('Retry-After' => '30'));
        $response = $this->push($availability);
        $this->assertSame(array(Bakuun_Code::SERVICE_UNAVAILABLE), self::errorCodes($response));
        $this->assertSame(503, Bakuun_Response::httpStatus($response));
    }

    public function testStillProcessingIsAWarning()
    {
        $this->ut->ariOutcome = array('status' => 'processing');

        $response = $this->push(array('action' => 'InvCountUpdate', 'data' => array(self::range(array('roomCode' => 'RX.1.1', 'roomCount' => '5')))));

        $this->assertTrue($response['success']);
        $this->assertSame(array(Bakuun_Code::STILL_PROCESSING), self::warningCodes($response));
    }
}
