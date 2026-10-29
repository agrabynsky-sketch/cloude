<?php
/**
 * The ZF1 application end to end: Zend_Application bootstrap, routing and controllers.
 */
class ControllerTest extends Test_TestCase
{
    const WEBHOOK_SECRET = 'whsec';

    /** @var Zend_Application */
    protected $application;

    /** @var Test_Fake_Notifier */
    protected $notifier;

    protected function setUp()
    {
        parent::setUp();
        Zend_Controller_Front::getInstance()->resetInstance();
        $this->application = new Zend_Application('testing', array(
            'phpSettings' => array('date.timezone' => 'UTC'),
            'bootstrap' => array('path' => APPLICATION_PATH . '/Bootstrap.php', 'class' => 'Bootstrap'),
            'appnamespace' => '',
            'autoloaderNamespaces' => array('Exely_'),
            'resources' => array(
                'frontController' => array(
                    'controllerDirectory' => APPLICATION_PATH . '/controllers',
                    'params' => array('displayExceptions' => 0),
                ),
                'db' => array('adapter' => 'pdo_sqlite', 'params' => array('dbname' => ':memory:')),
            ),
            'exely' => array('username' => self::USERNAME, 'password' => self::PASSWORD, 'notifyUrl' => ''),
            'unitTravel' => array(
                'baseUrl' => 'http://127.0.0.1:9/partner/v1',
                'apiKey' => 'key',
                'webhookSecret' => self::WEBHOOK_SECRET,
                'pullReservations' => '',
            ),
        ));
        $this->application->bootstrap();
        $this->application->getBootstrap()->getResource('db')->getConnection()
            ->exec(file_get_contents(APPLICATION_PATH . '/../data/schema.sqlite.sql'));
    }

    protected function tearDown()
    {
        Zend_Controller_Front::getInstance()->resetInstance();
    }

    /**
     * Swap in the test service (fake Partner API, prepared store) and notifier.
     */
    protected function useTestDoubles()
    {
        $container = $this->application->getBootstrap()->getContainer();
        $container->exelyservice = $this->createService(array('pullReservations' => false));
        $this->notifier = new Test_Fake_Notifier();
        $container->exelynotifier = $this->notifier;
    }

    protected function dispatch($method, $uri, $body = '', array $headers = array())
    {
        $request = new Zend_Controller_Request_HttpTestCase();
        $request->setMethod($method)->setRequestUri($uri)->setRawBody($body);
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        $response = new Zend_Controller_Response_HttpTestCase();
        $front = $this->application->getBootstrap()->getResource('frontController');
        $front->setParam('bootstrap', $this->application->getBootstrap())
            ->setRequest($request)
            ->setResponse($response)
            ->returnResponse(true);
        $front->dispatch($request, $response);
        return $response;
    }

    protected function postWebhook($event, array $reservation, $secret = self::WEBHOOK_SECRET)
    {
        $body = Exely_Json::encode(array('event' => $event, 'occurred_at' => '2026-07-01T10:00:00Z', 'reservation' => $reservation));
        return $this->dispatch('POST', '/webhooks/unit-travel', $body, array(
            Exely_Signature::HEADER => Exely_Signature::sign($secret, $body),
        ));
    }

    public function testBootstrapWiresTheServiceFromTheConfiguration()
    {
        $this->assertInstanceOf('Exely_Service', $this->application->getBootstrap()->getResource('exelyService'));

        $body = Exely_Json::encode(array('username' => 'exely', 'password' => 'secret', 'action' => 'get-bookings', 'data' => new stdClass()));
        $response = $this->dispatch('POST', '/exely', $body);

        $this->assertSame(200, $response->getHttpResponseCode());
        $this->assertSame(
            array('success' => true, 'errors' => array(), 'warnings' => array(), 'data' => array('bookings' => array())),
            Exely_Json::decode($response->getBody())
        );
    }

    public function testExelyEndpointAnswersInTheExelyEnvelope()
    {
        $response = $this->dispatch('POST', '/exely', 'not json');

        $this->assertSame(200, $response->getHttpResponseCode());
        $body = Exely_Json::decode($response->getBody());
        $this->assertFalse($body['success']);
        $this->assertSame(array(Exely_Code::INVALID_REQUEST), self::errorCodes($body));

        $wrong = Exely_Json::decode($this->dispatch('POST', '/exely', '{"username":"exely","password":"x","action":"get-bookings"}')->getBody());
        $this->assertSame(array(Exely_Code::INVALID_CREDENTIALS), self::errorCodes($wrong));
    }

    public function testExelyEndpointOnlyAcceptsPost()
    {
        $this->assertSame(405, $this->dispatch('GET', '/exely')->getHttpResponseCode());
    }

    public function testWebhookRequiresAValidSignature()
    {
        $this->useTestDoubles();

        $this->assertSame(401, $this->postWebhook('reservation.created', Test_Fixtures::reservation(), 'wrong')->getHttpResponseCode());
        $this->assertSame(401, $this->dispatch('POST', '/webhook/unit-travel', '{}')->getHttpResponseCode()); // ZF1 default route
        $this->assertSame(0, $this->notifier->calls);
    }

    public function testWebhookStoresTheReservationAndNotifiesExely()
    {
        $this->useTestDoubles();

        $this->assertSame(200, $this->postWebhook('reservation.created', Test_Fixtures::reservation())->getHttpResponseCode());
        $this->assertSame(200, $this->postWebhook('reservation.created', Test_Fixtures::reservation())->getHttpResponseCode());
        $this->assertSame(1, $this->notifier->calls); // the repeated delivery is not a new version

        $body = Exely_Json::encode(array('username' => 'exely', 'password' => 'secret', 'action' => 'get-bookings', 'data' => new stdClass()));
        $result = Exely_Json::decode($this->dispatch('POST', '/exely', $body)->getBody());
        $this->assertSame('UT-9F3A21', $result['data']['bookings'][0]['number']);
    }

    public function testWebhookRejectsAnInvalidReservation()
    {
        $this->useTestDoubles();

        $response = $this->postWebhook('reservation.created', array('reservation_id' => 'UT-1'));

        $this->assertSame(422, $response->getHttpResponseCode());
    }

    public function testHealthAndUnknownRoutes()
    {
        $this->assertSame('{"status":"ok"}', $this->dispatch('GET', '/health')->getBody());
        $missing = $this->dispatch('GET', '/nowhere');
        $this->assertSame(404, $missing->getHttpResponseCode());
        $this->assertSame('{"error":"not found"}', $missing->getBody());
    }
}
