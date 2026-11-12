<?php
/**
 * Unit.Travel reservations and their delivery to Bakuun.
 *
 * States: pending (a version waits to be sent), delivered, rejected (Bakuun refused it; final
 * for that version) and retry (Bakuun or the network failed; tried again later). A new
 * version of a reservation starts again at pending. Card data is stripped before storing.
 * Updates are conditional on the version that was read, so concurrent writers are safe
 * without row locks.
 */
class Bakuun_Store_Bookings
{
    const TABLE = 'bakuun_bookings';

    const PENDING = 'pending';
    const DELIVERED = 'delivered';
    const REJECTED = 'rejected';
    const RETRY = 'retry';

    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;

    public function __construct(Zend_Db_Adapter_Abstract $db)
    {
        $this->_db = $db;
    }

    /**
     * Store a reservation.
     *
     * @return bool true when it is new or a newer version of a stored one
     * @throws UnitTravel_InvalidReservationException
     */
    public function upsert(array $reservation)
    {
        $reservation = UnitTravel_Reservation::stripCard($reservation);
        UnitTravel_Reservation::assertValid($reservation);
        $id = (string) $reservation['reservation_id'];
        $version = UnitTravel_Reservation::version($reservation);
        $modifiedAt = UnitTravel_Reservation::utcDateTime(UnitTravel_Reservation::field(
            $reservation, 'modified_at', UnitTravel_Reservation::field($reservation, 'created_at', null)
        ));
        $values = array(
            'property_id' => (int) $reservation['property_id'],
            'status' => (string) $reservation['status'],
            'version' => $version,
            'modified_at' => $modifiedAt,
            'checkout' => UnitTravel_Reservation::date($reservation['checkout'])->format('Y-m-d'),
            'payload' => UnitTravel_Json::encode($reservation),
            'received_at' => gmdate('Y-m-d H:i:s'),
            'state' => self::PENDING,
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
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
            if ($this->_update($id, $row['version'], $values)) {
                return true;
            }
        }
        throw new RuntimeException("Could not store reservation $id: it keeps changing concurrently");
    }

    /**
     * @return array|null
     */
    public function find($reservationId)
    {
        $row = $this->_db->fetchRow($this->_db->select()->from(self::TABLE)->where('reservation_id = ?', (string) $reservationId));
        return $row ? $row : null;
    }

    /**
     * @param string[] $bakuunResIds Bakuun reservation ids of the rooms (kept when empty)
     */
    public function markDelivered($reservationId, $version, $status, array $bakuunResIds)
    {
        $values = array(
            'state' => self::DELIVERED, 'delivered_version' => $version, 'delivered_status' => $status,
            'last_error' => null, 'next_attempt_at' => null,
        );
        if ($bakuunResIds) {
            $values['bakuun_res_ids'] = UnitTravel_Json::encode(array_values($bakuunResIds));
        }
        return $this->_update($reservationId, $version, $values);
    }

    public function markRejected($reservationId, $version, $error)
    {
        return $this->_update($reservationId, $version, array(
            'state' => self::REJECTED, 'last_error' => (string) $error, 'next_attempt_at' => null,
        ));
    }

    public function markRetry($reservationId, $version, $error, $attempts, $nextAttemptAt)
    {
        return $this->_update($reservationId, $version, array(
            'state' => self::RETRY, 'last_error' => (string) $error, 'attempts' => (int) $attempts, 'next_attempt_at' => $nextAttemptAt,
        ));
    }

    /**
     * Reservations whose last delivery failed for a transient reason and are due again.
     *
     * @return array[]
     */
    public function dueForRetry($now)
    {
        return $this->_db->fetchAll($this->_db->select()->from(self::TABLE)
            ->where('state = ?', self::RETRY)
            ->where('next_attempt_at <= ?', $now)
            ->order('next_attempt_at'));
    }

    /**
     * Reservations Bakuun has answered for (delivered or rejected) but Unit.Travel has not been told.
     *
     * @return array[]
     */
    public function pendingAcks()
    {
        return $this->_db->fetchAll($this->_db->select()->from(self::TABLE)
            ->where('state IN (?)', array(self::DELIVERED, self::REJECTED))
            ->where('acked_version IS NULL OR acked_version <> version'));
    }

    public function markAcked($reservationId, $version)
    {
        $this->_update($reservationId, $version, array('acked_version' => $version));
    }

    /**
     * @return string[] Bakuun reservation ids of a stored row
     */
    public static function bakuunResIds(array $row)
    {
        $ids = empty($row['bakuun_res_ids']) ? null : UnitTravel_Json::decode($row['bakuun_res_ids']);
        return is_array($ids) ? $ids : array();
    }

    protected function _update($reservationId, $version, array $values)
    {
        return $this->_db->update(self::TABLE, $values, array(
            'reservation_id = ?' => (string) $reservationId, 'version = ?' => $version,
        )) > 0;
    }
}
