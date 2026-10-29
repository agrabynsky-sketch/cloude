<?php
class CatalogTest extends PHPUnit_Framework_TestCase
{
    public function testOccupancyCodesRoundTrip()
    {
        $this->assertSame(2, Exely_Catalog::parseOccupancyCode(Exely_Catalog::occupancyCode(4501, 2), 4501));
        $this->assertNull(Exely_Catalog::parseOccupancyCode(Exely_Catalog::occupancyCode(4500, null), 4500));
    }

    public function foreignCodeProvider()
    {
        return array(array('4502_2'), array('4501_0'), array('4501_x'), array('4501'), array('STD100_1'));
    }

    /**
     * @dataProvider foreignCodeProvider
     */
    public function testParseOccupancyCodeRejectsForeignCodes($code)
    {
        $this->setExpectedException('InvalidArgumentException');
        Exely_Catalog::parseOccupancyCode($code, 4501);
    }

    public function testBuild()
    {
        list($catalog, $warnings) = Exely_Catalog::build(
            Test_Fixtures::rooms(), Test_Fixtures::ratePlans(), array('rates' => true, 'restrictions' => true)
        );

        $this->assertSame(array(
            array(
                'roomTypeId' => '4500',
                'roomName' => 'Deluxe Room',
                'ratePlans' => array(array('ratePlanId' => '13500', 'ratePlanName' => 'Deluxe BB Flexible')),
                'occupancies' => array(array('code' => '4500_room', 'bedType' => 'adultBed', 'personQuantity' => 2)),
            ),
            array(
                'roomTypeId' => '4501',
                'roomName' => 'Standard Room',
                'ratePlans' => array(array('ratePlanId' => '13510', 'ratePlanName' => 'Standard RO')),
                'occupancies' => array(
                    array('code' => '4501_1', 'bedType' => 'adultBed', 'personQuantity' => 1),
                    array('code' => '4501_2', 'bedType' => 'adultBed', 'personQuantity' => 2),
                    array('code' => '4501_3', 'bedType' => 'adultBed', 'personQuantity' => 3),
                ),
            ),
        ), $catalog);
        $this->assertCount(1, $warnings);
        $this->assertSame(Exely_Code::ROOM_SKIPPED, $warnings[0]->code);
    }

    public function testBuildFlagsUpdatesTheConnectionMayNotSend()
    {
        list($catalog) = Exely_Catalog::build(Test_Fixtures::rooms(), Test_Fixtures::ratePlans(), array('rates' => false));

        $this->assertSame(array('prices' => true), $catalog[0]['ratePlans'][0]['nonSupportedUpdates']);
    }
}
