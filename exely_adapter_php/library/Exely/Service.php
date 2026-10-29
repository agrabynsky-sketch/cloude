<?php
/**
 * Exely Channel Manager requests → Unit.Travel Partner API calls.
 *
 * Every PHP request is a separate process, so nothing is cached between Exely calls:
 * the connection is looked up on each `update`, which Exely sends one at a time anyway.
 */
class Exely_Service
{
    const CONNECTION_NOTE = 'Exely Channel Manager';

    protected static $_actions = array(
        'get-rooms-and-rate-plans' => '_getRoomsAndRatePlans',
        'update' => '_update',
        'get-bookings' => '_getBookings',
        'confirm-bookings' => '_confirmBookings',
    );

    protected static $_finalAriStatuses = array('applied', 'failed', 'skipped');

    protected static $_defaults = array(
        'exelyUsername' => '',
        'exelyPassword' => '',
        // Pull /reservations of active connections on every get-bookings call,
        // on top of (or instead of) the reservation webhook.
        'pullReservations' => true,
        // Send Exely's confirmation in the body of POST /reservations/{id}/acknowledge.
        // Partner API v1.9.0 defines no body there, so this is off by default.
        'ackWithDetails' => false,
        // POST /ari is asynchronous: how long `update` waits for the result before answering.
        'ariStatusWaitSeconds' => 10,
        'ariStatusPollInterval' => 0.5,
        'ariMaxItemsPerMessage' => 1000,
        // Exely treats bookings as relevant until two months after check-out.
        'bookingRelevanceDays' => 60,
    );

    protected $_options;
    protected $_client;
    protected $_store;
    protected $_log;
    protected $_today;
    protected $_lastRevision = 0;

    /**
     * @param array                   $options see $_defaults
     * @param Exely_UnitTravel_Client $client
     * @param Exely_BookingStore      $store
     * @param Zend_Log|null           $log
     * @param string|null             $today   Y-m-d, for tests; defaults to the current UTC date
     */
    public function __construct(
        array $options, Exely_UnitTravel_Client $client, Exely_BookingStore $store, Zend_Log $log = null, $today = null
    ) {
        $this->_options = array_merge(self::$_defaults, $options);
        $this->_client = $client;
        $this->_store = $store;
        $this->_log = $log ? $log : new Zend_Log(new Zend_Log_Writer_Null());
        $this->_today = $today;
    }

    /**
     * Answer one Exely request; never throws.
     *
     * @param  mixed $payload decoded JSON body
     * @return array response body
     */
    public function handle($payload)
    {
        try {
            $envelope = Exely_Request::envelope($payload);
        } catch (Exely_Failure $e) {
            return Exely_Response::failure($e->getErrors());
        }
        $userOk = Exely_Values::equalsConstantTime($this->_options['exelyUsername'], $envelope['username']);
        $passwordOk = Exely_Values::equalsConstantTime($this->_options['exelyPassword'], $envelope['password']);
        if (!$userOk || !$passwordOk || $this->_options['exelyPassword'] === '') {
            return Exely_Response::failure(array(new Exely_Issue(Exely_Code::INVALID_CREDENTIALS, 'Invalid username or password')));
        }
        $action = $envelope['action'];
        if (!isset(self::$_actions[$action])) {
            return Exely_Response::failure(array(new Exely_Issue(Exely_Code::UNKNOWN_ACTION, "Unknown action '$action'")));
        }
        $method = self::$_actions[$action];
        try {
            $result = $this->$method($envelope['data']);
        } catch (Exely_Failure $e) {
            $this->_log->info("Exely $action failed: " . $e->getMessage());
            return Exely_Response::failure($e->getErrors(), $e->getWarnings());
        } catch (Exely_UnitTravel_Exception $e) {
            $this->_log->warn("Unit.Travel call for Exely $action failed: $e");
            return Exely_Response::failure(self::issuesFromUnitTravel($e));
        } catch (Exception $e) {
            $this->_log->err("Exely $action crashed: " . get_class($e) . ': ' . $e->getMessage());
            return Exely_Response::failure(array(new Exely_Issue(Exely_Code::INTERNAL_ERROR, 'Internal adapter error')));
        }
        return Exely_Response::success($result[0], $result[1]);
    }

    /**
     * Store a reservation pushed by the Unit.Travel webhook.
     *
     * @return bool true when Exely should be notified
     * @throws Exely_InvalidReservationException
     */
    public function acceptReservation(array $reservation)
    {
        return $this->_store->upsert($reservation);
    }

    // get-rooms-and-rate-plans

    protected function _getRoomsAndRatePlans(array $data)
    {
        $propertyId = self::_propertyId(Exely_Request::hotelId($data));
        // The property asks Exely to connect here, so this is where the Unit.Travel connection is requested.
        $connection = $this->_activeConnection($propertyId, true);
        $rooms = $this->_client->getRooms($propertyId);
        $ratePlans = $this->_client->getRatePlans($propertyId);
        $built = Exely_Catalog::build($rooms, $ratePlans, self::_scope($connection));
        return array(array('hotelId' => (string) $propertyId, 'roomsAndRatePlans' => $built[0]), $built[1]);
    }

    protected function _activeConnection($propertyId, $requestIfMissing)
    {
        try {
            if ($requestIfMissing) {
                $connection = $this->_client->createConnection($propertyId, self::CONNECTION_NOTE);
            } else {
                $connection = self::_pickConnection($this->_client->listConnections(null, $propertyId));
            }
        } catch (Exely_UnitTravel_Exception $e) {
            if ($e->getStatus() === 404) {
                throw Exely_Failure::of(Exely_Code::HOTEL_NOT_FOUND, "Property $propertyId does not exist in Unit.Travel");
            }
            throw $e;
        }
        if (!is_array($connection) || !isset($connection['status']) || $connection['status'] !== 'active') {
            throw Exely_Failure::of(Exely_Code::HOTEL_NOT_CONNECTED, self::_notActiveMessage($propertyId, $connection));
        }
        return $connection;
    }

    // update

    protected function _update(array $data)
    {
        $updates = Exely_Request::updates($data);
        $propertyId = self::_propertyId(Exely_Request::hotelId($data));
        $connection = $this->_activeConnection($propertyId, false);
        $parts = Exely_Ari::build($updates, new Exely_CurrencyResolver($this->_client, $propertyId));
        $warnings = array_merge($parts->warnings, self::_dropNotPermitted($parts, self::_scope($connection)));
        if (!$parts->isEmpty()) {
            $warnings = array_merge($warnings, $this->_sendAri($propertyId, $parts));
        }
        return array(null, $warnings);
    }

    protected function _sendAri($propertyId, Exely_AriParts $parts)
    {
        $warnings = array();
        foreach ($parts->chunks($this->_options['ariMaxItemsPerMessage']) as $chunk) {
            $uid = 'exely-' . $propertyId . '-' . str_replace('-', '', Exely_Uuid::v4());
            $message = array_merge(array(
                'message_uid' => $uid,
                // Exely sends updates one after another, so arrival order is the order of change.
                'revision' => $this->_nextRevision(),
                'property_id' => (int) $propertyId,
            ), $chunk);
            $status = $this->_client->postAri($message);
            if (!self::_isFinal($status)) {
                $status = $this->_waitForAri($uid, $status);
            }
            if (isset($status['status']) && $status['status'] === 'failed') {
                $errors = self::issuesFromErrors(isset($status['errors']) ? (array) $status['errors'] : array());
                if (!$errors) {
                    $errors = array(new Exely_Issue(Exely_Code::UPSTREAM_ERROR, 'Unit.Travel failed to apply the update'));
                }
                throw new Exely_Failure($errors, $warnings);
            }
            if (!self::_isFinal($status)) {
                // Answering "false" would make Exely resend and pile up duplicates while we are slow.
                $this->_log->warn("ARI message $uid not applied yet after waiting");
                $warnings[] = new Exely_Issue(
                    Exely_Code::STILL_PROCESSING, "Unit.Travel accepted update $uid but has not applied it yet"
                );
            }
        }
        return $warnings;
    }

    protected function _waitForAri($uid, $status)
    {
        $deadline = microtime(true) + (float) $this->_options['ariStatusWaitSeconds'];
        while (microtime(true) < $deadline) {
            usleep((int) ((float) $this->_options['ariStatusPollInterval'] * 1000000));
            $status = $this->_client->getAriStatus($uid);
            if (self::_isFinal($status)) {
                break;
            }
        }
        return $status;
    }

    /**
     * Strictly increasing millisecond timestamps for ARI `revision` (last-write-wins).
     */
    protected function _nextRevision()
    {
        $this->_lastRevision = max($this->_lastRevision + 1, (int) floor(microtime(true) * 1000));
        return $this->_lastRevision;
    }

    // get-bookings / confirm-bookings

    protected function _getBookings(array $data)
    {
        $hotelId = Exely_Request::hotelId($data);
        $this->_flushAcks();
        $today = $this->_today ? $this->_today : gmdate('Y-m-d');
        $days = (int) $this->_options['bookingRelevanceDays'];
        if ($hotelId !== null) {
            $propertyId = self::_propertyId($hotelId);
            if ($this->_options['pullReservations']) {
                $this->_pull($propertyId);
            }
            $rows = $this->_store->forProperty($propertyId, $today, $days);
        } else {
            if ($this->_options['pullReservations']) {
                $this->_pullAll();
            }
            $rows = $this->_store->pending($today, $days);
        }
        $bookings = array();
        foreach ($rows as $row) {
            $bookings[] = Exely_Bookings::toExely(Exely_Json::decode($row['payload']), $row['version']);
        }
        return array(array('bookings' => $bookings), array());
    }

    protected function _pullAll()
    {
        try {
            $connections = $this->_client->listConnections('active');
        } catch (Exely_UnitTravel_Exception $e) {
            $this->_log->warn("Could not list Unit.Travel connections, answering from stored bookings: $e");
            return;
        }
        foreach ($connections as $connection) {
            if (isset($connection['property_id'])) {
                $this->_pull((int) $connection['property_id']);
            }
        }
    }

    protected function _pull($propertyId)
    {
        try {
            $reservations = $this->_client->listReservations($propertyId);
        } catch (Exely_UnitTravel_Exception $e) {
            $this->_log->warn("Could not pull reservations of property $propertyId: $e");
            return;
        }
        foreach ($reservations as $reservation) {
            try {
                $this->_store->upsert(is_array($reservation) ? $reservation : array());
            } catch (Exely_InvalidReservationException $e) {
                $this->_log->err('Skipping malformed reservation: ' . $e->getMessage());
            }
        }
    }

    protected function _confirmBookings(array $data)
    {
        $warnings = array();
        foreach (Exely_Request::confirmations($data) as $item) {
            $outcome = $this->_store->confirm($item['number'], $item['echoToken'], $item['status'], $item['externalNumber']);
            if ($outcome === Exely_BookingStore::UNKNOWN) {
                $warnings[] = new Exely_Issue(Exely_Code::UNKNOWN_BOOKING, "Booking {$item['number']} is unknown to the channel");
            } elseif ($outcome === Exely_BookingStore::REJECTED) {
                $this->_log->err(
                    "Exely did not create booking {$item['number']} (no externalNumber): the property may be overbooked"
                );
            }
        }
        $this->_flushAcks();
        return array(null, $warnings);
    }

    /**
     * Acknowledge to Unit.Travel what Exely confirmed; failures are retried on the next call.
     */
    protected function _flushAcks()
    {
        foreach ($this->_store->pendingAcks() as $row) {
            $details = null;
            if ($this->_options['ackWithDetails']) {
                $details = array(
                    'external_reservation_code' => $row['exely_number'],
                    'status' => $row['exely_status'],
                    'result' => $row['rejected_by_exely'] ? 'rejected' : 'accepted',
                );
            }
            try {
                $this->_client->acknowledge($row['reservation_id'], $details);
            } catch (Exely_UnitTravel_Exception $e) {
                $status = $e->getStatus();
                if ($status === 0 || $status === 429 || $status >= 500) {
                    $this->_log->warn("Acknowledging reservations paused, will retry: $e");
                    return;
                }
                // Retrying a rejected acknowledge would fail the same way forever.
                $this->_log->err("Unit.Travel rejected acknowledging {$row['reservation_id']}: $e");
            }
            $this->_store->markAcked($row['reservation_id'], $row['version']);
        }
    }

    // Error mapping

    /**
     * @return Exely_Issue[]
     */
    public static function issuesFromUnitTravel(Exely_UnitTravel_Exception $e)
    {
        $status = $e->getStatus();
        if ($status === 409) {
            return array(new Exely_Issue(Exely_Code::HOTEL_NOT_CONNECTED, 'Unit.Travel: ' . $e->getMessage()));
        }
        if ($status === 404) {
            return array(new Exely_Issue(Exely_Code::HOTEL_NOT_FOUND, 'Unit.Travel: ' . $e->getMessage()));
        }
        if ($status === 429) {
            $wait = $e->getRetryAfter() ? ', retry in ' . $e->getRetryAfter() . 's' : '';
            return array(new Exely_Issue(Exely_Code::RATE_LIMITED, 'Unit.Travel rate limit exceeded' . $wait));
        }
        if ($status === 400 || $status === 422) {
            return self::issuesFromErrors(array(array(
                'code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'details' => $e->getDetails(),
            )));
        }
        if ($status === 401 || $status === 403) {
            return array(new Exely_Issue(Exely_Code::UPSTREAM_ERROR, 'The adapter is not authorised in Unit.Travel'));
        }
        return array(new Exely_Issue(Exely_Code::UPSTREAM_ERROR, 'Unit.Travel is unavailable: ' . $e->getMessage()));
    }

    /**
     * Map Unit.Travel validation errors onto Exely's error codes.
     *
     * @return Exely_Issue[]
     */
    public static function issuesFromErrors(array $errors)
    {
        $issues = array();
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $code = isset($error['code']) ? (string) $error['code'] : '';
            $message = !empty($error['message']) ? (string) $error['message'] : ($code !== '' ? $code : 'error');
            $details = isset($error['details']) && is_array($error['details']) ? $error['details'] : array();
            if (!$details) {
                $issues[] = new Exely_Issue(self::_classify($code), 'Unit.Travel: ' . $message);
            }
            foreach ($details as $detail) {
                $path = isset($detail['path']) ? (string) $detail['path'] : '';
                $reason = !empty($detail['reason']) ? (string) $detail['reason'] : $message;
                $issues[] = new Exely_Issue(
                    self::_classify("$path $code"), 'Unit.Travel: ' . ($path !== '' ? "$path: $reason" : $reason)
                );
            }
        }
        return $issues;
    }

    protected static function _classify($text)
    {
        $text = strtolower($text);
        if (strpos($text, 'connection') !== false) {
            return Exely_Code::HOTEL_NOT_CONNECTED;
        }
        if (strpos($text, 'currency') !== false) {
            return Exely_Code::INVALID_CURRENCY;
        }
        if (strpos($text, 'occupanc') !== false || strpos($text, 'pricing_model') !== false) {
            return Exely_Code::INVALID_ACCOMMODATION;
        }
        if (strpos($text, 'rate_plan') !== false) {
            return Exely_Code::INVALID_RATE_PLAN;
        }
        if (strpos($text, 'room') !== false) {
            return Exely_Code::INVALID_ROOM_TYPE;
        }
        return Exely_Code::INVALID_REQUEST;
    }

    // Helpers

    protected static function _dropNotPermitted(Exely_AriParts $parts, array $scope)
    {
        $warnings = array();
        foreach (Exely_AriParts::$kinds as $kind) {
            if (isset($scope[$kind]) && $scope[$kind] === false && $parts->$kind) {
                $parts->$kind = array();
                $warnings[] = new Exely_Issue(
                    Exely_Code::NOT_PERMITTED, "$kind updates are not permitted for this connection in Unit.Travel; ignored"
                );
            }
        }
        return $warnings;
    }

    protected static function _propertyId($hotelId)
    {
        if ($hotelId === null) {
            throw Exely_Failure::of(Exely_Code::INVALID_REQUEST, 'hotelId is required');
        }
        if (!ctype_digit($hotelId) || (int) $hotelId <= 0) {
            throw Exely_Failure::of(Exely_Code::HOTEL_NOT_FOUND, "Hotel '$hotelId' does not exist in Unit.Travel");
        }
        return (int) $hotelId;
    }

    protected static function _pickConnection(array $connections)
    {
        $picked = null;
        foreach ($connections as $connection) {
            if ($picked === null || $picked['status'] !== 'active') {
                $picked = $connection;
            }
        }
        return $picked;
    }

    protected static function _scope($connection)
    {
        return isset($connection['scope']) && is_array($connection['scope']) ? $connection['scope'] : array();
    }

    protected static function _isFinal($status)
    {
        return is_array($status) && isset($status['status']) && in_array($status['status'], self::$_finalAriStatuses, true);
    }

    protected static function _notActiveMessage($propertyId, $connection)
    {
        $status = is_array($connection) && isset($connection['status']) ? $connection['status'] : null;
        if ($status === null) {
            return "Property $propertyId is not connected to Exely in Unit.Travel";
        }
        if ($status === 'pending') {
            return "Connection of property $propertyId awaits the property's confirmation in Unit.Travel Extranet";
        }
        if ($status === 'rejected') {
            return "The property rejected the connection of property $propertyId in Unit.Travel Extranet";
        }
        return "Connection of property $propertyId is $status in Unit.Travel";
    }
}
