<?php
/**
 * Delivers Unit.Travel reservations to Bakuun and acknowledges them back to Unit.Travel.
 *
 *   new        Pre-Booking (token, valid 2 minutes) + Acknowledgement with our reservation id
 *   modified   pushModifyBooking, or the new flow when the booking never reached Bakuun
 *   cancelled  pushCancelBooking, or nothing when the booking never reached Bakuun
 *
 * Bakuun refusing a booking is final for that version (logged: the property may be
 * overbooked); Bakuun or the network failing is retried with backoff. A retry first asks
 * Bakuun (Read Booking) whether an earlier attempt went through, so no booking is doubled.
 * Unit.Travel is acknowledged once Bakuun has answered, so in pull mode an undelivered
 * reservation keeps coming back with its card data.
 */
class Bakuun_BookingSync
{
    const DELIVERED = 'delivered';
    const REJECTED = 'rejected';
    const RETRY = 'retry';
    const UNCHANGED = 'unchanged';

    protected static $_defaults = array(
        'pullReservations' => true,
        // Send Bakuun's answer in the acknowledge body (needs Partner API support).
        'ackWithDetails' => false,
        'maxAttempts' => 20,
        'mapper' => array(),
    );

    protected $_options;
    protected $_bakuun;
    protected $_client;
    protected $_bookings;
    protected $_properties;
    protected $_mapper;
    protected $_log;

    public function __construct(
        array $options, Bakuun_Client $bakuun, UnitTravel_Client $client, Bakuun_Store_Bookings $bookings,
        Bakuun_Store_Properties $properties, Bakuun_Store_Mappings $mappings, Zend_Log $log = null
    ) {
        $this->_options = array_merge(self::$_defaults, $options);
        $this->_bakuun = $bakuun;
        $this->_client = $client;
        $this->_bookings = $bookings;
        $this->_properties = $properties;
        $this->_mapper = new Bakuun_BookingMapper($mappings, (array) $this->_options['mapper']);
        $this->_log = $log ? $log : new Zend_Log(new Zend_Log_Writer_Null());
    }

    /**
     * Store a reservation from Unit.Travel and deliver it to Bakuun.
     *
     * @param  array $reservation as Unit.Travel sent it, card data included
     * @return string DELIVERED, REJECTED, RETRY or UNCHANGED
     * @throws UnitTravel_InvalidReservationException
     */
    public function process(array $reservation)
    {
        $changed = $this->_bookings->upsert($reservation);
        $row = $this->_bookings->find($reservation['reservation_id']);
        if (!$changed && in_array($row['state'], array(Bakuun_Store_Bookings::DELIVERED, Bakuun_Store_Bookings::REJECTED), true)) {
            $this->flushAcks();
            return self::UNCHANGED;
        }
        if ($row['version'] !== UnitTravel_Reservation::version($reservation)) {
            return self::UNCHANGED; // an older version arrived after a newer one
        }
        return $this->_deliver($row, $reservation);
    }

    /**
     * Pull Unit.Travel reservations, retry due deliveries and acknowledge what Bakuun answered (cron).
     *
     * @return array counts per result
     */
    public function run()
    {
        $counts = array(self::DELIVERED => 0, self::REJECTED => 0, self::RETRY => 0, self::UNCHANGED => 0);
        $this->flushAcks();
        $seen = array();
        if ($this->_options['pullReservations']) {
            foreach ($this->_properties->all() as $property) {
                if ($property['status'] !== Bakuun_Store_Properties::ACTIVE) {
                    continue;
                }
                foreach ($this->_pull($property) as $reservation) {
                    try {
                        $counts[$this->process($reservation)]++;
                        $seen[(string) $reservation['reservation_id']] = true;
                    } catch (UnitTravel_InvalidReservationException $e) {
                        $this->_log->err('Skipping malformed reservation: ' . $e->getMessage());
                    }
                }
            }
        }
        foreach ($this->_bookings->dueForRetry(gmdate('Y-m-d H:i:s')) as $row) {
            if (isset($seen[$row['reservation_id']])) {
                continue;
            }
            // Without a fresh copy from Unit.Travel there is no card data any more.
            $counts[$this->_deliver($row, UnitTravel_Json::decode($row['payload']))]++;
        }
        $this->flushAcks();
        return $counts;
    }

    /**
     * Acknowledge to Unit.Travel what Bakuun answered for; failures are retried on the next call.
     */
    public function flushAcks()
    {
        foreach ($this->_bookings->pendingAcks() as $row) {
            $details = null;
            if ($this->_options['ackWithDetails']) {
                $details = array(
                    'external_reservation_code' => implode(',', Bakuun_Store_Bookings::bakuunResIds($row)),
                    'status' => $row['status'],
                    'result' => $row['state'] === Bakuun_Store_Bookings::DELIVERED ? 'accepted' : 'rejected',
                );
            }
            try {
                $this->_client->acknowledge($row['reservation_id'], $details);
            } catch (UnitTravel_Exception $e) {
                $status = $e->getStatus();
                if ($status === 0 || $status === 429 || $status >= 500) {
                    $this->_log->warn("Acknowledging reservations paused, will retry: $e");
                    return;
                }
                // Retrying a rejected acknowledge would fail the same way forever.
                $this->_log->err("Unit.Travel rejected acknowledging {$row['reservation_id']}: $e");
            }
            $this->_bookings->markAcked($row['reservation_id'], $row['version']);
        }
    }

    protected function _deliver(array $row, array $reservation)
    {
        $id = (string) $row['reservation_id'];
        $property = $this->_properties->findByUtProperty($row['property_id']);
        if ($property === null || in_array($property['status'], array(Bakuun_Store_Properties::SUSPENDED, Bakuun_Store_Properties::DISCONNECTED), true)) {
            return $this->_reject($row, 'property ' . $row['property_id'] . ' is not connected to Bakuun');
        }
        if ($property['status'] !== Bakuun_Store_Properties::ACTIVE) {
            return $this->_retry($row, 'the Bakuun mapping of property ' . $row['property_id'] . ' is not active yet');
        }
        $hotelCode = $property['bakuun_hotel_id'];
        $hotelName = (string) $property['hotel_name'];
        $status = $reservation['status'];
        $delivered = Bakuun_Store_Bookings::bakuunResIds($row) || $row['delivered_version'] !== null;
        $reachedBakuun = $delivered && $row['delivered_status'] !== 'cancelled';
        try {
            if (!$reachedBakuun && ($status !== 'new' || $row['attempts'] > 0)) {
                // An earlier attempt (maybe of an older version) may have gone through although
                // its answer never arrived: ask Bakuun before cancelling, changing or creating.
                $existing = $this->_bakuun->readBooking($id);
                $reachedBakuun = $existing !== null && strtolower((string) UnitTravel_Reservation::field($existing, 'statusBooking', '')) !== 'cancelled';
                if ($reachedBakuun && $status === 'new') {
                    return $this->_delivered($row, $status, empty($existing['ourBookingId']) ? array() : array((string) $existing['ourBookingId']));
                }
            }
            if ($status === 'cancelled') {
                $ids = $reachedBakuun ? $this->_cancel($hotelCode, $hotelName, $id) : array();
            } elseif ($reachedBakuun) {
                $ids = $this->_modify($reservation, $property);
            } else {
                $ids = $this->_create($reservation, $property);
            }
        } catch (Bakuun_Failure $e) {
            return $this->_reject($row, $e->getMessage());
        } catch (Bakuun_Exception $e) {
            return $e->isTransient() ? $this->_retry($row, (string) $e) : $this->_reject($row, (string) $e);
        }
        return $this->_delivered($row, $status, $ids);
    }

    protected function _delivered(array $row, $status, array $ids)
    {
        $this->_bookings->markDelivered($row['reservation_id'], $row['version'], $status, $ids);
        $this->_log->info("Reservation {$row['reservation_id']} ($status) delivered to Bakuun");
        $this->flushAcks();
        return self::DELIVERED;
    }

    protected function _create(array $reservation, array $property)
    {
        $hotelReservation = $this->_mapper->hotelReservation($reservation, $property, 'New');
        $token = $this->_bakuun->preBook($hotelReservation);
        try {
            return $this->_bakuun->confirmBooking(
                $property['bakuun_hotel_id'], (string) $property['hotel_name'], $token, $reservation['reservation_id']
            );
        } catch (Bakuun_Exception $e) {
            if ($e->getErrorCode() !== Bakuun_Code::RESERVATION_ID_EXISTS) {
                throw $e;
            }
            // Bakuun already has it: bring it up to date instead.
            return $reservation['status'] === 'new' ? array() : $this->_modify($reservation, $property);
        }
    }

    protected function _modify(array $reservation, array $property)
    {
        return $this->_bakuun->modifyBooking($this->_mapper->hotelReservation($reservation, $property, 'Modify'));
    }

    protected function _cancel($hotelCode, $hotelName, $id)
    {
        try {
            return $this->_bakuun->cancelBooking($hotelCode, $hotelName, $id);
        } catch (Bakuun_Exception $e) {
            if ($e->getErrorCode() === Bakuun_Code::RESERVATION_ALREADY_CANCELLED) {
                return array();
            }
            throw $e;
        }
    }

    protected function _reject(array $row, $error)
    {
        $this->_bookings->markRejected($row['reservation_id'], $row['version'], $error);
        $this->_log->err("Bakuun did not take reservation {$row['reservation_id']} ({$row['status']}): $error; the property may be overbooked");
        $this->flushAcks();
        return self::REJECTED;
    }

    protected function _retry(array $row, $error)
    {
        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= (int) $this->_options['maxAttempts']) {
            return $this->_reject($row, "gave up after $attempts attempts: $error");
        }
        $next = new DateTime('now', new DateTimeZone('UTC'));
        $next->modify('+' . min(pow(2, $attempts), 60) . ' minutes');
        $this->_bookings->markRetry($row['reservation_id'], $row['version'], $error, $attempts, $next->format('Y-m-d H:i:s'));
        $this->_log->warn("Delivering reservation {$row['reservation_id']} to Bakuun failed (attempt $attempts), will retry: $error");
        return self::RETRY;
    }

    protected function _pull(array $property)
    {
        try {
            return $this->_client->listReservations($property['ut_property_id']);
        } catch (UnitTravel_Exception $e) {
            $this->_log->warn("Could not pull reservations of property {$property['ut_property_id']}: $e");
            return array();
        }
    }
}
