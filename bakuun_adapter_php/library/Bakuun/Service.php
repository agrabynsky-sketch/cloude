<?php
/**
 * Requests Bakuun sends to the adapter: ARI pushes and the mapping lookups getRoom / getRoomRatePlan.
 */
class Bakuun_Service
{
    const CONNECTION_NOTE = 'Bakuun';

    protected static $_defaults = array(
        // Credentials Bakuun sends in every request (issued by us).
        'inboundUsername' => '',
        'inboundPassword' => '',
        'ari' => array(),
        'sender' => array(),
    );

    protected $_options;
    protected $_client;
    protected $_properties;
    protected $_mappings;
    protected $_restrictions;
    protected $_sender;
    protected $_log;

    public function __construct(
        array $options, UnitTravel_Client $client, Bakuun_Store_Properties $properties, Bakuun_Store_Mappings $mappings,
        Bakuun_Store_Restrictions $restrictions, Zend_Log $log = null
    ) {
        $this->_options = array_merge(self::$_defaults, $options);
        $this->_client = $client;
        $this->_properties = $properties;
        $this->_mappings = $mappings;
        $this->_restrictions = $restrictions;
        $this->_sender = new UnitTravel_AriSender($client, array_merge(array('uidPrefix' => 'bakuun'), (array) $this->_options['sender']));
        $this->_log = $log ? $log : new Zend_Log(new Zend_Log_Writer_Null());
    }

    /**
     * Answer one Bakuun request; never throws.
     *
     * @param  mixed $payload decoded JSON body
     * @return array response body (see Bakuun_Response::httpStatus for its HTTP status)
     */
    public function handle($payload)
    {
        $payload = Bakuun_Payload::normalize($payload);
        if (!is_array($payload) || Bakuun_Payload::string($payload, 'action') === null) {
            return Bakuun_Response::failure(array(new Bakuun_Issue(Bakuun_Code::BAD_REQUEST, 'Malformed request: expected a JSON object with action')));
        }
        $userOk = UnitTravel_Values::equalsConstantTime($this->_options['inboundUsername'], (string) Bakuun_Payload::string($payload, 'username'));
        $passwordOk = UnitTravel_Values::equalsConstantTime($this->_options['inboundPassword'], (string) Bakuun_Payload::string($payload, 'password'));
        if (!$userOk || !$passwordOk || $this->_options['inboundPassword'] === '') {
            return Bakuun_Response::failure(array(new Bakuun_Issue(Bakuun_Code::UNAUTHORISED, 'Unauthorised user')));
        }
        $action = Bakuun_Payload::string($payload, 'action');
        try {
            switch (strtolower($action)) {
                case 'getroom':
                    return $this->_getRoom($payload);
                case 'getroomrateplan':
                    return $this->_getRoomRatePlan($payload);
            }
            if (!Bakuun_Ari::isAriAction($action)) {
                throw Bakuun_Failure::of(Bakuun_Code::INVALID_ACTION, "Invalid action '$action'");
            }
            return $this->_ari($action, $payload);
        } catch (Bakuun_Failure $e) {
            $this->_log->info("Bakuun $action failed: " . $e->getMessage());
            return Bakuun_Response::failure($e->getErrors(), $e->getWarnings());
        } catch (UnitTravel_AriFailedException $e) {
            $this->_log->warn("Unit.Travel failed to apply Bakuun $action");
            return Bakuun_Response::failure(Bakuun_ErrorMap::fromAriErrors($e->getErrors()));
        } catch (UnitTravel_Exception $e) {
            $this->_log->warn("Unit.Travel call for Bakuun $action failed: $e");
            return Bakuun_Response::failure(Bakuun_ErrorMap::fromUnitTravel($e));
        } catch (Exception $e) {
            $this->_log->err("Bakuun $action crashed: " . get_class($e) . ': ' . $e->getMessage());
            return Bakuun_Response::failure(array(new Bakuun_Issue(Bakuun_Code::INTERNAL_ERROR, 'Internal server error')));
        }
    }

    // ARI

    protected function _ari($action, array $payload)
    {
        $hotelId = Bakuun_Payload::string($payload, 'hotelId');
        if ($hotelId === null) {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, 'hotelId is required');
        }
        $property = $this->_properties->find($hotelId);
        if ($property === null) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_HOTEL, "Hotel '$hotelId' is not mapped to Unit.Travel");
        }
        if ($property['status'] !== Bakuun_Store_Properties::ACTIVE) {
            throw Bakuun_Failure::of(Bakuun_Code::MAPPING_NOT_ACTIVE, "Mapping of hotel '$hotelId' is {$property['status']}");
        }
        $catalog = new Bakuun_Catalog($this->_client, $property['ut_property_id']);
        $translator = new Bakuun_Ari($this->_mappings, $this->_restrictions, $catalog, $hotelId, (array) $this->_options['ari']);
        $parts = $translator->translate($action, $payload);
        $warnings = $parts->warnings;
        if (!$parts->isEmpty()) {
            foreach ($this->_sender->send($property['ut_property_id'], $parts) as $uid) {
                // Failing would make Bakuun resend and pile up duplicates while Unit.Travel is slow.
                $this->_log->warn("ARI message $uid not applied yet after waiting");
                $warnings[] = new Bakuun_Issue(Bakuun_Code::STILL_PROCESSING, "Unit.Travel accepted update $uid but has not applied it yet");
            }
        }
        foreach ($warnings as $warning) {
            $this->_log->notice("Bakuun $action for $hotelId: {$warning->message}");
        }
        return Bakuun_Response::success(null, $warnings);
    }

    // Mapping lookups

    protected function _getRoom(array $payload)
    {
        $hotelRef = isset($payload['HotelRef']) ? $payload['HotelRef'] : null;
        $property = $this->_propertyForMapping(Bakuun_Payload::string($hotelRef, 'code'));
        $hotelId = $property['bakuun_hotel_id'];
        $list = array();
        foreach ($this->_client->getRooms($property['ut_property_id']) as $room) {
            $name = self::_name($room);
            // Bakuun maps our ids on its side and sends them back in ARI pushes.
            $this->_mappings->save($hotelId, Bakuun_Store_Mappings::ROOM, (string) $room['id'], $room['id'], null, $name, Bakuun_Store_Mappings::SOURCE_PARTNER);
            $list[] = array('roomTypeId' => (string) $room['id'], 'roomName' => $name, 'type' => 'Daily Room');
        }
        return Bakuun_Response::success(array('hotelId' => $hotelId, 'hotelRoomLists' => $list));
    }

    protected function _getRoomRatePlan(array $payload)
    {
        $hotelRef = isset($payload['HotelRef']) ? $payload['HotelRef'] : null;
        $property = $this->_propertyForMapping(Bakuun_Payload::string($hotelRef, 'code'));
        $hotelId = $property['bakuun_hotel_id'];
        $roomCode = Bakuun_Payload::string(isset($hotelRef['Room']) ? $hotelRef['Room'] : null, 'code');
        if ($roomCode === null) {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, 'HotelRef.Room.code is required');
        }
        $catalog = new Bakuun_Catalog($this->_client, $property['ut_property_id']);
        $mapped = $this->_mappings->find($hotelId, Bakuun_Store_Mappings::ROOM, $roomCode);
        $room = $catalog->room($mapped ? $mapped['ut_id'] : (ctype_digit($roomCode) ? $roomCode : 0));
        if ($room === null) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_ROOM, "Room '$roomCode' does not exist");
        }
        $ratePlans = array();
        foreach ($catalog->ratePlansOfRoom($room['id']) as $plan) {
            $this->_mappings->save(
                $hotelId, Bakuun_Store_Mappings::RATE_PLAN, (string) $plan['id'], $plan['id'], $room['id'], self::_name($plan),
                Bakuun_Store_Mappings::SOURCE_PARTNER
            );
            $ratePlans[] = self::_ratePlanDetails($plan, $room);
        }
        return Bakuun_Response::success(array(
            'hotelId' => $hotelId, 'Room' => array('roomTypeId' => $roomCode, 'ratePlans' => $ratePlans),
        ));
    }

    /**
     * The linked property a mapping lookup is about. Bakuun asking about a property we have
     * not linked yet starts its onboarding: the Unit.Travel connection is requested and the
     * hotel confirms it in Extranet.
     *
     * @return array property row with an active Unit.Travel connection
     */
    protected function _propertyForMapping($code)
    {
        if ($code === null) {
            throw Bakuun_Failure::of(Bakuun_Code::DATA_MISSING, 'HotelRef.code is required');
        }
        $property = $this->_properties->find($code);
        if ($property === null && ctype_digit($code)) {
            $property = $this->_properties->findByUtProperty($code);
        }
        if ($property !== null && in_array($property['status'], array(Bakuun_Store_Properties::SUSPENDED, Bakuun_Store_Properties::DISCONNECTED), true)) {
            throw Bakuun_Failure::of(Bakuun_Code::MAPPING_NOT_ACTIVE, "Mapping of hotel '$code' is {$property['status']}");
        }
        if ($property === null && !ctype_digit($code)) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_HOTEL, "Hotel '$code' is unknown to Unit.Travel");
        }
        $hotelId = $property ? $property['bakuun_hotel_id'] : $code;
        $utPropertyId = $property ? (int) $property['ut_property_id'] : (int) $code;
        try {
            $connection = $this->_client->createConnection($utPropertyId, self::CONNECTION_NOTE, $hotelId);
        } catch (UnitTravel_Exception $e) {
            if ($e->getStatus() === 404) {
                throw Bakuun_Failure::of(Bakuun_Code::INVALID_HOTEL, "Hotel '$code' does not exist in Unit.Travel");
            }
            throw $e;
        }
        $active = isset($connection['status']) && $connection['status'] === 'active';
        $fields = array(
            'ut_property_id' => $utPropertyId,
            'ut_connection_id' => isset($connection['connection_id']) ? $connection['connection_id'] : null,
            'ut_connection_status' => isset($connection['status']) ? $connection['status'] : null,
        );
        if ($property === null) {
            $fields['source'] = Bakuun_Store_Properties::SOURCE_PARTNER;
        }
        if ($active && ($property === null || $property['source'] === Bakuun_Store_Properties::SOURCE_PARTNER)) {
            // Bakuun maps our own codes, so nothing else is needed before ARI can flow.
            $fields['status'] = Bakuun_Store_Properties::ACTIVE;
        }
        $this->_properties->save($hotelId, $fields);
        if (!$active) {
            throw Bakuun_Failure::of(
                Bakuun_Code::MAPPING_NOT_ACTIVE, "Hotel '$code' has to confirm the Bakuun connection in Unit.Travel Extranet"
            );
        }
        return $this->_properties->find($hotelId);
    }

    protected static function _ratePlanDetails(array $plan, array $room)
    {
        $board = isset($plan['board']) ? strtoupper($plan['board']) : 'RO';
        return array(
            'ratePlanId' => (string) $plan['id'],
            'ratePlanName' => self::_name($plan),
            // Bakuun's getRoomRatePlan: 1 = non-refundable, 0 = refundable
            'cancelType' => isset($plan['is_refundable']) && $plan['is_refundable'] === false ? 1 : 0,
            'authPerson' => !empty($room['max_occupancy']) ? (int) $room['max_occupancy'] : Bakuun_Catalog::maxAdults($room),
            'extraBed' => 0,
            'extraCradles' => !empty($room['max_infants']) ? (int) $room['max_infants'] : 0,
            'default_breakfast' => in_array($board, array('BB', 'HB', 'FB', 'AI'), true) ? 1 : 0,
            'default_halfMeal' => $board === 'HB' ? 1 : 0,
            'default_fullMeal' => in_array($board, array('FB', 'AI'), true) ? 1 : 0,
        );
    }

    protected static function _name(array $item)
    {
        foreach (array('name', 'code', 'id') as $field) {
            if (isset($item[$field]) && $item[$field] !== '') {
                return (string) $item[$field];
            }
        }
        return '';
    }
}
