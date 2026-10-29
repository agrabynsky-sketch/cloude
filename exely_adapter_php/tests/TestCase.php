<?php
/**
 * Base test case: a fake Partner API, an in-memory SQLite store and helpers to call the adapter.
 */
abstract class Test_TestCase extends PHPUnit_Framework_TestCase
{
    const USERNAME = 'exely';
    const PASSWORD = 'secret';

    /** @var Test_Fake_UnitTravel */
    protected $fake;

    /** @var Zend_Db_Adapter_Abstract */
    protected $db;

    /** @var Exely_BookingStore */
    protected $store;

    /** @var Exely_Service */
    protected $service;

    protected function setUp()
    {
        $this->fake = new Test_Fake_UnitTravel();
        $this->db = self::createDatabase();
        $this->store = new Exely_BookingStore($this->db);
        $this->service = $this->createService();
    }

    public static function createDatabase()
    {
        $db = Zend_Db::factory('Pdo_Sqlite', array('dbname' => ':memory:'));
        $db->getConnection()->exec(file_get_contents(dirname(__FILE__) . '/../data/schema.sqlite.sql'));
        return $db;
    }

    protected function createService(array $options = array())
    {
        $options = array_merge(array(
            'exelyUsername' => self::USERNAME,
            'exelyPassword' => self::PASSWORD,
            'ariStatusWaitSeconds' => 0.2,
            'ariStatusPollInterval' => 0.01,
        ), $options);
        return new Exely_Service($options, $this->fake->client(), $this->store);
    }

    /**
     * Call the adapter like Exely does, round-tripping through JSON both ways.
     */
    protected function exely($action, array $data = array(), array $envelope = array())
    {
        $payload = array_merge(
            array('username' => self::USERNAME, 'password' => self::PASSWORD, 'action' => $action, 'data' => (object) $data),
            $envelope
        );
        $response = $this->service->handle(Exely_Json::decode(Exely_Json::encode($payload)));
        return Exely_Json::decode(Exely_Json::encode($response));
    }

    protected function confirm(array $booking, $externalNumber = '20241111-111111-22548963')
    {
        $item = array('number' => $booking['number'], 'echoToken' => $booking['echoToken'], 'status' => $booking['status']);
        if ($externalNumber !== null) {
            $item['externalNumber'] = $externalNumber;
        }
        return $this->exely('confirm-bookings', array('confirmBookings' => array($item)));
    }

    protected function bookings(array $data = array())
    {
        $response = $this->exely('get-bookings', $data);
        $this->assertTrue($response['success'], json_encode($response));
        return $response['data']['bookings'];
    }

    public static function isSuccess(array $response)
    {
        return $response['success'] === true && $response['errors'] === array();
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
