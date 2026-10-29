<?php
class Test_NoCurrencyLookup
{
    public function currencyFor($ratePlanId)
    {
        throw new LogicException("currency lookup for $ratePlanId not expected");
    }
}

class Test_FixedCurrencies
{
    public function currencyFor($ratePlanId)
    {
        return $ratePlanId === 13500 ? 'UAH' : 'EUR';
    }
}

class AriTest extends PHPUnit_Framework_TestCase
{
    protected static function build(array $items, $currencies = null)
    {
        $updates = array();
        foreach ($items as $index => $item) {
            $updates[] = Exely_Update::fromArray(Exely_Json::decode(Exely_Json::encode($item)), "updates[$index]");
        }
        return Exely_Ari::build($updates, $currencies ? $currencies : new Test_NoCurrencyLookup());
    }

    protected static function item(array $fields)
    {
        return array_merge(array('startDateYmd' => '2024-07-20', 'endDateYmd' => '2024-07-20'), $fields);
    }

    public function testQuotaBecomesAvailability()
    {
        $parts = self::build(array(
            array('startDateYmd' => '2024-07-28', 'endDateYmd' => '2024-07-29', 'roomTypeId' => '4500', 'quota' => '0'),
        ));

        $this->assertSame(
            array(array('room_id' => 4500, 'date_from' => '2024-07-28', 'date_to' => '2024-07-29', 'units' => 0)),
            $parts->availability
        );
        $this->assertSame(array(), $parts->rates);
        $this->assertSame(array(), $parts->restrictions);
    }

    public function testPerRoomAndPerGuestPrices()
    {
        $parts = self::build(array(
            array('startDateYmd' => '2024-07-23', 'endDateYmd' => '2024-07-25', 'roomTypeId' => '4500', 'ratePlanId' => '13500',
                'currencyCode' => 'eur', 'prices' => array(array('price' => '15,500.00', 'code' => '4500_room'))),
            array('startDateYmd' => '2024-07-23', 'endDateYmd' => '2024-07-25', 'roomTypeId' => '4501', 'ratePlanId' => '13510',
                'currencyCode' => 'EUR', 'prices' => array(
                    array('price' => '120.50', 'code' => '4501_2'), array('price' => '80', 'code' => '4501_1'),
                )),
        ));

        $this->assertEquals(array(
            array('room_id' => 4500, 'rate_plan_id' => 13500, 'date_from' => '2024-07-23', 'date_to' => '2024-07-25',
                'currency' => 'EUR', 'pricing_model' => 'per_room', 'price' => 15500),
            array('room_id' => 4501, 'rate_plan_id' => 13510, 'date_from' => '2024-07-23', 'date_to' => '2024-07-25',
                'currency' => 'EUR', 'pricing_model' => 'per_guest', 'occupancy_prices' => array(
                    array('occupancy' => 1, 'price' => 80), array('occupancy' => 2, 'price' => 120.5),
                )),
        ), $parts->rates);
        $this->assertSame('{"occupancy":2,"price":120.5}', Exely_Json::encode($parts->rates[1]['occupancy_prices'][1]));
    }

    public function testMissingCurrencyIsLookedUp()
    {
        $parts = self::build(array(self::item(array(
            'roomTypeId' => '4500', 'ratePlanId' => '13500', 'prices' => array(array('price' => '100', 'code' => '4500_room')),
        ))), new Test_FixedCurrencies());

        $this->assertSame('UAH', $parts->rates[0]['currency']);
    }

    public function testRestrictionsFollowUnitTravelConventions()
    {
        $parts = self::build(array(array(
            'startDateYmd' => '2024-07-20', 'endDateYmd' => '2024-07-23', 'roomTypeId' => '4500', 'ratePlanId' => '13500',
            'closed' => 'false', 'closedToArrival' => true, 'closedToDeparture' => false, 'minLos' => 0, 'maxLos' => 7,
            'minimumAdvanceBooking' => 2, 'maximumAdvanceBooking' => -1, 'minLosArrivalBased' => 0,
            'mealPlanType' => 'allInclusive',
        )));

        $this->assertSame(array(array(
            'rate_plan_id' => 13500, 'date_from' => '2024-07-20', 'date_to' => '2024-07-23',
            'stop_sell' => false, 'closed_to_arrival' => true, 'closed_to_departure' => false, 'min_stay' => 1,
            'max_stay' => 7, 'min_advance_days' => 2, 'max_advance_days' => 0,
        )), $parts->restrictions);
        $this->assertSame(array(), $parts->warnings);
    }

    public function testOnlyFieldsPresentAreSent()
    {
        $parts = self::build(array(self::item(array('roomTypeId' => '4500', 'ratePlanId' => '13500', 'minLos' => 3))));

        $this->assertSame(
            array(array('rate_plan_id' => 13500, 'date_from' => '2024-07-20', 'date_to' => '2024-07-20', 'min_stay' => 3)),
            $parts->restrictions
        );
    }

    public function testUnsupportedRestrictionsAreWarnedAbout()
    {
        $parts = self::build(array(self::item(array(
            'roomTypeId' => '4500', 'ratePlanId' => '13500', 'minLosArrivalBased' => 3, 'maximumAdvanceBooking' => 0,
        ))));

        $this->assertTrue($parts->isEmpty());
        $this->assertCount(2, $parts->warnings);
        $this->assertSame(Exely_Code::UNSUPPORTED_VALUE, $parts->warnings[0]->code);
    }

    public function testRoomLevelClosedWithQuota()
    {
        $parts = self::build(array(self::item(array('roomTypeId' => '4500', 'quota' => 3, 'closed' => true))));

        $this->assertTrue($parts->availability[0]['closed']);
        $this->assertSame(array(), $parts->restrictions);
    }

    public function invalidUpdateProvider()
    {
        return array(
            array(array('roomTypeId' => 'STD100', 'quota' => 1), Exely_Code::INVALID_ROOM_TYPE),
            array(array('roomTypeId' => '4500', 'currencyCode' => 'EUR',
                'prices' => array(array('price' => '1', 'code' => '4500_room'))), Exely_Code::INVALID_RATE_PLAN),
            array(array('roomTypeId' => '4500', 'minLos' => 2), Exely_Code::INVALID_RATE_PLAN),
            array(array('roomTypeId' => '4500', 'ratePlanId' => '13500', 'currencyCode' => 'EUR',
                'prices' => array(array('price' => '1', 'code' => '4501_2'))), Exely_Code::INVALID_ACCOMMODATION),
            array(array('roomTypeId' => '4501', 'ratePlanId' => '13510', 'currencyCode' => 'EUR', 'prices' => array(
                array('price' => '1', 'code' => '4501_2'), array('price' => '1', 'code' => '4501_room'),
            )), Exely_Code::INVALID_ACCOMMODATION),
            array(array('roomTypeId' => '4500', 'quota' => -1), Exely_Code::INVALID_REQUEST),
            array(array('roomTypeId' => '4500', 'quota' => 'many'), Exely_Code::INVALID_REQUEST),
            array(array('roomTypeId' => '4500', 'quota' => 1, 'endDateYmd' => '2024-07-19'), Exely_Code::INVALID_REQUEST),
            array(array('roomTypeId' => '4500', 'quota' => 1, 'startDateYmd' => '2024-02-30'), Exely_Code::INVALID_REQUEST),
        );
    }

    /**
     * @dataProvider invalidUpdateProvider
     */
    public function testInvalidUpdatesFail(array $fields, $code)
    {
        try {
            self::build(array(self::item($fields)));
            $this->fail('Exely_Failure expected');
        } catch (Exely_Failure $e) {
            $errors = $e->getErrors();
            $this->assertSame($code, $errors[0]->code, $e->getMessage());
        }
    }

    public function testChunksRespectTheSizeLimit()
    {
        $parts = new Exely_AriParts();
        $parts->availability = array(array('a' => 1), array('a' => 2));
        $parts->rates = array(array('r' => 1));
        $parts->restrictions = array(array('s' => 1));

        $this->assertSame(array(
            array('availability' => array(array('a' => 1), array('a' => 2)), 'rates' => array(array('r' => 1))),
            array('restrictions' => array(array('s' => 1))),
        ), $parts->chunks(3));
    }
}
