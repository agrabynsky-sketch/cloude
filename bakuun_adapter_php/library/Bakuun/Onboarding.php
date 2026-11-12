<?php
/**
 * Onboarding a Bakuun property: link it to a Unit.Travel property, map its rooms and rate
 * plans, then activate (mappings to Unit.Travel's /mappings, status "Active" to Bakuun).
 *
 *   link       Bakuun GetPropertyInfo + Unit.Travel POST /connections (the hotel confirms in Extranet)
 *   autoMap    suggest mappings by name / code / meal + refundability
 *   map/unmap  set or remove one mapping by hand
 *   activate   PUT /properties/{id}/mappings, pushPropertyInfo mappingStatus "Active"
 *   suspend, disconnect
 */
class Bakuun_Onboarding
{
    protected static $_meals = array(
        'RO' => 'RO', '0' => 'RO', 'BB' => 'BB', '1' => 'BB', 'HB' => 'HB', '2' => 'HB', 'FB' => 'FB', '3' => 'FB', 'AI' => 'AI',
    );

    protected $_bakuun;
    protected $_client;
    protected $_properties;
    protected $_mappings;

    public function __construct(
        Bakuun_Client $bakuun, UnitTravel_Client $client, Bakuun_Store_Properties $properties, Bakuun_Store_Mappings $mappings
    ) {
        $this->_bakuun = $bakuun;
        $this->_client = $client;
        $this->_properties = $properties;
        $this->_mappings = $mappings;
    }

    /**
     * Bakuun's listing of the partner account next to the local links.
     *
     * @return array[] bakuun_hotel_id, name, bakuun_status, ut_property_id, status
     */
    public function listing()
    {
        $rows = array();
        foreach ($this->_bakuun->getListing() as $item) {
            $id = isset($item['propertyID']) ? (string) $item['propertyID'] : '';
            $link = $this->_properties->find($id);
            $rows[$id] = array(
                'bakuun_hotel_id' => $id,
                'name' => isset($item['propertyName']) ? $item['propertyName'] : '',
                'bakuun_status' => isset($item['mappingStatus']) ? $item['mappingStatus'] : '',
                'ut_property_id' => $link ? $link['ut_property_id'] : null,
                'status' => $link ? $link['status'] : null,
            );
        }
        foreach ($this->_properties->all() as $link) {
            if (!isset($rows[$link['bakuun_hotel_id']])) {
                $rows[$link['bakuun_hotel_id']] = array(
                    'bakuun_hotel_id' => $link['bakuun_hotel_id'], 'name' => $link['hotel_name'], 'bakuun_status' => null,
                    'ut_property_id' => $link['ut_property_id'], 'status' => $link['status'],
                );
            }
        }
        return array_values($rows);
    }

    /**
     * Link a Bakuun property to a Unit.Travel property and request the Unit.Travel connection.
     *
     * @return array the property row
     */
    public function link($bakuunHotelId, $utPropertyId)
    {
        $bakuunHotelId = trim((string) $bakuunHotelId);
        $other = $this->_properties->findByUtProperty($utPropertyId);
        if ($other !== null && $other['bakuun_hotel_id'] !== $bakuunHotelId) {
            throw new InvalidArgumentException("Unit.Travel property $utPropertyId is already linked to Bakuun hotel {$other['bakuun_hotel_id']}");
        }
        $data = $this->_bakuun->getPropertyInfo($bakuunHotelId);
        $connection = $this->_client->createConnection($utPropertyId, 'Bakuun ' . $bakuunHotelId, $bakuunHotelId);
        $this->_properties->save($bakuunHotelId, array(
            'ut_property_id' => (int) $utPropertyId,
            'ut_connection_id' => isset($connection['connection_id']) ? $connection['connection_id'] : null,
            'ut_connection_status' => isset($connection['status']) ? $connection['status'] : null,
            'source' => Bakuun_Store_Properties::SOURCE_BAKUUN,
            'hotel_name' => isset($data['propertyName']) ? $data['propertyName'] : null,
            'static_data' => $data,
        ));
        return $this->_properties->find($bakuunHotelId);
    }

    /**
     * Re-read the Unit.Travel connection status (the hotel may have confirmed it since).
     *
     * @return array the property row
     */
    public function refresh($bakuunHotelId)
    {
        $property = $this->_property($bakuunHotelId);
        $connection = null;
        foreach ($this->_client->listConnections(null, $property['ut_property_id']) as $candidate) {
            if ($connection === null || $candidate['status'] === 'active') {
                $connection = $candidate;
            }
        }
        $this->_properties->save($bakuunHotelId, array(
            'ut_connection_id' => $connection && isset($connection['connection_id']) ? $connection['connection_id'] : null,
            'ut_connection_status' => $connection ? $connection['status'] : null,
        ));
        return $this->_properties->find($bakuunHotelId);
    }

    /**
     * Suggest mappings for the Bakuun rooms and rate plans that have none yet.
     *
     * A room matches a Unit.Travel room with the same name or code. A rate plan matches,
     * within the matched room, by name or code, else by meal plan and refundability, else
     * when the room has exactly one rate plan on both sides. Only unique matches count.
     *
     * @param  bool $save store the suggestions (as "auto"; mappings set by hand are kept)
     * @return array[] type, bakuun_code, bakuun_name, ut_id, ut_name, result (mapped, suggested, kept, unmatched)
     */
    public function autoMap($bakuunHotelId, $save)
    {
        $property = $this->_activeConnection($bakuunHotelId);
        $catalog = new Bakuun_Catalog($this->_client, $property['ut_property_id']);
        $report = array();
        foreach (self::_roomTypes($property) as $roomType) {
            $code = isset($roomType['roomTypeId']) ? (string) $roomType['roomTypeId'] : '';
            $name = isset($roomType['roomName']) ? (string) $roomType['roomName'] : '';
            $existing = $this->_mappings->find($bakuunHotelId, Bakuun_Store_Mappings::ROOM, $code);
            $room = $existing ? $catalog->room($existing['ut_id']) : self::_unique($catalog->rooms(), $name, $code);
            $report[] = $this->_suggest($bakuunHotelId, Bakuun_Store_Mappings::ROOM, $code, $name, $room, null, $existing, $save);
            $rateTypes = isset($roomType['rateTypes']) && is_array($roomType['rateTypes']) ? $roomType['rateTypes'] : array();
            $candidates = $room ? $catalog->ratePlansOfRoom($room['id']) : array();
            foreach ($rateTypes as $rateType) {
                $rateCode = isset($rateType['ratePlanId']) ? (string) $rateType['ratePlanId'] : '';
                $rateName = isset($rateType['ratePlanName']) ? (string) $rateType['ratePlanName'] : '';
                $existingRate = $this->_mappings->find($bakuunHotelId, Bakuun_Store_Mappings::RATE_PLAN, $rateCode);
                $plan = $existingRate ? $catalog->ratePlan($existingRate['ut_id']) : self::_matchRatePlan($candidates, $rateType, count($rateTypes));
                $report[] = $this->_suggest(
                    $bakuunHotelId, Bakuun_Store_Mappings::RATE_PLAN, $rateCode, $rateName, $plan, $room ? $room['id'] : null, $existingRate, $save
                );
            }
        }
        return $report;
    }

    /**
     * Map one Bakuun room or rate plan code by hand.
     *
     * @param string $type Bakuun_Store_Mappings::ROOM or RATE_PLAN
     */
    public function map($bakuunHotelId, $type, $bakuunCode, $utId)
    {
        $property = $this->_activeConnection($bakuunHotelId);
        $catalog = new Bakuun_Catalog($this->_client, $property['ut_property_id']);
        if ($type === Bakuun_Store_Mappings::ROOM) {
            $room = $catalog->room($utId);
            if ($room === null) {
                throw new InvalidArgumentException("Unit.Travel room $utId does not exist in property {$property['ut_property_id']}");
            }
            $this->_mappings->save($bakuunHotelId, $type, $bakuunCode, $utId, null, self::_bakuunName($property, $type, $bakuunCode), Bakuun_Store_Mappings::SOURCE_MANUAL);
            return;
        }
        if ($type !== Bakuun_Store_Mappings::RATE_PLAN) {
            throw new InvalidArgumentException("Unknown mapping type '$type' (room or rate_plan)");
        }
        $plan = $catalog->ratePlan($utId);
        if ($plan === null) {
            throw new InvalidArgumentException("Unit.Travel rate plan $utId does not exist in property {$property['ut_property_id']}");
        }
        $bakuunRoom = self::_bakuunRoomOfRate($property, $bakuunCode);
        if ($bakuunRoom !== null) {
            $roomMapping = $this->_mappings->find($bakuunHotelId, Bakuun_Store_Mappings::ROOM, $bakuunRoom);
            if ($roomMapping !== null && (int) $roomMapping['ut_id'] !== (int) $plan['room_id']) {
                throw new InvalidArgumentException(
                    "Unit.Travel rate plan $utId belongs to room {$plan['room_id']}, but Bakuun room $bakuunRoom is mapped to room {$roomMapping['ut_id']}"
                );
            }
        }
        $this->_mappings->save(
            $bakuunHotelId, $type, $bakuunCode, $utId, $plan['room_id'], self::_bakuunName($property, $type, $bakuunCode),
            Bakuun_Store_Mappings::SOURCE_MANUAL
        );
    }

    public function unmap($bakuunHotelId, $type, $bakuunCode)
    {
        $this->_property($bakuunHotelId);
        return $this->_mappings->delete($bakuunHotelId, $type, $bakuunCode) > 0;
    }

    /**
     * Bakuun rooms and rate plans that are not mapped yet.
     *
     * @return array[] type, bakuun_code, name
     */
    public function unmapped($bakuunHotelId)
    {
        $property = $this->_property($bakuunHotelId);
        $missing = array();
        foreach (self::_roomTypes($property) as $roomType) {
            $code = isset($roomType['roomTypeId']) ? (string) $roomType['roomTypeId'] : '';
            if (!$this->_mappings->find($bakuunHotelId, Bakuun_Store_Mappings::ROOM, $code)) {
                $missing[] = array('type' => Bakuun_Store_Mappings::ROOM, 'bakuun_code' => $code, 'name' => isset($roomType['roomName']) ? $roomType['roomName'] : '');
            }
            foreach (isset($roomType['rateTypes']) && is_array($roomType['rateTypes']) ? $roomType['rateTypes'] : array() as $rateType) {
                $rateCode = isset($rateType['ratePlanId']) ? (string) $rateType['ratePlanId'] : '';
                if (!$this->_mappings->find($bakuunHotelId, Bakuun_Store_Mappings::RATE_PLAN, $rateCode)) {
                    $missing[] = array('type' => Bakuun_Store_Mappings::RATE_PLAN, 'bakuun_code' => $rateCode, 'name' => isset($rateType['ratePlanName']) ? $rateType['ratePlanName'] : '');
                }
            }
        }
        return $missing;
    }

    /**
     * Start ARI and bookings for a property.
     *
     * @param bool $allowPartial activate although some Bakuun rooms or rate plans are not mapped
     * @param bool $notifyBakuun send pushPropertyInfo with mappingStatus "Active"
     * @return array the property row
     */
    public function activate($bakuunHotelId, $allowPartial = false, $notifyBakuun = true)
    {
        $property = $this->_activeConnection($bakuunHotelId);
        $missing = $this->unmapped($bakuunHotelId);
        if ($missing && !$allowPartial) {
            $codes = array();
            foreach ($missing as $item) {
                $codes[] = $item['type'] . ' ' . $item['bakuun_code'];
            }
            throw new InvalidArgumentException('Not mapped yet: ' . implode(', ', $codes));
        }
        $rows = $this->_mappings->all($bakuunHotelId);
        $mappings = array();
        foreach ($rows as $row) {
            if ($row['source'] !== Bakuun_Store_Mappings::SOURCE_PARTNER) {
                $mappings[] = array('entity_type' => $row['entity_type'], 'external_code' => $row['bakuun_code'], 'internal_id' => (int) $row['ut_id']);
            }
        }
        if (!$rows) {
            throw new InvalidArgumentException("Hotel $bakuunHotelId has no mappings");
        }
        if ($mappings) {
            // Reservations then come back from Unit.Travel with Bakuun's codes in room_code / rate_plan_code.
            $this->_client->putMappings($property['ut_property_id'], $mappings);
        }
        if ($notifyBakuun) {
            $this->_bakuun->pushProperty($bakuunHotelId, 'Active', $this->_propertyData($property, $rows));
        }
        $this->_properties->save($bakuunHotelId, array('status' => Bakuun_Store_Properties::ACTIVE));
        return $this->_properties->find($bakuunHotelId);
    }

    /**
     * Pause ARI and bookings; Bakuun is told the mapping is "Suspended".
     */
    public function suspend($bakuunHotelId, $notifyBakuun = true)
    {
        return $this->_stop($bakuunHotelId, Bakuun_Store_Properties::SUSPENDED, 'Suspended', $notifyBakuun, false);
    }

    /**
     * End the connection; Bakuun is told "Disconnected", and optionally Unit.Travel too.
     */
    public function disconnect($bakuunHotelId, $notifyBakuun = true, $disconnectUnitTravel = false)
    {
        return $this->_stop($bakuunHotelId, Bakuun_Store_Properties::DISCONNECTED, 'Disconnected', $notifyBakuun, $disconnectUnitTravel);
    }

    protected function _stop($bakuunHotelId, $status, $bakuunStatus, $notifyBakuun, $disconnectUnitTravel)
    {
        $property = $this->_property($bakuunHotelId);
        if ($notifyBakuun) {
            $this->_bakuun->pushProperty($bakuunHotelId, $bakuunStatus, $this->_propertyData($property, $this->_mappings->all($bakuunHotelId)));
        }
        if ($disconnectUnitTravel && !empty($property['ut_connection_id'])) {
            $this->_client->deleteConnection($property['ut_connection_id']);
        }
        $this->_properties->save($bakuunHotelId, array('status' => $status));
        return $this->_properties->find($bakuunHotelId);
    }

    /**
     * pushPropertyInfo propertyData: Bakuun's static data with the mapped rooms and rate plans.
     */
    protected function _propertyData(array $property, array $rows)
    {
        $data = Bakuun_Store_Properties::staticData($property);
        $mapped = array();
        foreach ($rows as $row) {
            $mapped[$row['entity_type']][$row['bakuun_code']] = $row;
        }
        $roomTypes = array();
        foreach (self::_roomTypes($property) as $roomType) {
            $code = isset($roomType['roomTypeId']) ? (string) $roomType['roomTypeId'] : '';
            if (!isset($mapped[Bakuun_Store_Mappings::ROOM][$code])) {
                continue;
            }
            $rateTypes = array();
            foreach (isset($roomType['rateTypes']) && is_array($roomType['rateTypes']) ? $roomType['rateTypes'] : array() as $rateType) {
                $rateCode = isset($rateType['ratePlanId']) ? (string) $rateType['ratePlanId'] : '';
                if (!isset($mapped[Bakuun_Store_Mappings::RATE_PLAN][$rateCode])) {
                    continue;
                }
                $rateTypes[] = array(
                    'ratePlanId' => $rateCode,
                    'ratePlanName' => self::_first($rateType, 'ratePlanName', $mapped[Bakuun_Store_Mappings::RATE_PLAN][$rateCode]['name']),
                    'cancelType' => self::_first($rateType, 'cancelType', ''),
                    'authPerson' => self::_first($rateType, 'authPerson', ''),
                    'defaultMeal' => self::_first($rateType, 'defaultMeal', ''),
                    'rateCategory' => self::_first($rateType, 'rateCategory', ''),
                    'restrictedCountry' => self::_first($rateType, 'restrictedCountry', ''),
                );
            }
            $roomTypes[] = array(
                'roomTypeId' => $code,
                'roomName' => self::_first($roomType, 'roomName', $mapped[Bakuun_Store_Mappings::ROOM][$code]['name']),
                'type' => self::_first($roomType, 'type', 'DailyRoom'),
                'rateTypes' => $rateTypes,
            );
        }
        $propertyData = array();
        $fields = array('propertyName', 'country', 'region', 'city', 'area', 'zipCode', 'address', 'latitude', 'longitude',
            'prefixPhone', 'phoneNum', 'currency');
        foreach ($fields as $field) {
            $propertyData[$field] = self::_first($data, $field, $field === 'propertyName' ? (string) $property['hotel_name'] : '');
        }
        $propertyData['roomTypes'] = $roomTypes;
        return $propertyData;
    }

    protected function _suggest($hotelId, $type, $code, $name, $match, $utRoomId, $existing, $save)
    {
        $result = array(
            'type' => $type, 'bakuun_code' => $code, 'bakuun_name' => $name,
            'ut_id' => $match ? (int) $match['id'] : null, 'ut_name' => $match ? self::_firstOf($match, array('name', 'code')) : null,
        );
        if ($existing) {
            $result['result'] = 'kept';
        } elseif (!$match) {
            $result['result'] = 'unmatched';
        } else {
            $result['result'] = $save ? 'mapped' : 'suggested';
            if ($save) {
                $this->_mappings->save($hotelId, $type, $code, $match['id'], $utRoomId, $name, Bakuun_Store_Mappings::SOURCE_AUTO);
            }
        }
        return $result;
    }

    protected static function _unique(array $items, $name, $code)
    {
        $found = array();
        $wanted = self::_normalize($name);
        foreach ($items as $item) {
            if (($wanted !== '' && self::_normalize(self::_firstOf($item, array('name'))) === $wanted)
                || ($code !== '' && isset($item['code']) && strcasecmp(trim($item['code']), $code) === 0)
            ) {
                $found[] = $item;
            }
        }
        return count($found) === 1 ? $found[0] : null;
    }

    protected static function _matchRatePlan(array $candidates, array $rateType, $bakuunRateCount)
    {
        $name = isset($rateType['ratePlanName']) ? (string) $rateType['ratePlanName'] : '';
        $code = isset($rateType['ratePlanId']) ? (string) $rateType['ratePlanId'] : '';
        $match = self::_unique($candidates, $name, $code);
        if ($match) {
            return $match;
        }
        $meal = isset($rateType['defaultMeal']) ? strtoupper(trim((string) $rateType['defaultMeal'])) : '';
        $meal = isset(self::$_meals[$meal]) ? self::$_meals[$meal] : null;
        // GetPropertyInfo: cancelType 1 = refundable, 0 = non-refundable
        $refundable = isset($rateType['cancelType']) && $rateType['cancelType'] !== '' ? (string) $rateType['cancelType'] === '1' : null;
        if ($meal !== null && $refundable !== null) {
            $found = array();
            foreach ($candidates as $plan) {
                $planRefundable = !isset($plan['is_refundable']) || $plan['is_refundable'] !== false;
                if (isset($plan['board']) && strtoupper($plan['board']) === $meal && $planRefundable === $refundable) {
                    $found[] = $plan;
                }
            }
            if (count($found) === 1) {
                return $found[0];
            }
        }
        return $bakuunRateCount === 1 && count($candidates) === 1 ? reset($candidates) : null;
    }

    protected function _property($bakuunHotelId)
    {
        $property = $this->_properties->find($bakuunHotelId);
        if ($property === null) {
            throw new InvalidArgumentException("Bakuun hotel $bakuunHotelId is not linked; run link first");
        }
        return $property;
    }

    /**
     * The property, once the hotel has confirmed the Unit.Travel connection (mapping needs its catalog).
     */
    protected function _activeConnection($bakuunHotelId)
    {
        $this->_property($bakuunHotelId);
        $property = $this->refresh($bakuunHotelId);
        if ($property['ut_connection_status'] !== 'active') {
            throw new InvalidArgumentException(
                "The Unit.Travel connection of hotel $bakuunHotelId is " . ($property['ut_connection_status'] ? $property['ut_connection_status'] : 'missing')
                . '; the hotel has to confirm it in Unit.Travel Extranet'
            );
        }
        return $property;
    }

    protected static function _roomTypes(array $property)
    {
        $data = Bakuun_Store_Properties::staticData($property);
        return isset($data['roomTypes']) && is_array($data['roomTypes']) ? array_values($data['roomTypes']) : array();
    }

    protected static function _bakuunRoomOfRate(array $property, $rateCode)
    {
        foreach (self::_roomTypes($property) as $roomType) {
            foreach (isset($roomType['rateTypes']) && is_array($roomType['rateTypes']) ? $roomType['rateTypes'] : array() as $rateType) {
                if (isset($rateType['ratePlanId']) && (string) $rateType['ratePlanId'] === (string) $rateCode) {
                    return isset($roomType['roomTypeId']) ? (string) $roomType['roomTypeId'] : null;
                }
            }
        }
        return null;
    }

    protected static function _bakuunName(array $property, $type, $code)
    {
        foreach (self::_roomTypes($property) as $roomType) {
            if ($type === Bakuun_Store_Mappings::ROOM && isset($roomType['roomTypeId']) && (string) $roomType['roomTypeId'] === (string) $code) {
                return isset($roomType['roomName']) ? $roomType['roomName'] : null;
            }
            foreach (isset($roomType['rateTypes']) && is_array($roomType['rateTypes']) ? $roomType['rateTypes'] : array() as $rateType) {
                if ($type === Bakuun_Store_Mappings::RATE_PLAN && isset($rateType['ratePlanId']) && (string) $rateType['ratePlanId'] === (string) $code) {
                    return isset($rateType['ratePlanName']) ? $rateType['ratePlanName'] : null;
                }
            }
        }
        return null;
    }

    /**
     * Lower-cased letters and digits only, Unicode-aware (room names are often not Latin).
     */
    protected static function _normalize($text)
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
        return trim(preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $text));
    }

    protected static function _first($data, $field, $default)
    {
        return is_array($data) && isset($data[$field]) && $data[$field] !== '' ? $data[$field] : $default;
    }

    protected static function _firstOf(array $item, array $fields)
    {
        foreach ($fields as $field) {
            if (isset($item[$field]) && $item[$field] !== '') {
                return (string) $item[$field];
            }
        }
        return '';
    }
}
