<?php
require_once APPLICATION_PATH . '/../scripts/bakuun.php';

/**
 * The ZF1 application end to end: bootstrap, routes, controllers and the command line.
 */
class ApplicationTest extends Test_TestCase
{
    const WEBHOOK_SECRET = 'whsec';

    /** @var Zend_Application */
    protected $application;

    protected function setUp()
    {
        parent::setUp();
        Zend_Controller_Front::getInstance()->resetInstance();
        $this->application = new Zend_Application('testing', array(
            'phpSettings' => array('date.timezone' => 'UTC'),
            'bootstrap' => array('path' => APPLICATION_PATH . '/Bootstrap.php', 'class' => 'Bootstrap'),
            'appnamespace' => '',
            'autoloaderNamespaces' => array('Bakuun_', 'UnitTravel_'),
            'resources' => array(
                'frontController' => array('controllerDirectory' => APPLICATION_PATH . '/controllers', 'params' => array('displayExceptions' => 0)),
                'db' => array('adapter' => 'pdo_sqlite', 'params' => array('dbname' => ':memory:')),
            ),
            'bakuun' => array(
                'inbound' => array('username' => self::INBOUND_USER, 'password' => self::INBOUND_PASSWORD),
                'username' => 'buyer', 'password' => 'buyer-secret', 'partnerId' => 'MPB1', 'partnerName' => 'Unit.Travel',
                'url' => Test_Fake_Bakuun::urls(),
            ),
            'unitTravel' => array('baseUrl' => 'http://127.0.0.1:9/partner/v1', 'apiKey' => 'key', 'webhookSecret' => self::WEBHOOK_SECRET, 'pullReservations' => ''),
        ));
        $this->application->bootstrap();
        $bootstrap = $this->application->getBootstrap();
        $bootstrap->getResource('db')->getConnection()->exec(file_get_contents(APPLICATION_PATH . '/../data/schema.sqlite.sql'));
        // The configured clients would go to the network; swap in services built on the fakes and this database.
        $this->db = $bootstrap->getResource('db');
        $this->properties = $bootstrap->getResource('bakuunProperties');
        $this->mappings = $bootstrap->getResource('bakuunMappings');
        $this->restrictions = $bootstrap->getResource('bakuunRestrictions');
        $this->bookings = $bootstrap->getResource('bakuunBookings');
        $container = $bootstrap->getContainer();
        $container->bakuunservice = $this->service();
        $container->bakuunbookingsync = $this->sync();
        $container->bakuunonboarding = $this->onboarding();
    }

    protected function tearDown()
    {
        Zend_Controller_Front::getInstance()->resetInstance();
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
        $front->setParam('bootstrap', $this->application->getBootstrap())->setRequest($request)->setResponse($response)->returnResponse(true);
        $front->dispatch($request, $response);
        return $response;
    }

    protected function webhook(array $reservation, $secret = self::WEBHOOK_SECRET)
    {
        $body = UnitTravel_Json::encode(array('event' => 'reservation.created', 'reservation' => $reservation));
        return $this->dispatch('POST', '/webhooks/unit-travel', $body, array(UnitTravel_Signature::HEADER => UnitTravel_Signature::sign($secret, $body)));
    }

    protected function cli(array $args)
    {
        ob_start();
        $stdout = fopen('php://output', 'w');
        $code = bakuun_cli(array_merge(array('bakuun.php'), $args), $this->application, $stdout, $stdout);
        fclose($stdout);
        return array($code, ob_get_clean());
    }

    public function testBootstrapBuildsEveryResource()
    {
        $application = new Zend_Application('testing', array(
            'bootstrap' => array('path' => APPLICATION_PATH . '/Bootstrap.php', 'class' => 'Bootstrap'),
            'autoloaderNamespaces' => array('Bakuun_', 'UnitTravel_'),
            'resources' => array(
                'frontController' => array('controllerDirectory' => APPLICATION_PATH . '/controllers'),
                'db' => array('adapter' => 'pdo_sqlite', 'params' => array('dbname' => ':memory:')),
            ),
        ));
        Zend_Controller_Front::getInstance()->resetInstance();
        $bootstrap = $application->bootstrap()->getBootstrap();

        $this->assertInstanceOf('Bakuun_Service', $bootstrap->getResource('bakuunService'));
        $this->assertInstanceOf('Bakuun_BookingSync', $bootstrap->getResource('bakuunBookingSync'));
        $this->assertInstanceOf('Bakuun_Onboarding', $bootstrap->getResource('bakuunOnboarding'));
    }

    public function testBakuunEndpointOnEveryPathBelowBakuun()
    {
        $this->givenMappedProperty();
        $body = UnitTravel_Json::encode(array(
            'username' => self::INBOUND_USER, 'password' => self::INBOUND_PASSWORD, 'hotelId' => Test_Fixtures::HOTEL, 'action' => 'InvCountUpdate',
            'data' => array(array('startDateYmd' => '2026-07-01', 'endDateYmd' => '2026-07-01', 'roomCode' => 'RX.1.1', 'roomCount' => '4')),
        ));

        foreach (array('/bakuun', '/bakuun/updateroomavailrq/MPBXX/invupdate') as $path) {
            $response = $this->dispatch('POST', $path, $body);
            $this->assertSame(200, $response->getHttpResponseCode(), $path);
            $this->assertSame('{"success":true,"warnings":[],"errors":[]}', $response->getBody());
        }
        $this->assertCount(2, $this->ut->ariMessages);
    }

    public function testBakuunEndpointErrors()
    {
        $unauthorised = $this->dispatch('POST', '/bakuun', '{"action":"InvCountUpdate","username":"x","password":"y"}');
        $this->assertSame(401, $unauthorised->getHttpResponseCode());
        $this->assertSame(array(Bakuun_Code::UNAUTHORISED), self::errorCodes(UnitTravel_Json::decode($unauthorised->getBody())));

        $this->assertSame(400, $this->dispatch('POST', '/bakuun', 'not json')->getHttpResponseCode());
        $this->assertSame(405, $this->dispatch('GET', '/bakuun')->getHttpResponseCode());
        $this->assertSame(404, $this->dispatch('GET', '/nowhere')->getHttpResponseCode());
        $this->assertSame('{"status":"ok"}', $this->dispatch('GET', '/health')->getBody());
    }

    public function testWebhookDeliversTheReservationToBakuun()
    {
        $this->givenMappedProperty();

        $this->assertSame(401, $this->webhook(Test_Fixtures::reservation(), 'wrong')->getHttpResponseCode());
        $this->assertSame(200, $this->webhook(Test_Fixtures::reservation())->getHttpResponseCode());

        $this->assertSame('confirmed', $this->bakuun->bookings['UT-9F3A21']['status']);
        $this->assertSame(422, $this->webhook(array('reservation_id' => 'UT-1'))->getHttpResponseCode());
    }

    public function testWebhookAsksUnitTravelToRetryWhileBakuunIsDown()
    {
        $this->givenMappedProperty();
        $this->bakuun->failures['prebook'] = array(
            array(503, array('success' => false, 'errors' => array(array('code' => 503, 'message' => 'Service unavailable')))),
        );

        $this->assertSame(503, $this->webhook(Test_Fixtures::reservation())->getHttpResponseCode());
        $this->assertSame(200, $this->webhook(Test_Fixtures::reservation())->getHttpResponseCode());
    }

    public function testOnboardingFromTheCommandLine()
    {
        list($code, $output) = $this->cli(array('link', Test_Fixtures::HOTEL, '123'));
        $this->assertSame(0, $code);
        $this->assertContains('pending', $output);

        list($code, $output) = $this->cli(array('automap', Test_Fixtures::HOTEL));
        $this->assertSame(1, $code); // the hotel has not confirmed the connection yet

        $this->ut->activate(123);
        list($code, $output) = $this->cli(array('automap', Test_Fixtures::HOTEL, '--save'));
        $this->assertSame(0, $code);
        $this->assertContains('RX.1.2.1', $output);
        $this->assertContains('mapped', $output);

        list($code, $output) = $this->cli(array('activate', Test_Fixtures::HOTEL));
        $this->assertSame(0, $code);
        $this->assertContains('active', $output);

        list($code, $output) = $this->cli(array('mappings', Test_Fixtures::HOTEL));
        $this->assertContains('13510', $output);
        $this->assertNotContains('Not mapped', $output);

        list($code) = $this->cli(array('nonsense'));
        $this->assertSame(2, $code);
    }
}
