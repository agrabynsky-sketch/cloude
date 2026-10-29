<?php
/**
 * Reservations received from Unit.Travel and their delivery state in Exely.
 *
 * Exely expects a queue on the channel side: get-bookings without hotelId returns every
 * unconfirmed booking of every property, and get-bookings with a hotelId returns all
 * relevant bookings, confirmed ones included. Unit.Travel's /reservations works per
 * property and drops acknowledged reservations, so the adapter keeps its own copy.
 * Card data is stripped before a reservation is stored.
 *
 * Concurrent writers are handled optimistically: updates are conditional on the version
 * that was read, so no SELECT ... FOR UPDATE is needed (and SQLite works too).
 */
class Exely_BookingStore
{
    const TABLE = 'exely_bookings';

    const CONFIRMED = 'confirmed';
    /** Exely confirmed delivery without its own booking number: it did not create the booking. */
    const REJECTED = 'rejected';
    /** Exely confirmed an older version; the current one stays queued. */
    const STALE = 'stale';
    const UNKNOWN = 'unknown';

    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;

    public function __construct(Zend_Db_Adapter_Abstract $db)
    {
        $this->_db = $db;
    }

    /**
     * Store a reservation.
     *
     * @param  array $reservation as received from Unit.Travel
     * @return bool  true when it is new or a newer version of a stored one
     * @throws Exely_InvalidReservationException
     */
    public function upsert(array $reservation)
    {
        $reservation = Exely_Bookings::stripCard($reservation);
        Exely_Bookings::assertValid($reservation);
        $id = (string) $reservation['reservation_id'];
        $version = Exely_Bookings::version($reservation);
        $modifiedAt = Exely_Bookings::utcDateTime(
            isset($reservation['modified_at']) ? $reservation['modified_at']
                : (isset($reservation['created_at']) ? $reservation['created_at'] : null)
        );
        $values = array(
            'property_id' => (int) $reservation['property_id'],
            'status' => (string) $reservation['status'],
            'version' => $version,
            'modified_at' => $modifiedAt,
            'checkout' => Exely_Bookings::date($reservation['checkout'])->format('Y-m-d'),
            'payload' => Exely_Json::encode($reservation),
            'received_at' => self::_now(),
            'rejected_by_exely' => 0,
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $this->find($id);
            if ($row === null) {
                try {
                    $this->_db->insert(self::TABLE, array_merge(array('reservation_id' => $id), $values));
                    return true;
                } catch (Zend_Db_Exception $e) {
                    if ($this->find($id) === null) {
                        throw $e;
                    }
                    continue; // a concurrent delivery inserted it first
                }
            }
            if ($row['version'] === $version) {
                return false;
            }
            if ($modifiedAt !== null && $row['modified_at'] !== null && $modifiedAt < $row['modified_at']) {
                return false; // an older version delivered out of order
            }
            $where = array('reservation_id = ?' => $id, 'version = ?' => $row['version']);
            if ($this->_db->update(self::TABLE, $values, $where) > 0) {
                return true;
            }
        }
        throw new RuntimeException("Could not store reservation $id: it keeps changing concurrently");
    }

    /**
     * @return array|null the stored row
     */
    public function find($reservationId)
    {
        $select = $this->_db->select()->from(self::TABLE)->where('reservation_id = ?', (string) $reservationId);
        $row = $this->_db->fetchRow($select);
        return $row ? $row : null;
    }

    /**
     * Relevant bookings of all properties whose current version Exely has not confirmed.
     *
     * @return array[] rows
     */
    public function pending($today, $relevanceDays)
    {
        $select = $this->_db->select()
            ->from(self::TABLE)
            ->where('checkout >= ?', self::_cutoff($today, $relevanceDays))
            ->where('confirmed_version IS NULL OR confirmed_version <> version')
            ->order(array('received_at', 'reservation_id'));
        return $this->_db->fetchAll($select);
    }

    /**
     * Relevant bookings of one property, confirmed or not.
     *
     * @return array[] rows
     */
    public function forProperty($propertyId, $today, $relevanceDays)
    {
        $select = $this->_db->select()
            ->from(self::TABLE)
            ->where('property_id = ?', (int) $propertyId)
            ->where('checkout >= ?', self::_cutoff($today, $relevanceDays))
            ->order(array('received_at', 'reservation_id'));
        return $this->_db->fetchAll($select);
    }

    /**
     * Record Exely's confirm-bookings answer for one booking.
     *
     * @param  string      $reservationId
     * @param  string|null $echoToken    optional in Exely's confirmation; the status is compared then
     * @param  string|null $status
     * @param  string|null $exelyNumber  Exely's booking number; absent when Exely did not create it
     * @return string one of the outcome constants
     */
    public function confirm($reservationId, $echoToken, $status, $exelyNumber)
    {
        $row = $this->find($reservationId);
        if ($row === null) {
            return self::UNKNOWN;
        }
        $current = $echoToken !== null ? $echoToken === $row['version'] : $status === $row['status'];
        $changes = array();
        if ($exelyNumber !== null) {
            $changes['exely_number'] = $exelyNumber;
        }
        if ($current) {
            $changes['confirmed_version'] = $row['version'];
            $changes['exely_status'] = $status;
            $changes['confirmed_at'] = self::_now();
            $changes['rejected_by_exely'] = $exelyNumber === null ? 1 : 0;
        }
        if (!$changes) {
            return self::STALE;
        }
        $where = array('reservation_id = ?' => (string) $reservationId, 'version = ?' => $row['version']);
        $updated = $this->_db->update(self::TABLE, $changes, $where);
        if (!$current || $updated === 0) {
            return self::STALE; // a newer version arrived meanwhile and stays queued
        }
        return $exelyNumber === null ? self::REJECTED : self::CONFIRMED;
    }

    /**
     * Bookings Exely confirmed that are not yet acknowledged to Unit.Travel.
     *
     * @return array[] rows
     */
    public function pendingAcks()
    {
        $select = $this->_db->select()
            ->from(self::TABLE)
            ->where('confirmed_version = version')
            ->where('acked_version IS NULL OR acked_version <> version');
        return $this->_db->fetchAll($select);
    }

    public function markAcked($reservationId, $version)
    {
        $this->_db->update(self::TABLE, array('acked_version' => $version), array('reservation_id = ?' => $reservationId));
    }

    protected static function _cutoff($today, $relevanceDays)
    {
        $cutoff = new DateTime($today, new DateTimeZone('UTC'));
        $cutoff->modify('-' . (int) $relevanceDays . ' days');
        return $cutoff->format('Y-m-d');
    }

    protected static function _now()
    {
        return gmdate('Y-m-d H:i:s');
    }
}
