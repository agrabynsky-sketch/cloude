<?php
/**
 * One item of an Exely `update` request, with Exely's loose types parsed.
 *
 * Fields Exely did not send stay null: Exely omits what it has already delivered.
 */
class Exely_Update
{
    /** @var string Y-m-d */
    public $startDate;
    /** @var string Y-m-d */
    public $endDate;
    /** @var string */
    public $roomTypeId;
    /** @var string|null */
    public $ratePlanId;
    /** @var int|null */
    public $quota;
    /** @var string|null */
    public $currencyCode;
    /** @var array|null list of array('code' => string, 'cents' => int) */
    public $prices;
    /** @var bool|null */
    public $closed;
    /** @var bool|null */
    public $closedToArrival;
    /** @var bool|null */
    public $closedToDeparture;
    /** @var int|null */
    public $minLos;
    /** @var int|null */
    public $maxLos;
    /** @var int|null */
    public $minLosArrivalBased;
    /** @var int|null */
    public $maxLosArrivalBased;
    /** @var int|null */
    public $minimumAdvanceBooking;
    /** @var int|null */
    public $maximumAdvanceBooking;
    /** @var string|null */
    public $mealPlanType;

    protected static $_integers = array(
        'quota', 'minLos', 'maxLos', 'minLosArrivalBased', 'maxLosArrivalBased',
        'minimumAdvanceBooking', 'maximumAdvanceBooking',
    );

    protected static $_booleans = array('closed', 'closedToArrival', 'closedToDeparture');

    /**
     * @param  mixed  $item
     * @param  string $where position in the request, for error messages
     * @return Exely_Update
     * @throws Exely_Failure
     */
    public static function fromArray($item, $where)
    {
        if (!is_array($item)) {
            throw self::_invalid($where, 'expected an object');
        }
        $update = new self();
        $update->startDate = self::_date($item, 'startDateYmd', $where);
        $update->endDate = self::_date($item, 'endDateYmd', $where);
        if ($update->endDate < $update->startDate) {
            throw self::_invalid($where, 'endDateYmd is before startDateYmd');
        }
        $update->roomTypeId = Exely_Request::optionalString($item, 'roomTypeId');
        if ($update->roomTypeId === null) {
            throw self::_invalid($where . '.roomTypeId', 'required');
        }
        $update->ratePlanId = Exely_Request::optionalString($item, 'ratePlanId');
        $update->currencyCode = Exely_Request::optionalString($item, 'currencyCode');
        $update->mealPlanType = Exely_Request::optionalString($item, 'mealPlanType');
        foreach (self::$_integers as $name) {
            $update->$name = self::_parse($item, $name, $where, 'parseInt');
        }
        foreach (self::$_booleans as $name) {
            $update->$name = self::_parse($item, $name, $where, 'parseBool');
        }
        if (isset($item['prices'])) {
            if (!is_array($item['prices'])) {
                throw self::_invalid($where . '.prices', 'expected a list');
            }
            $update->prices = array();
            foreach (array_values($item['prices']) as $index => $price) {
                $at = $where . '.prices[' . $index . ']';
                $code = is_array($price) ? Exely_Request::optionalString($price, 'code') : null;
                if ($code === null) {
                    throw self::_invalid($at . '.code', 'required');
                }
                $cents = self::_parse($price, 'price', $at, 'parseCents');
                if ($cents === null) {
                    throw self::_invalid($at . '.price', 'required');
                }
                $update->prices[] = array('code' => $code, 'cents' => $cents);
            }
        }
        return $update;
    }

    protected static function _date(array $item, $name, $where)
    {
        $value = isset($item[$name]) && is_string($item[$name]) ? substr(trim($item[$name]), 0, 10) : '';
        // Exely types dates as dateTime but sends YYYY-MM-DD; a time part is tolerated.
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw self::_invalid($where . '.' . $name, 'expected a YYYY-MM-DD date');
        }
        return $value;
    }

    protected static function _parse(array $item, $name, $where, $parser)
    {
        if (!isset($item[$name]) || (is_string($item[$name]) && trim($item[$name]) === '')) {
            return null;
        }
        try {
            return call_user_func(array('Exely_Values', $parser), $item[$name]);
        } catch (InvalidArgumentException $e) {
            throw self::_invalid($where . '.' . $name, $e->getMessage());
        }
    }

    protected static function _invalid($where, $message)
    {
        return Exely_Failure::of(Exely_Code::INVALID_REQUEST, 'Invalid request: ' . $where . ': ' . $message);
    }
}
