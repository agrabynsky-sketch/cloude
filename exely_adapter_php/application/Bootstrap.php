<?php
/**
 * Builds the adapter from application.ini: Unit.Travel client, booking store, Exely service.
 */
class Bootstrap extends Zend_Application_Bootstrap_Bootstrap
{
    /**
     * Same webhook path as the Python adapter; ZF1's default /webhook/unit-travel keeps working.
     */
    protected function _initExelyRoutes()
    {
        $this->bootstrap('frontController');
        $this->getResource('frontController')->getRouter()->addRoute('unitTravelWebhook', new Zend_Controller_Router_Route_Static(
            'webhooks/unit-travel', array('controller' => 'webhook', 'action' => 'unit-travel')
        ));
    }

    protected function _initExelyLog()
    {
        $options = $this->_section('log');
        if (!empty($options['path'])) {
            $writer = new Zend_Log_Writer_Stream($options['path']);
        } else {
            $writer = new Zend_Log_Writer_Null();
        }
        return new Zend_Log($writer);
    }

    protected function _initExelyClient()
    {
        $options = $this->_section('unitTravel');
        return new Exely_UnitTravel_Client(
            self::_value($options, 'baseurl', 'https://api.test.unit.travel/partner/v1'),
            self::_value($options, 'apikey', ''),
            array('timeout' => self::_value($options, 'timeout', 30), 'caFile' => self::_value($options, 'cafile', null))
        );
    }

    protected function _initExelyService()
    {
        $this->bootstrap(array('db', 'exelyLog', 'exelyClient'));
        $exely = $this->_section('exely');
        $unitTravel = $this->_section('unitTravel');
        $ari = $this->_section('ari');
        $bookings = $this->_section('bookings');
        $options = array(
            'exelyUsername' => self::_value($exely, 'username', ''),
            'exelyPassword' => self::_value($exely, 'password', ''),
            'pullReservations' => self::_flag($unitTravel, 'pullreservations', true),
            'ackWithDetails' => self::_flag($unitTravel, 'ackwithdetails', false),
            'ariStatusWaitSeconds' => (float) self::_value($ari, 'statuswaitseconds', 10),
            'ariStatusPollInterval' => (float) self::_value($ari, 'statuspollinterval', 0.5),
            'ariMaxItemsPerMessage' => (int) self::_value($ari, 'maxitemspermessage', 1000),
            'bookingRelevanceDays' => (int) self::_value($bookings, 'relevancedays', 60),
        );
        $store = new Exely_BookingStore($this->getResource('db'));
        return new Exely_Service($options, $this->getResource('exelyClient'), $store, $this->getResource('exelyLog'));
    }

    protected function _initExelyNotifier()
    {
        $this->bootstrap('exelyLog');
        $exely = $this->_section('exely');
        return new Exely_Notifier(self::_value($exely, 'notifyurl', ''), null, $this->getResource('exelyLog'));
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
