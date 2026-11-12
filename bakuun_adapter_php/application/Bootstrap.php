<?php
/**
 * Builds the adapter from application.ini: clients, stores, the Bakuun service, booking sync and onboarding.
 */
class Bootstrap extends Zend_Application_Bootstrap_Bootstrap
{
    /**
     * Bakuun configures one URL per action, so every path under /bakuun is the same endpoint.
     */
    protected function _initBakuunRoutes()
    {
        $this->bootstrap('frontController');
        $router = $this->getResource('frontController')->getRouter();
        $router->addRoute('bakuun', new Zend_Controller_Router_Route('bakuun/*', array('controller' => 'bakuun', 'action' => 'index')));
        $router->addRoute('unitTravelWebhook', new Zend_Controller_Router_Route_Static(
            'webhooks/unit-travel', array('controller' => 'webhook', 'action' => 'unit-travel')
        ));
    }

    protected function _initBakuunLog()
    {
        $options = $this->_section('log');
        $writer = !empty($options['path']) ? new Zend_Log_Writer_Stream($options['path']) : new Zend_Log_Writer_Null();
        return new Zend_Log($writer);
    }

    protected function _initUnitTravelClient()
    {
        $options = $this->_section('unitTravel');
        return new UnitTravel_Client(
            self::_value($options, 'baseurl', 'https://api.test.unit.travel/partner/v1'),
            self::_value($options, 'apikey', ''),
            array('timeout' => self::_value($options, 'timeout', 30), 'caFile' => self::_value($options, 'cafile', null))
        );
    }

    protected function _initBakuunClient()
    {
        $options = $this->_section('bakuun');
        $configured = isset($options['url']) ? array_change_key_case((array) $options['url'], CASE_LOWER) : array();
        $urls = array();
        foreach (Bakuun_Client::urlNames() as $name) {
            $urls[$name] = self::_value($configured, strtolower($name), '');
        }
        return new Bakuun_Client(array(
            'username' => self::_value($options, 'username', ''),
            'password' => self::_value($options, 'password', ''),
            'partnerId' => self::_value($options, 'partnerid', ''),
            'partnerName' => self::_value($options, 'partnername', ''),
            'urls' => $urls,
            'timeout' => self::_value($options, 'timeout', 30),
            'caFile' => self::_value($options, 'cafile', null),
        ));
    }

    protected function _initBakuunProperties()
    {
        $this->bootstrap('db');
        return new Bakuun_Store_Properties($this->getResource('db'));
    }

    protected function _initBakuunMappings()
    {
        $this->bootstrap('db');
        return new Bakuun_Store_Mappings($this->getResource('db'));
    }

    protected function _initBakuunRestrictions()
    {
        $this->bootstrap('db');
        return new Bakuun_Store_Restrictions($this->getResource('db'));
    }

    protected function _initBakuunBookings()
    {
        $this->bootstrap('db');
        return new Bakuun_Store_Bookings($this->getResource('db'));
    }

    protected function _initBakuunService()
    {
        $this->bootstrap(array('unitTravelClient', 'bakuunProperties', 'bakuunMappings', 'bakuunRestrictions', 'bakuunLog'));
        $bakuun = $this->_section('bakuun');
        $inbound = isset($bakuun['inbound']) ? array_change_key_case((array) $bakuun['inbound'], CASE_LOWER) : array();
        $ari = $this->_section('ari');
        return new Bakuun_Service(
            array(
                'inboundUsername' => self::_value($inbound, 'username', ''),
                'inboundPassword' => self::_value($inbound, 'password', ''),
                'ari' => array(
                    'priceField' => self::_value($ari, 'pricefield', 'amountBeforeTax'),
                    'rejectBarRates' => self::_flag($ari, 'rejectbarrates', false),
                ),
                'sender' => array(
                    'statusWaitSeconds' => (float) self::_value($ari, 'statuswaitseconds', 10),
                    'statusPollInterval' => (float) self::_value($ari, 'statuspollinterval', 0.5),
                    'maxItemsPerMessage' => (int) self::_value($ari, 'maxitemspermessage', 1000),
                ),
            ),
            $this->getResource('unitTravelClient'),
            $this->getResource('bakuunProperties'),
            $this->getResource('bakuunMappings'),
            $this->getResource('bakuunRestrictions'),
            $this->getResource('bakuunLog')
        );
    }

    protected function _initBakuunBookingSync()
    {
        $this->bootstrap(array('bakuunClient', 'unitTravelClient', 'bakuunBookings', 'bakuunProperties', 'bakuunMappings', 'bakuunLog'));
        $unitTravel = $this->_section('unitTravel');
        $bookings = $this->_section('bookings');
        return new Bakuun_BookingSync(
            array(
                'pullReservations' => self::_flag($unitTravel, 'pullreservations', true),
                'ackWithDetails' => self::_flag($unitTravel, 'ackwithdetails', false),
                'maxAttempts' => (int) self::_value($bookings, 'maxattempts', 20),
                'mapper' => array(
                    'checkInTime' => self::_value($bookings, 'checkintime', '14:00'),
                    'checkOutTime' => self::_value($bookings, 'checkouttime', '12:00'),
                    'paymentMethod' => (string) self::_value($bookings, 'paymentmethod', ''),
                    'taxType' => self::_value($bookings, 'taxtype', 'VAT'),
                    'taxIncluded' => self::_flag($bookings, 'taxincluded', false) ? '1' : '0',
                    'taxPrice' => self::_value($bookings, 'taxprice', '0.00'),
                    'taxDescription' => self::_value($bookings, 'taxdescription', 'Taxes are not included in the price'),
                ),
            ),
            $this->getResource('bakuunClient'),
            $this->getResource('unitTravelClient'),
            $this->getResource('bakuunBookings'),
            $this->getResource('bakuunProperties'),
            $this->getResource('bakuunMappings'),
            $this->getResource('bakuunLog')
        );
    }

    protected function _initBakuunOnboarding()
    {
        $this->bootstrap(array('bakuunClient', 'unitTravelClient', 'bakuunProperties', 'bakuunMappings'));
        return new Bakuun_Onboarding(
            $this->getResource('bakuunClient'),
            $this->getResource('unitTravelClient'),
            $this->getResource('bakuunProperties'),
            $this->getResource('bakuunMappings')
        );
    }

    /**
     * An application.ini section with lower-cased keys (Zend_Application only lower-cases the top level).
     */
    protected function _section($name)
    {
        return array_change_key_case((array) $this->getOption($name), CASE_LOWER);
    }

    protected static function _value(array $options, $name, $default)
    {
        return isset($options[$name]) && $options[$name] !== '' ? $options[$name] : $default;
    }

    /**
     * Zend_Config_Ini turns an unquoted false/off/no into "", so "" means false here.
     */
    protected static function _flag(array $options, $name, $default)
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }
        return !in_array(strtolower((string) $options[$name]), array('', '0', 'false', 'off', 'no'), true);
    }
}
