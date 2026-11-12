<?php
/**
 * In-memory Bakuun Buyer API (Push) for the calls the adapter makes.
 */
class Test_Fake_Bakuun
{
    const HOST = 'https://bakuun.test';

    public $properties = array();
    public $listing = array();
    /** @var array action => list of array($status, $body) to answer the next calls of that action with */
    public $failures = array();
    /** @var array partner resID => array('status' => confirmed|cancelled, 'rooms' => int) */
    public $bookings = array();
    /** @var array every request body, in order */
    public $requests = array();
    /** Confirm bookings but lose the answer (simulates a timeout after Bakuun committed). */
    public $loseConfirmAnswer = false;
    protected $_tokens = array();

    public function __construct()
    {
        $this->properties['HTL1.1'] = Test_Fixtures::bakuunProperty();
    }

    public static function urls()
    {
        return array(
            'getProperty' => self::HOST . '/v2/getproperty/MPB1/KEY',
            'pushProperty' => self::HOST . '/v2/push-property/MPB1/KEY',
            'getListing' => self::HOST . '/v2/getlisting/MPB1/KEY',
            'readProperties' => self::HOST . '/ratedockAPI/MPB1/readproperties',
            'booking' => self::HOST . '/v2/booking/MPB1/KEY',
            'readBooking' => self::HOST . '/v1/read-booking/MPB1/KEY',
        );
    }

    public function client()
    {
        return new Bakuun_Client(array(
            'username' => 'buyer', 'password' => 'buyer-secret', 'partnerId' => 'MPB1', 'partnerName' => 'Unit.Travel',
            'urls' => self::urls(), 'adapter' => new Test_Fake_HttpAdapter(array($this, 'handle')),
        ));
    }

    /**
     * Requests of one action, in order.
     */
    public function requestsOf($action)
    {
        $found = array();
        foreach ($this->requests as $request) {
            if ($request['action'] === $action) {
                $found[] = $request;
            }
        }
        return $found;
    }

    public function handle($method, $url, array $headers, $body)
    {
        $data = UnitTravel_Json::decode($body);
        $action = isset($data['action']) ? $data['action'] : '';
        $credentials = isset($data['authentication']) ? $data['authentication'] : $data;
        $this->requests[] = array('action' => $action, 'url' => $url, 'body' => $data);
        if ($credentials['username'] !== 'buyer' || $credentials['password'] !== 'buyer-secret') {
            return self::_error(410, 'Unauthorized user', 401);
        }
        $key = $action === 'getBooking' ? (isset($data['hotelReservation'][0]['BookingTokenCode']) ? 'confirm' : 'prebook') : $action;
        if (!empty($this->failures[$key])) {
            return array_shift($this->failures[$key]);
        }
        switch ($action) {
            case 'GetPropertyInfo':
                $id = $credentials['propertyId'];
                return isset($this->properties[$id])
                    ? array(200, array('success' => true, 'propertyData' => $this->properties[$id]))
                    : self::_error(447, "Property ID doesn't exist");
            case 'GetListing':
                return array(200, array('status' => 'Success', 'totalListing' => count($this->listing), 'listingData' => $this->listing));
            case 'pushPropertyInfo':
                return array(200, array('success' => true));
            case 'getBooking':
                return $key === 'prebook' ? $this->_preBook($data) : $this->_confirm($data);
            case 'pushModifyBooking':
                return $this->_change($data, 'Reservation Modify Success', 'confirmed');
            case 'pushCancelBooking':
                return $this->_change($data, 'Reservation Cancel Success', 'cancelled');
            case 'readSingleBookingRQ':
                $resId = $data['ResID'];
                if (!isset($this->bookings[$resId])) {
                    return self::_error(477, 'No Booking to retrieve');
                }
                return array(200, array('success' => true, 'reservation' => array(array(
                    'yourBookingId' => $resId, 'ourBookingId' => 'BK-' . $resId, 'statusBooking' => $this->bookings[$resId]['status'],
                ))));
        }
        return self::_error(430, 'Invalid action');
    }

    protected function _preBook(array $data)
    {
        $reservation = $data['hotelReservation'][0];
        $token = 'BTC' . (count($this->_tokens) + 1);
        $this->_tokens[$token] = count($reservation['roomStays']);
        return array(200, array('success' => true, 'data' => array(array('hotelId' => $reservation['hotelCode'], 'BookingTokenCode' => $token))));
    }

    protected function _confirm(array $data)
    {
        $reservation = $data['hotelReservation'][0];
        $token = $reservation['BookingTokenCode'];
        if (!isset($this->_tokens[$token])) {
            return self::_error(424, 'Invalid Reservation Token ID');
        }
        $resId = $reservation['resID'];
        if (isset($this->bookings[$resId])) {
            return self::_error(448, 'Reservation ID already exits');
        }
        $this->bookings[$resId] = array('status' => 'confirmed', 'rooms' => $this->_tokens[$token]);
        if ($this->loseConfirmAnswer) {
            return self::_error(504, 'Endpoint request timed-out', 504);
        }
        return array(200, self::_roomResults($resId, $this->_tokens[$token], 'Reservation Success'));
    }

    protected function _change(array $data, $message, $status)
    {
        $resId = $data['hotelReservation'][0]['resID'];
        if (!isset($this->bookings[$resId])) {
            return self::_error(476, 'Reservation ID not found');
        }
        if ($this->bookings[$resId]['status'] === 'cancelled') {
            return self::_error(438, 'Reservation already cancelled');
        }
        $this->bookings[$resId]['status'] = $status;
        return array(200, self::_roomResults($resId, $this->bookings[$resId]['rooms'], $message));
    }

    protected static function _roomResults($resId, $rooms, $message)
    {
        $results = array();
        for ($room = 1; $room <= $rooms; $room++) {
            $results[] = array('success' => true, 'message' => $message, 'roomIndex' => (string) $room, 'reservationID' => "$resId-$room");
        }
        return $results;
    }

    protected static function _error($code, $message, $httpStatus = 400)
    {
        return array($httpStatus, array('success' => false, 'errors' => array(array('code' => $code, 'message' => $message))));
    }
}
