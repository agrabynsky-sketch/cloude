<?php
/**
 * A property's Unit.Travel rooms and rate plans, loaded on first use within one request.
 */
class Bakuun_Catalog
{
    protected $_client;
    protected $_propertyId;
    protected $_rooms;
    protected $_ratePlans;

    public function __construct(UnitTravel_Client $client, $propertyId)
    {
        $this->_client = $client;
        $this->_propertyId = (int) $propertyId;
    }

    public function propertyId()
    {
        return $this->_propertyId;
    }

    /**
     * @return array[] room id => Room
     */
    public function rooms()
    {
        if ($this->_rooms === null) {
            $this->_rooms = self::_byId($this->_client->getRooms($this->_propertyId));
        }
        return $this->_rooms;
    }

    /**
     * @return array[] rate plan id => RatePlan
     */
    public function ratePlans()
    {
        if ($this->_ratePlans === null) {
            $this->_ratePlans = self::_byId($this->_client->getRatePlans($this->_propertyId));
        }
        return $this->_ratePlans;
    }

    /**
     * @return array|null
     */
    public function room($roomId)
    {
        $rooms = $this->rooms();
        return isset($rooms[(int) $roomId]) ? $rooms[(int) $roomId] : null;
    }

    /**
     * @return array|null
     */
    public function ratePlan($ratePlanId)
    {
        $plans = $this->ratePlans();
        return isset($plans[(int) $ratePlanId]) ? $plans[(int) $ratePlanId] : null;
    }

    /**
     * @return array[] rate plan id => RatePlan
     */
    public function ratePlansOfRoom($roomId)
    {
        $plans = array();
        foreach ($this->ratePlans() as $id => $plan) {
            if (isset($plan['room_id']) && (int) $plan['room_id'] === (int) $roomId) {
                $plans[$id] = $plan;
            }
        }
        return $plans;
    }

    /**
     * The most adults a room can be priced for.
     */
    public static function maxAdults(array $room)
    {
        foreach (array('max_adults', 'max_occupancy', 'base_occupancy') as $field) {
            if (!empty($room[$field]) && (int) $room[$field] > 0) {
                return (int) $room[$field];
            }
        }
        return 1;
    }

    /**
     * The number of adults a room's base price is for.
     */
    public static function baseOccupancy(array $room)
    {
        return !empty($room['base_occupancy']) && (int) $room['base_occupancy'] > 0
            ? (int) $room['base_occupancy']
            : self::maxAdults($room);
    }

    public static function isPerGuest(array $room)
    {
        return isset($room['pricing_model']) && $room['pricing_model'] === 'per_guest';
    }

    protected static function _byId(array $items)
    {
        $byId = array();
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $byId[(int) $item['id']] = $item;
            }
        }
        return $byId;
    }
}
