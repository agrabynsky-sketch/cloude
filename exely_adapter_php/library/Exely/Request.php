<?php
/**
 * Reading Exely requests: {"username", "password", "action", "data"}.
 */
class Exely_Request
{
    /**
     * @param  mixed $payload decoded JSON body
     * @return array with keys username, password, action, data
     * @throws Exely_Failure
     */
    public static function envelope($payload)
    {
        if (!is_array($payload) || !isset($payload['action']) || !is_string($payload['action'])) {
            throw Exely_Failure::of(
                Exely_Code::INVALID_REQUEST, 'Malformed request: expected username, password, action and data'
            );
        }
        $data = isset($payload['data']) ? $payload['data'] : array();
        if (!is_array($data)) {
            throw Exely_Failure::of(Exely_Code::INVALID_REQUEST, 'Malformed request: data must be an object');
        }
        return array(
            'username' => isset($payload['username']) ? (string) $payload['username'] : '',
            'password' => isset($payload['password']) ? (string) $payload['password'] : '',
            'action' => $payload['action'],
            'data' => $data,
        );
    }

    /**
     * @param  array $data
     * @return string|null
     */
    public static function hotelId(array $data)
    {
        // The specification spells the field both "hotelId" and "hotelid".
        foreach (array('hotelId', 'hotelid', 'HotelId') as $name) {
            $value = self::optionalString($data, $name);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param  array $data
     * @return Exely_Update[]
     * @throws Exely_Failure
     */
    public static function updates(array $data)
    {
        if (!isset($data['updates']) || !is_array($data['updates'])) {
            throw Exely_Failure::of(Exely_Code::INVALID_REQUEST, 'Invalid request: updates: expected a list');
        }
        $updates = array();
        foreach (array_values($data['updates']) as $index => $item) {
            $updates[] = Exely_Update::fromArray($item, 'updates[' . $index . ']');
        }
        return $updates;
    }

    /**
     * @param  array $data
     * @return array list of array(number, echoToken, externalNumber, status), absent values null
     * @throws Exely_Failure
     */
    public static function confirmations(array $data)
    {
        $items = isset($data['confirmBookings']) ? $data['confirmBookings'] : array();
        if (!is_array($items)) {
            throw Exely_Failure::of(Exely_Code::INVALID_REQUEST, 'Invalid request: confirmBookings: expected a list');
        }
        $confirmations = array();
        foreach (array_values($items) as $index => $item) {
            $number = is_array($item) ? self::optionalString($item, 'number') : null;
            if ($number === null) {
                throw Exely_Failure::of(
                    Exely_Code::INVALID_REQUEST, 'Invalid request: confirmBookings[' . $index . '].number: required'
                );
            }
            $echoToken = self::optionalString($item, 'echoToken');
            if ($echoToken === null) {
                // The specification also spells it "echotToken".
                $echoToken = self::optionalString($item, 'echotToken');
            }
            $confirmations[] = array(
                'number' => $number,
                'echoToken' => $echoToken,
                'externalNumber' => self::optionalString($item, 'externalNumber'),
                'status' => self::optionalString($item, 'status'),
            );
        }
        return $confirmations;
    }

    /**
     * A string or number field as a trimmed string; null when absent or blank.
     *
     * @param  array  $data
     * @param  string $name
     * @return string|null
     */
    public static function optionalString(array $data, $name)
    {
        if (!isset($data[$name]) || is_bool($data[$name]) || !is_scalar($data[$name])) {
            return null;
        }
        $value = trim((string) $data[$name]);
        return $value === '' ? null : $value;
    }
}
