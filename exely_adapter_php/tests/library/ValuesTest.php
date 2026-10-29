<?php
class ValuesTest extends PHPUnit_Framework_TestCase
{
    public function centsProvider()
    {
        return array(
            array('15,500.00', 1550000),
            array('20000.00', 2000000),
            array('5.000.00', 500000), // sic, from the Exely specification
            array('1,000', 100000),
            array('1,000,000.50', 100000050),
            array('15,50', 1550),
            array('1.000,50', 100050),
            array(' 1 200.00 ', 120000),
            array('99.995', 10000),
            array(120, 12000),
            array(99.9, 9990),
            array('-12.5', -1250),
        );
    }

    /**
     * @dataProvider centsProvider
     */
    public function testParseCents($raw, $expected)
    {
        $this->assertSame($expected, Exely_Values::parseCents($raw));
    }

    public function invalidNumberProvider()
    {
        return array(array(''), array('abc'), array(true), array(null), array('1e5'), array(array()));
    }

    /**
     * @dataProvider invalidNumberProvider
     */
    public function testParseCentsRejects($raw)
    {
        $this->setExpectedException('InvalidArgumentException');
        Exely_Values::parseCents($raw);
    }

    public function testParseInt()
    {
        $this->assertSame(6, Exely_Values::parseInt('6'));
        $this->assertSame(-1, Exely_Values::parseInt(-1));
        $this->assertSame(1000, Exely_Values::parseInt('1,000'));
        $this->setExpectedException('InvalidArgumentException');
        Exely_Values::parseInt('2.5');
    }

    public function testParseBool()
    {
        $this->assertFalse(Exely_Values::parseBool('false'));
        $this->assertTrue(Exely_Values::parseBool('TRUE'));
        $this->assertTrue(Exely_Values::parseBool(true));
        $this->assertFalse(Exely_Values::parseBool(0));
        $this->setExpectedException('InvalidArgumentException');
        Exely_Values::parseBool('maybe');
    }

    public function testFormatCents()
    {
        $this->assertSame('675.00', Exely_Values::formatCents(67500));
        $this->assertSame('0.05', Exely_Values::formatCents(5));
        $this->assertSame('-1.50', Exely_Values::formatCents(-150));
    }

    public function testSplitCentsKeepsTheTotal()
    {
        $this->assertSame(array(3333, 3333, 3334), Exely_Values::splitCents(10000, 3));
    }

    public function testAllocateCentsIsProportionalAndExact()
    {
        $parts = Exely_Values::allocateCents(10125, array(40000, 27500));
        $this->assertSame(10125, array_sum($parts));
        $this->assertSame(6000, $parts[0]);
    }

    public function testDivideRounded()
    {
        $this->assertSame(10125, Exely_Values::divideRounded(67500 * 1500, 10000));
        $this->assertSame(3, Exely_Values::divideRounded(5, 2)); // 2.5 rounds half up
        $this->assertSame(2, Exely_Values::divideRounded(5, 3));
        $this->assertSame(1, Exely_Values::divideRounded(4, 3));
    }

    public function testEqualsConstantTime()
    {
        $this->assertTrue(Exely_Values::equalsConstantTime('secret', 'secret'));
        $this->assertFalse(Exely_Values::equalsConstantTime('secret', 'secreT'));
        $this->assertFalse(Exely_Values::equalsConstantTime('secret', ''));
    }

    public function testUuid5MatchesPython()
    {
        // uuid.uuid5(uuid.UUID(VERSION_NAMESPACE), ...) computed with CPython.
        $this->assertSame(
            '7ba51887-cf31-50e5-b5a7-0a76ee4f50d9',
            Exely_Uuid::v5(Exely_Bookings::VERSION_NAMESPACE, 'UT-9F3A21|new|2026-07-01T10:00:00Z')
        );
        $this->assertSame(
            'e6b56253-388b-549a-9cd5-cb54243cbe9e',
            Exely_Uuid::v5(Exely_Bookings::VERSION_NAMESPACE, 'Привет|new|x')
        );
        $this->assertRegExp('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Exely_Uuid::v4());
    }
}
