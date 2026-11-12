<?php
/**
 * Bakuun properties linked to Unit.Travel properties, with their onboarding status.
 */
class Bakuun_Store_Properties
{
    const TABLE = 'bakuun_properties';

    const PENDING = 'pending';
    const ACTIVE = 'active';
    const SUSPENDED = 'suspended';
    const DISCONNECTED = 'disconnected';

    /** Linked by us from Bakuun's GetPropertyInfo; Bakuun's codes are mapped in bakuun_mappings. */
    const SOURCE_BAKUUN = 'bakuun';
    /** Bakuun maps our own codes on its side (getRoom / getRoomRatePlan). */
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
    public function find($bakuunHotelId)
    {
        return $this->_one($this->_db->select()->from(self::TABLE)->where('bakuun_hotel_id = ?', (string) $bakuunHotelId));
    }

    /**
     * @return array|null
     */
    public function findByUtProperty($utPropertyId)
    {
        return $this->_one($this->_db->select()->from(self::TABLE)->where('ut_property_id = ?', (int) $utPropertyId));
    }

    /**
     * @return array[]
     */
    public function all()
    {
        return $this->_db->fetchAll($this->_db->select()->from(self::TABLE)->order('bakuun_hotel_id'));
    }

    /**
     * Insert or update a link; only the given fields change on update.
     */
    public function save($bakuunHotelId, array $fields)
    {
        $now = gmdate('Y-m-d H:i:s');
        if (isset($fields['static_data']) && is_array($fields['static_data'])) {
            $fields['static_data'] = UnitTravel_Json::encode($fields['static_data']);
        }
        $fields['updated_at'] = $now;
        if ($this->find($bakuunHotelId) === null) {
            $this->_db->insert(self::TABLE, array_merge(array(
                'bakuun_hotel_id' => (string) $bakuunHotelId,
                'status' => self::PENDING,
                'source' => self::SOURCE_BAKUUN,
                'created_at' => $now,
            ), $fields));
            return;
        }
        $this->_db->update(self::TABLE, $fields, array('bakuun_hotel_id = ?' => (string) $bakuunHotelId));
    }

    /**
     * @return array Bakuun's propertyData saved at linking, or an empty array
     */
    public static function staticData(array $property)
    {
        $data = empty($property['static_data']) ? null : UnitTravel_Json::decode($property['static_data']);
        return is_array($data) ? $data : array();
    }

    protected function _one($select)
    {
        $row = $this->_db->fetchRow($select);
        return $row ? $row : null;
    }
}
