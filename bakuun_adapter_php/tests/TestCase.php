<?php
/**
 * Base test case: fake Partner API and Bakuun, an in-memory SQLite database and helpers.
 */
abstract class Test_TestCase extends PHPUnit_Framework_TestCase
{
    const INBOUND_USER = 'bakuun';
    const INBOUND_PASSWORD = 'secret';

    /** @var Test_Fake_UnitTravel */
    protected $ut;
    /** @var Test_Fake_Bakuun */
    protected $bakuun;
    /** @var Zend_Db_Adapter_Abstract */
    protected $db;
    protected $properties;
    protected $mappings;
    protected $restrictions;
    protected $bookings;

    protected function setUp()
    {
        $this->ut = new Test_Fake_UnitTravel();
        $this->bakuun = new Test_Fake_Bakuun();
        $this->db = self::createDatabase();
        $this->properties = new Bakuun_Store_Properties($this->db);
        $this->mappings = new Bakuun_Store_Mappings($this->db);
        $this->restrictions = new Bakuun_Store_Restrictions($this->db);
        $this->bookings = new Bakuun_Store_Bookings($this->db);
    }

    public static function createDatabase()
    {
        $db = Zend_Db::factory('Pdo_Sqlite', array('dbname' => ':memory:'));
        $db->getConnection()->exec(file_get_contents(dirname(__FILE__) . '/../data/schema.sqlite.sql'));
        return $db;
    }

    protected function service(array $ari = array())
    {
        return new Bakuun_Service(
            array(
                'inboundUsername' => self::INBOUND_USER, 'inboundPassword' => self::INBOUND_PASSWORD, 'ari' => $ari,
                'sender' => array('statusWaitSeconds' => 0.2, 'statusPollInterval' => 0.01),
            ),
            $this->ut->client(), $this->properties, $this->mappings, $this->restrictions
        );
    }

    protected function sync(array $options = array())
    {
        return new Bakuun_BookingSync(
            $options, $this->bakuun->client(), $this->ut->client(), $this->bookings, $this->properties, $this->mappings
        );
    }

    protected function onboarding()
    {
        return new Bakuun_Onboarding($this->bakuun->client(), $this->ut->client(), $this->properties, $this->mappings);
    }

    /**
     * HTL1.1 linked to Unit.Travel property 123, mapped and active.
     */
    protected function givenMappedProperty($status = Bakuun_Store_Properties::ACTIVE)
    {
        $this->ut->activate(123);
        $this->properties->save(Test_Fixtures::HOTEL, array(
            'ut_property_id' => 123, 'ut_connection_id' => 'conn_123', 'ut_connection_status' => 'active', 'status' => $status,
            'source' => Bakuun_Store_Properties::SOURCE_BAKUUN, 'hotel_name' => 'Hotel Test', 'static_data' => Test_Fixtures::bakuunProperty(),
        ));
        foreach (Test_Fixtures::mappings() as $mapping) {
            list($type, $code, $utId, $utRoomId, $name) = $mapping;
            $this->mappings->save(Test_Fixtures::HOTEL, $type, $code, $utId, $utRoomId, $name, Bakuun_Store_Mappings::SOURCE_MANUAL);
        }
    }

    /**
     * Push like Bakuun does, round-tripping through JSON both ways.
     */
    protected function push(array $body, Bakuun_Service $service = null)
    {
        $payload = array_merge(array('username' => self::INBOUND_USER, 'password' => self::INBOUND_PASSWORD, 'hotelId' => Test_Fixtures::HOTEL), $body);
        $service = $service ? $service : $this->service();
        $response = $service->handle(UnitTravel_Json::decode(UnitTravel_Json::encode($payload)));
        return UnitTravel_Json::decode(UnitTravel_Json::encode($response));
    }

    protected static function errorCodes(array $response)
    {
        return self::_codes($response['errors']);
    }

    protected static function warningCodes(array $response)
    {
        return self::_codes($response['warnings']);
    }

    protected static function _codes(array $issues)
    {
        $codes = array();
        foreach ($issues as $issue) {
            $codes[] = $issue['code'];
        }
        return $codes;
    }
}
