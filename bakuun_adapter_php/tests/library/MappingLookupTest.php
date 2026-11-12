<?php
/**
 * Bakuun mapping our own codes on its side: getRoom / getRoomRatePlan.
 */
class MappingLookupTest extends Test_TestCase
{
    protected function lookup(array $hotelRef, $action = 'getRoom')
    {
        return $this->push(array('action' => $action, 'HotelRef' => $hotelRef, 'hotelId' => null));
    }

    public function testFirstLookupRequestsTheUnitTravelConnection()
    {
        $pending = $this->lookup(array('code' => '123', 'Name' => 'Hotel Test'));

        $this->assertSame(array(Bakuun_Code::MAPPING_NOT_ACTIVE), self::errorCodes($pending));
        $this->assertSame('pending', $this->ut->connections[123]['status']);
        $this->assertSame('123', $this->ut->connections[123]['external_property_code']);
        $property = $this->properties->find('123');
        $this->assertSame(Bakuun_Store_Properties::PENDING, $property['status']);
        $this->assertSame(Bakuun_Store_Properties::SOURCE_PARTNER, $property['source']);

        $this->ut->activate(123);
        $response = $this->lookup(array('code' => '123', 'Name' => 'Hotel Test'));

        $this->assertTrue($response['success']);
        $this->assertSame(array('hotelId' => '123', 'hotelRoomLists' => array(
            array('roomTypeId' => '4500', 'roomName' => 'Deluxe Room', 'type' => 'Daily Room'),
            array('roomTypeId' => '4501', 'roomName' => 'Стандарт', 'type' => 'Daily Room'),
        )), $response['data']);
        $property = $this->properties->find('123');
        $this->assertSame(Bakuun_Store_Properties::ACTIVE, $property['status']);
        $mapping = $this->mappings->find('123', 'room', '4501');
        $this->assertSame('4501', (string) $mapping['ut_id']);
    }

    public function testRatePlansOfARoom()
    {
        $this->ut->activate(123);
        $this->lookup(array('code' => '123'));

        $response = $this->lookup(array('code' => '123', 'Room' => array('code' => '4500')), 'getRoomRatePlan');

        $this->assertTrue($response['success']);
        $this->assertSame(array('hotelId' => '123', 'Room' => array('roomTypeId' => '4500', 'ratePlans' => array(
            array('ratePlanId' => '13500', 'ratePlanName' => 'Deluxe BB Flexible', 'cancelType' => 0, 'authPerson' => 4, 'extraBed' => 0,
                'extraCradles' => 1, 'default_breakfast' => 1, 'default_halfMeal' => 0, 'default_fullMeal' => 0),
            array('ratePlanId' => '13501', 'ratePlanName' => 'Deluxe RO Non-refundable', 'cancelType' => 1, 'authPerson' => 4, 'extraBed' => 0,
                'extraCradles' => 1, 'default_breakfast' => 0, 'default_halfMeal' => 0, 'default_fullMeal' => 0),
        ))), $response['data']);
        $mapping = $this->mappings->find('123', 'rate_plan', '13501');
        $this->assertSame('4500', (string) $mapping['ut_room_id']);

        $unknown = $this->lookup(array('code' => '123', 'Room' => array('code' => '999')), 'getRoomRatePlan');
        $this->assertSame(array(Bakuun_Code::INVALID_ROOM), self::errorCodes($unknown));
    }

    public function testAriWithOurCodesFlowsOnceBakuunHasMappedThem()
    {
        $this->ut->activate(123);
        $this->lookup(array('code' => '123'));
        $this->lookup(array('code' => '123', 'Room' => array('code' => '4500')), 'getRoomRatePlan');

        $response = $this->push(array('action' => 'masterCondClosed', 'hotelId' => '123', 'data' => array(array(
            'startDateYmd' => '2026-07-01', 'endDateYmd' => '2026-07-01', 'roomCode' => '4500', 'condCode' => '13501', 'status' => '1',
        ))));

        $this->assertTrue($response['success']);
        $this->assertSame(array(array('rate_plan_id' => 13501, 'date_from' => '2026-07-01', 'date_to' => '2026-07-01', 'stop_sell' => true)), $this->ut->ariItems('restrictions'));
    }

    public function testUnknownHotels()
    {
        $this->assertSame(array(Bakuun_Code::INVALID_HOTEL), self::errorCodes($this->lookup(array('code' => 'HTL404'))));
        $this->assertSame(array(Bakuun_Code::INVALID_HOTEL), self::errorCodes($this->lookup(array('code' => '999'))));
        $this->assertSame(array(Bakuun_Code::DATA_MISSING), self::errorCodes($this->lookup(array())));
    }

    public function testASuspendedHotelIsNotServed()
    {
        $this->givenMappedProperty(Bakuun_Store_Properties::SUSPENDED);

        $this->assertSame(array(Bakuun_Code::MAPPING_NOT_ACTIVE), self::errorCodes($this->lookup(array('code' => Test_Fixtures::HOTEL))));
    }
}
