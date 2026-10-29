<?php
/**
 * Unit.Travel rooms and rate plans → Exely get-rooms-and-rate-plans.
 *
 * Exely is handed Unit.Travel's own ids (roomTypeId = room_id, ratePlanId = rate_plan_id),
 * so no code mapping through /mappings is needed: the property maps them to its Exely
 * rooms in the Exely account.
 *
 * Accommodation codes carry the pricing model, so `update` can be translated without
 * looking the room up again:
 *   "<room_id>_<n>"   per_guest room, the price for n adults
 *   "<room_id>_room"  per_room room, one price for the room
 * Child and extra-bed accommodations are not offered: Unit.Travel ARI takes no child prices.
 */
class Exely_Catalog
{
    const PER_ROOM_SUFFIX = 'room';

    /**
     * @param  int      $roomId
     * @param  int|null $adults null for a per-room price
     * @return string
     */
    public static function occupancyCode($roomId, $adults)
    {
        return $roomId . '_' . ($adults ? $adults : self::PER_ROOM_SUFFIX);
    }

    /**
     * @param  string $code
     * @param  int    $roomId
     * @return int|null the number of adults the code prices, or null for a per-room price
     * @throws InvalidArgumentException for a code of another room or of an unknown format
     */
    public static function parseOccupancyCode($code, $roomId)
    {
        $code = trim($code);
        $separator = strrpos($code, '_');
        if ($separator === false || substr($code, 0, $separator) !== (string) $roomId) {
            throw new InvalidArgumentException("accommodation code '$code' does not belong to room type $roomId");
        }
        $suffix = substr($code, $separator + 1);
        if ($suffix === self::PER_ROOM_SUFFIX) {
            return null;
        }
        if (ctype_digit($suffix) && (int) $suffix > 0) {
            return (int) $suffix;
        }
        throw new InvalidArgumentException("unknown accommodation code '$code'");
    }

    /**
     * @param  array $rooms     Unit.Travel Room objects
     * @param  array $ratePlans Unit.Travel RatePlan objects
     * @param  array $scope     Connection.scope
     * @return array array(roomsAndRatePlans, Exely_Issue[] warnings)
     */
    public static function build(array $rooms, array $ratePlans, array $scope)
    {
        $plansByRoom = array();
        foreach ($ratePlans as $plan) {
            if (isset($plan['room_id'])) {
                $plansByRoom[(int) $plan['room_id']][] = $plan;
            }
        }
        $catalog = array();
        $warnings = array();
        foreach ($rooms as $room) {
            $roomId = (int) $room['id'];
            $name = self::_name($room);
            if (empty($plansByRoom[$roomId])) {
                // Exely requires at least one rate plan per room type.
                $warnings[] = new Exely_Issue(
                    Exely_Code::ROOM_SKIPPED, "Room type $roomId ($name) has no rate plans; not offered"
                );
                continue;
            }
            $entries = array();
            foreach ($plansByRoom[$roomId] as $plan) {
                $entries[] = self::_ratePlan($plan, $scope);
            }
            $catalog[] = array(
                'roomTypeId' => (string) $roomId,
                'roomName' => $name,
                'ratePlans' => $entries,
                'occupancies' => self::_occupancies($room),
            );
        }
        return array($catalog, $warnings);
    }

    protected static function _ratePlan(array $plan, array $scope)
    {
        $entry = array('ratePlanId' => (string) $plan['id'], 'ratePlanName' => self::_name($plan));
        $unsupported = array();
        if (isset($scope['rates']) && $scope['rates'] === false) {
            $unsupported['prices'] = true;
        }
        if (isset($scope['restrictions']) && $scope['restrictions'] === false) {
            $unsupported['restrictions'] = true;
        }
        if ($unsupported) {
            $entry['nonSupportedUpdates'] = $unsupported;
        }
        return $entry;
    }

    protected static function _occupancies(array $room)
    {
        $roomId = (int) $room['id'];
        $base = self::_firstPositive($room, array('base_occupancy', 'max_adults'));
        if (isset($room['pricing_model']) && $room['pricing_model'] === 'per_guest') {
            $most = self::_firstPositive($room, array('max_adults', 'max_occupancy', 'base_occupancy'));
            $occupancies = array();
            for ($adults = 1; $adults <= $most; $adults++) {
                $occupancies[] = self::_adultBed(self::occupancyCode($roomId, $adults), $adults);
            }
            return $occupancies;
        }
        return array(self::_adultBed(self::occupancyCode($roomId, null), $base));
    }

    protected static function _adultBed($code, $adults)
    {
        return array('code' => $code, 'bedType' => 'adultBed', 'personQuantity' => $adults);
    }

    protected static function _firstPositive(array $room, array $fields)
    {
        foreach ($fields as $field) {
            if (!empty($room[$field]) && (int) $room[$field] > 0) {
                return (int) $room[$field];
            }
        }
        return 1;
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
