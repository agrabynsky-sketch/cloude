<?php
/**
 * Bakuun's restrictions per date and level (room or rate plan), keyed by Unit.Travel ids.
 *
 * Bakuun keeps room-level and rate-plan-level restrictions apart, Unit.Travel only has the
 * rate plan level. Both layers are stored so the adapter can send their combination: a
 * room-level "open" must not reopen a rate plan that is closed on its own.
 */
class Bakuun_Store_Restrictions
{
    const TABLE = 'bakuun_restrictions';

    const ROOM = 'room';
    const RATE = 'rate';

    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;

    public function __construct(Zend_Db_Adapter_Abstract $db)
    {
        $this->_db = $db;
    }

    /**
     * Set (or, with null, clear) a value for every date of a range.
     *
     * @param string   $level ROOM or RATE
     * @param int      $utId  Unit.Travel room_id (ROOM) or rate_plan_id (RATE)
     * @param string   $field Unit.Travel restriction field
     * @param string   $from  Y-m-d
     * @param string   $to    Y-m-d, inclusive
     * @param int|null $value
     */
    public function set($propertyId, $level, $utId, $field, $from, $to, $value)
    {
        $this->_db->beginTransaction();
        try {
            $this->_db->delete(self::TABLE, array(
                'level = ?' => $level, 'ut_id = ?' => (int) $utId, 'field = ?' => $field,
                'stay_date >= ?' => $from, 'stay_date <= ?' => $to,
            ));
            if ($value !== null) {
                foreach (self::dates($from, $to) as $date) {
                    $this->_db->insert(self::TABLE, array(
                        'level' => $level, 'ut_id' => (int) $utId, 'field' => $field, 'stay_date' => $date,
                        'value' => (int) $value, 'property_id' => (int) $propertyId,
                    ));
                }
            }
            $this->_db->commit();
        } catch (Exception $e) {
            $this->_db->rollBack();
            throw $e;
        }
    }

    /**
     * @return array date (Y-m-d) => value, for the dates that have one
     */
    public function values($level, $utId, $field, $from, $to)
    {
        $rows = $this->_db->fetchAll($this->_db->select()->from(self::TABLE, array('stay_date', 'value'))
            ->where('level = ?', $level)
            ->where('ut_id = ?', (int) $utId)
            ->where('field = ?', $field)
            ->where('stay_date >= ?', $from)
            ->where('stay_date <= ?', $to));
        $values = array();
        foreach ($rows as $row) {
            $values[substr($row['stay_date'], 0, 10)] = (int) $row['value'];
        }
        return $values;
    }

    /**
     * @return string[] every date of an inclusive Y-m-d range
     */
    public static function dates($from, $to)
    {
        $dates = array();
        $day = new DateTime($from, new DateTimeZone('UTC'));
        $last = new DateTime($to, new DateTimeZone('UTC'));
        while ($day <= $last) {
            $dates[] = $day->format('Y-m-d');
            $day->modify('+1 day');
        }
        return $dates;
    }
}
