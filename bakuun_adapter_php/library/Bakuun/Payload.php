<?php
/**
 * Reading Bakuun payloads, whose keys sometimes carry stray spaces ("roomCode ", "HotelRef ").
 */
class Bakuun_Payload
{
    /**
     * The payload with every key trimmed, recursively.
     */
    public static function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $normalized = array();
        foreach ($value as $key => $item) {
            $normalized[is_string($key) ? trim($key) : $key] = self::normalize($item);
        }
        return $normalized;
    }

    /**
     * A scalar field as a trimmed string; null when absent or blank.
     */
    public static function string($data, $name)
    {
        if (!is_array($data) || !isset($data[$name]) || is_bool($data[$name]) || !is_scalar($data[$name])) {
            return null;
        }
        $value = trim((string) $data[$name]);
        return $value === '' ? null : $value;
    }

    /**
     * A list field; a single object is treated as a list of one.
     */
    public static function items($data, $name)
    {
        if (!is_array($data) || !isset($data[$name]) || !is_array($data[$name])) {
            return array();
        }
        $items = $data[$name];
        return isset($items[0]) || !$items ? array_values($items) : array($items);
    }

    /**
     * Whether a value means "clear" in Bakuun ("N/A").
     */
    public static function isClear($value)
    {
        return is_string($value) && strtoupper(trim($value)) === 'N/A';
    }

    /**
     * A validated Y-m-d date field.
     *
     * @throws Bakuun_Failure
     */
    public static function date($data, $name, $code)
    {
        $value = self::string($data, $name);
        $value = $value === null ? '' : substr($value, 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw Bakuun_Failure::of($code, "$name must be a YYYY-MM-DD date");
        }
        return $value;
    }

    /**
     * A validated inclusive date range.
     *
     * @return string[] array(from, to)
     * @throws Bakuun_Failure
     */
    public static function period($data)
    {
        $from = self::date($data, 'startDateYmd', Bakuun_Code::INVALID_START_DATE);
        $to = self::date($data, 'endDateYmd', Bakuun_Code::INVALID_END_DATE);
        if ($to < $from) {
            throw Bakuun_Failure::of(Bakuun_Code::INVALID_DATE_RANGE, 'endDateYmd is before startDateYmd');
        }
        return array($from, $to);
    }
}
