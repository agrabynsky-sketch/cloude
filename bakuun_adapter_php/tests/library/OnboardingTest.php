<?php
/**
 * Linking a Bakuun property, mapping it with Bakuun's codes and activating it.
 */
class OnboardingTest extends Test_TestCase
{
    protected static function results(array $report)
    {
        $results = array();
        foreach ($report as $row) {
            $results[$row['bakuun_code']] = array($row['ut_id'], $row['result']);
        }
        return $results;
    }

    public function testLinkReadsBakuunAndRequestsTheUnitTravelConnection()
    {
        $property = $this->onboarding()->link(Test_Fixtures::HOTEL, 123);

        $this->assertSame(Bakuun_Store_Properties::PENDING, $property['status']);
        $this->assertSame('Hotel Test', $property['hotel_name']);
        $this->assertSame('pending', $property['ut_connection_status']);
        $this->assertSame(Test_Fixtures::HOTEL, $this->ut->connections[123]['external_property_code']);
        $this->assertCount(1, $this->bakuun->requestsOf('GetPropertyInfo'));
        $this->assertSame(Test_Fixtures::bakuunProperty(), Bakuun_Store_Properties::staticData($property));
    }

    public function testAPropertyIsLinkedOnce()
    {
        $this->onboarding()->link(Test_Fixtures::HOTEL, 123);
        $this->bakuun->properties['HTL2.1'] = Test_Fixtures::bakuunProperty();

        $this->setExpectedException('InvalidArgumentException');
        $this->onboarding()->link('HTL2.1', 123);
    }

    public function testMappingWaitsForTheHotelsConfirmation()
    {
        $this->onboarding()->link(Test_Fixtures::HOTEL, 123);

        $this->setExpectedException('InvalidArgumentException', 'confirm it in Unit.Travel Extranet');
        $this->onboarding()->autoMap(Test_Fixtures::HOTEL, false);
    }

    public function testAutoMapMatchesByNameMealAndRefundability()
    {
        $onboarding = $this->onboarding();
        $onboarding->link(Test_Fixtures::HOTEL, 123);
        $this->ut->activate(123);

        $suggested = self::results($onboarding->autoMap(Test_Fixtures::HOTEL, false));

        $this->assertSame(array(
            'RX.1.1' => array(4500, 'suggested'),     // "Deluxe room" = "Deluxe Room"
            'RX.1.1.1' => array(13500, 'suggested'),  // BB, refundable
            'RX.1.1.2' => array(13501, 'suggested'),  // RO, non-refundable
            'RX.1.2' => array(4501, 'suggested'),     // "СТАНДАРТ" = "Стандарт"
            'RX.1.2.1' => array(13510, 'suggested'),  // meal code "0" = RO, refundable
        ), $suggested);
        $this->assertSame(array(), $this->mappings->all(Test_Fixtures::HOTEL));

        $onboarding->map(Test_Fixtures::HOTEL, 'rate_plan', 'RX.1.1.1', 13501);
        $saved = self::results($onboarding->autoMap(Test_Fixtures::HOTEL, true));

        $this->assertSame(array(13501, 'kept'), $saved['RX.1.1.1']);
        $this->assertSame(array(4500, 'mapped'), $saved['RX.1.1']);
        $this->assertCount(5, $this->mappings->all(Test_Fixtures::HOTEL));
    }

    public function testManualMappingIsValidated()
    {
        $onboarding = $this->onboarding();
        $onboarding->link(Test_Fixtures::HOTEL, 123);
        $this->ut->activate(123);
        $onboarding->map(Test_Fixtures::HOTEL, 'room', 'RX.1.1', 4500);

        try {
            $onboarding->map(Test_Fixtures::HOTEL, 'rate_plan', 'RX.1.1.1', 13510); // a rate plan of room 4501
            $this->fail('a rate plan of another room must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertContains('belongs to room 4501', $e->getMessage());
        }
        $this->setExpectedException('InvalidArgumentException', 'does not exist');
        $onboarding->map(Test_Fixtures::HOTEL, 'room', 'RX.1.2', 9999);
    }

    public function testActivatePushesMappingsToUnitTravelAndTellsBakuun()
    {
        $onboarding = $this->onboarding();
        $onboarding->link(Test_Fixtures::HOTEL, 123);
        $this->ut->activate(123);
        $onboarding->map(Test_Fixtures::HOTEL, 'room', 'RX.1.1', 4500);

        try {
            $onboarding->activate(Test_Fixtures::HOTEL);
            $this->fail('unmapped rooms must block activation');
        } catch (InvalidArgumentException $e) {
            $this->assertContains('rate_plan RX.1.1.1', $e->getMessage());
        }

        $onboarding->autoMap(Test_Fixtures::HOTEL, true);
        $property = $onboarding->activate(Test_Fixtures::HOTEL);

        $this->assertSame(Bakuun_Store_Properties::ACTIVE, $property['status']);
        $this->assertSame(
            array('entity_type' => 'rate_plan', 'external_code' => 'RX.1.2.1', 'internal_id' => 13510),
            $this->ut->mappings[123]['rate_plan:RX.1.2.1']
        );
        $this->assertCount(5, $this->ut->mappings[123]);
        $pushes = $this->bakuun->requestsOf('pushPropertyInfo');
        $this->assertCount(1, $pushes);
        $body = $pushes[0]['body'];
        $this->assertSame('Active', $body['mappingStatus']);
        $this->assertSame(Test_Fixtures::HOTEL, $body['propertyId']);
        $this->assertSame('Hotel Test', $body['propertyData']['propertyName']);
        $this->assertSame('RX.1.1.2', $body['propertyData']['roomTypes'][0]['rateTypes'][1]['ratePlanId']);
    }

    public function testPartialActivationMapsOnlyWhatIsMapped()
    {
        $onboarding = $this->onboarding();
        $onboarding->link(Test_Fixtures::HOTEL, 123);
        $this->ut->activate(123);
        $onboarding->map(Test_Fixtures::HOTEL, 'room', 'RX.1.1', 4500);
        $onboarding->map(Test_Fixtures::HOTEL, 'rate_plan', 'RX.1.1.1', 13500);

        $onboarding->activate(Test_Fixtures::HOTEL, true);

        $pushes = $this->bakuun->requestsOf('pushPropertyInfo');
        $roomTypes = $pushes[0]['body']['propertyData']['roomTypes'];
        $this->assertCount(1, $roomTypes);
        $this->assertCount(1, $roomTypes[0]['rateTypes']);
        $this->assertCount(3, $onboarding->unmapped(Test_Fixtures::HOTEL));
    }

    public function testSuspendAndDisconnect()
    {
        $this->givenMappedProperty();
        $onboarding = $this->onboarding();

        $property = $onboarding->suspend(Test_Fixtures::HOTEL);
        $this->assertSame(Bakuun_Store_Properties::SUSPENDED, $property['status']);
        $pushes = $this->bakuun->requestsOf('pushPropertyInfo');
        $this->assertSame('Suspended', $pushes[0]['body']['mappingStatus']);

        $property = $onboarding->disconnect(Test_Fixtures::HOTEL, true, true);
        $this->assertSame(Bakuun_Store_Properties::DISCONNECTED, $property['status']);
        $this->assertSame('disconnected', $this->ut->connections[123]['status']);
    }

    public function testListingMergesBakuunAndLocalLinks()
    {
        $this->bakuun->listing = array(
            array('propertyID' => Test_Fixtures::HOTEL, 'propertyName' => 'Hotel Test', 'mappingStatus' => 'Completed'),
            array('propertyID' => 'HTL2.1', 'propertyName' => 'Other', 'mappingStatus' => 'To Start'),
        );
        $this->givenMappedProperty();

        $listing = $this->onboarding()->listing();

        $this->assertSame(array('bakuun_hotel_id' => Test_Fixtures::HOTEL, 'name' => 'Hotel Test', 'bakuun_status' => 'Completed',
            'ut_property_id' => '123', 'status' => 'active'), $listing[0]);
        $this->assertNull($listing[1]['ut_property_id']);
    }
}
