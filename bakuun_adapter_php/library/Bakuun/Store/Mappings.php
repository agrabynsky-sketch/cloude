<?php
/**
 * Bakuun room and rate plan codes of a property → Unit.Travel room_id / rate_plan_id.
 *
 * Rows come from onboarding (auto-mapping by name, or set by hand) or, when Bakuun maps
 * our own codes on its side, from the getRoom / getRoomRatePlan answers (source "partner",
 * where the code is the Unit.Travel id itself).
 */
class Bakuun_Store_Mappings
{
    const TABLE = 'bakuun_mappings';

    const ROOM = 'room';
    const RATE_PLAN = 'rate_plan';

    const SOURCE_AUTO = 'auto';
    const SOURCE_MANUAL = 'manual';
    const SOURCE_PARTNER = 'partner';

    /** @var Zend_Db_Adapter_Abstract */
    protected $_db;

    public function __construct(Zend_Db_Adapter_Abstract $db)
    {
        $this->_db = $db;
    }

    /**
     * @return array|null
     */
    public function find($bakuunHotelId, $entityType, $bakuunCode)
    {
        $row = $this->_db->fetchRow($this->_db->select()->from(self::TABLE)
            ->where('bakuun_hotel_id = ?', (string) $bakuunHotelId)
            ->where('entity_type = ?', $entityType)
            ->where('bakuun_code = ?', trim((string) $bakuunCode)));
        return $row ? $row : null;
    }

    /**
     * @return array[]
     */
    public function all($bakuunHotelId, $entityType = null)
    {
        $select = $this->_db->select()->from(self::TABLE)->where('bakuun_hotel_id = ?', (string) $bakuunHotelId)
            ->order(array('entity_type', 'bakuun_code'));
        if ($entityType !== null) {
            $select->where('entity_type = ?', $entityType);
        }
        return $this->_db->fetchAll($select);
    }

    /**
     * Insert or replace a mapping. An automatic suggestion never replaces a mapping set by hand.
     *
     * @return bool whether the row was written
     */
    public function save($bakuunHotelId, $entityType, $bakuunCode, $utId, $utRoomId, $name, $source)
    {
        $bakuunCode = trim((string) $bakuunCode);
        $existing = $this->find($bakuunHotelId, $entityType, $bakuunCode);
        if ($existing && $existing['source'] === self::SOURCE_MANUAL && $source === self::SOURCE_AUTO) {
            return false;
        }
        $values = array(
            'ut_id' => (int) $utId,
            'ut_room_id' => $utRoomId === null ? null : (int) $utRoomId,
            'name' => $name === null ? null : (string) $name,
            'source' => $source,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        if ($existing) {
            $this->_db->update(self::TABLE, $values, array(
                'bakuun_hotel_id = ?' => (string) $bakuunHotelId, 'entity_type = ?' => $entityType, 'bakuun_code = ?' => $bakuunCode,
            ));
        } else {
            $this->_db->insert(self::TABLE, array_merge(array(
                'bakuun_hotel_id' => (string) $bakuunHotelId, 'entity_type' => $entityType, 'bakuun_code' => $bakuunCode,
            ), $values));
        }
        return true;
    }

    public function delete($bakuunHotelId, $entityType, $bakuunCode)
    {
        return $this->_db->delete(self::TABLE, array(
            'bakuun_hotel_id = ?' => (string) $bakuunHotelId, 'entity_type = ?' => $entityType, 'bakuun_code = ?' => trim((string) $bakuunCode),
        ));
    }

    /**
     * The Bakuun code of a Unit.Travel id; a Bakuun-side code wins over our own echoed one.
     *
     * @return string|null
     */
    public function bakuunCode($bakuunHotelId, $entityType, $utId)
    {
        $rows = $this->_db->fetchAll($this->_db->select()->from(self::TABLE)
            ->where('bakuun_hotel_id = ?', (string) $bakuunHotelId)
            ->where('entity_type = ?', $entityType)
            ->where('ut_id = ?', (int) $utId)
            ->order('bakuun_code'));
        $partner = null;
        foreach ($rows as $row) {
            if ($row['source'] !== self::SOURCE_PARTNER) {
                return $row['bakuun_code'];
            }
            $partner = $row['bakuun_code'];
        }
        return $partner;
    }
}
