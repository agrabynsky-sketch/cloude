<?php
/**
 * Calls the adapter makes to Bakuun (Buyer API (Push)): onboarding and bookings.
 *
 * Bakuun gives every partner its own URLs (with the partner id and an account key in the
 * path, and a separate set for B-Aggregate and B-MarketPlace), so each one is configured.
 */
class Bakuun_Client
{
    const USER_AGENT = 'bakuun-adapter-php/0.1';

    protected static $_urlNames = array('getProperty', 'pushProperty', 'getListing', 'readProperties', 'booking', 'readBooking');

    protected $_options;
    protected $_adapter;

    /**
     * @param array $options username, password, partnerId, partnerName, urls (getProperty, pushProperty,
     *                       getListing, readProperties, booking, readBooking), timeout, caFile, adapter
     */
    public function __construct(array $options)
    {
        $this->_options = array_merge(array(
            'username' => '', 'password' => '', 'partnerId' => '', 'partnerName' => '', 'urls' => array(), 'timeout' => 30,
        ), $options);
        $this->_adapter = isset($options['adapter'])
            ? $options['adapter']
            : UnitTravel_Client::secureAdapter($this->_options['timeout'], isset($options['caFile']) ? $options['caFile'] : null);
    }

    /**
     * Bakuun's static data of a property: name, address, currency, room types and their rate plans.
     */
    public function getPropertyInfo($propertyId)
    {
        $response = $this->_call('getProperty', array(
            'action' => 'GetPropertyInfo',
            'authentication' => array_merge($this->_credentials(), array(
                'propertyId' => (string) $propertyId, 'partnerId' => $this->_options['partnerId'],
            )),
        ));
        if (!isset($response['propertyData']) || !is_array($response['propertyData'])) {
            throw new Bakuun_Exception(Bakuun_Code::INVALID_DATA, 'GetPropertyInfo returned no propertyData');
        }
        return $response['propertyData'];
    }

    /**
     * Properties mapped to this partner account with their mapping status.
     */
    public function getListing()
    {
        $response = $this->_call('getListing', array(
            'action' => 'GetListing',
            'authentication' => array_merge($this->_credentials(), array('partnerId' => $this->_options['partnerId'])),
        ));
        return isset($response['listingData']) && is_array($response['listingData']) ? $response['listingData'] : array();
    }

    public function readProperties()
    {
        $response = $this->_call('readProperties', array_merge($this->_credentials(), array(
            'partnerId' => $this->_options['partnerId'], 'action' => 'readProperties',
        )));
        return isset($response['propertyData']) && is_array($response['propertyData']) ? $response['propertyData'] : array();
    }

    /**
     * Send the property's mapping and its status (Active, Suspended or Disconnected) to Bakuun.
     */
    public function pushProperty($propertyId, $mappingStatus, array $propertyData)
    {
        $this->_call('pushProperty', array_merge($this->_credentials(), array(
            'propertyId' => (string) $propertyId,
            'partnerId' => $this->_options['partnerId'],
            'action' => 'pushPropertyInfo',
            'mappingStatus' => $mappingStatus,
            'propertyData' => $propertyData,
        )));
    }

    /**
     * Pre-booking: Bakuun checks the booking and returns a token valid for 2 minutes.
     *
     * @return string BookingTokenCode
     */
    public function preBook(array $hotelReservation)
    {
        $response = $this->_call('booking', $this->_bookingEnvelope('getBooking', $hotelReservation));
        $token = isset($response['data'][0]['BookingTokenCode']) ? (string) $response['data'][0]['BookingTokenCode'] : '';
        if ($token === '') {
            throw new Bakuun_Exception(Bakuun_Code::TOKEN_MISSING, 'Pre-booking returned no BookingTokenCode');
        }
        return $token;
    }

    /**
     * Acknowledgement: confirm a pre-booking with the partner reservation id.
     *
     * @return string[] Bakuun reservation id of every room
     */
    public function confirmBooking($hotelCode, $hotelName, $token, $resId)
    {
        return $this->_roomResults($this->_call('booking', $this->_bookingEnvelope('getBooking', array(
            'hotelCode' => $hotelCode, 'hotelName' => $hotelName, 'resStatus' => 'New',
            'BookingTokenCode' => $token, 'resID' => (string) $resId,
        ))));
    }

    /**
     * @return string[] Bakuun reservation id of every room
     */
    public function modifyBooking(array $hotelReservation)
    {
        return $this->_roomResults($this->_call('booking', $this->_bookingEnvelope('pushModifyBooking', $hotelReservation)));
    }

    /**
     * @return string[] Bakuun reservation id of every room
     */
    public function cancelBooking($hotelCode, $hotelName, $resId)
    {
        return $this->_roomResults($this->_call('booking', $this->_bookingEnvelope('pushCancelBooking', array(
            'hotelCode' => $hotelCode, 'hotelName' => $hotelName, 'resStatus' => 'cancel', 'resID' => (string) $resId,
        ))));
    }

    /**
     * Bakuun's record of one partner reservation, or null when it has none.
     *
     * @return array|null with yourBookingId, ourBookingId, statusBooking
     */
    public function readBooking($resId)
    {
        try {
            $response = $this->_call('readBooking', array_merge($this->_credentials(), array(
                'partnerId' => $this->_options['partnerId'], 'action' => 'readSingleBookingRQ', 'ResID' => (string) $resId,
            )));
        } catch (Bakuun_Exception $e) {
            if (in_array($e->getErrorCode(), array(Bakuun_Code::NO_BOOKING, Bakuun_Code::RESERVATION_NOT_FOUND), true)) {
                return null;
            }
            throw $e;
        }
        return isset($response['reservation'][0]) && is_array($response['reservation'][0]) ? $response['reservation'][0] : null;
    }

    protected function _credentials()
    {
        return array('username' => $this->_options['username'], 'password' => $this->_options['password']);
    }

    protected function _bookingEnvelope($action, array $hotelReservation)
    {
        return array_merge($this->_credentials(), array(
            'partnerId' => $this->_options['partnerId'],
            'partnerName' => $this->_options['partnerName'],
            'action' => $action,
            'hotelReservation' => array($hotelReservation),
        ));
    }

    /**
     * Booking calls answer with one result per room; any failed room fails the call.
     */
    protected function _roomResults($response)
    {
        $results = isset($response[0]) ? $response : array($response);
        $ids = array();
        foreach ($results as $result) {
            if (!is_array($result) || empty($result['success'])) {
                $message = is_array($result) && !empty($result['message']) ? $result['message'] : 'room not accepted';
                throw new Bakuun_Exception(Bakuun_Code::PROCESSING_ERROR, $message);
            }
            if (!empty($result['reservationID'])) {
                $ids[] = (string) $result['reservationID'];
            }
        }
        return $ids;
    }

    /**
     * @return array decoded JSON body
     * @throws Bakuun_Exception
     */
    protected function _call($urlName, array $body)
    {
        $url = isset($this->_options['urls'][$urlName]) ? trim($this->_options['urls'][$urlName]) : '';
        if ($url === '') {
            throw new Bakuun_Exception(Bakuun_Code::INVALID_REQUEST, "Bakuun URL '$urlName' is not configured");
        }
        try {
            $client = new Zend_Http_Client($url, array(
                'adapter' => $this->_adapter, 'timeout' => (int) $this->_options['timeout'], 'useragent' => self::USER_AGENT,
            ));
            $client->setHeaders(array('Accept' => 'application/json'));
            $client->setRawData(UnitTravel_Json::encode($body), 'application/json');
            $response = $client->request('POST');
        } catch (Zend_Exception $e) {
            throw new Bakuun_Exception(0, $e->getMessage(), 0, $e);
        }
        $decoded = UnitTravel_Json::decode($response->getBody());
        $status = $response->getStatus();
        if (is_array($decoded) && array_key_exists('success', $decoded) && $decoded['success'] === false) {
            $error = isset($decoded['errors'][0]) && is_array($decoded['errors'][0]) ? $decoded['errors'][0] : array();
            throw new Bakuun_Exception(
                isset($error['code']) ? $error['code'] : 0,
                isset($error['message']) ? $error['message'] : "Bakuun answered HTTP $status",
                $status
            );
        }
        if ($status >= 400 || !is_array($decoded)) {
            throw new Bakuun_Exception(0, "Bakuun answered HTTP $status", $status);
        }
        return $decoded;
    }

    public static function urlNames()
    {
        return self::$_urlNames;
    }
}
