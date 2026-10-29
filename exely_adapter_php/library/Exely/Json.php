<?php
/**
 * JSON helpers that behave the same on PHP 5.3 and later.
 */
class Exely_Json
{
    /**
     * @param  mixed $value
     * @return string
     * @throws RuntimeException when the value cannot be encoded (for example invalid UTF-8)
     */
    public static function encode($value)
    {
        // JSON_UNESCAPED_UNICODE appeared in PHP 5.4.
        $flags = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0;
        $json = json_encode($value, $flags);
        if (json_last_error() !== JSON_ERROR_NONE || !is_string($json)) {
            throw new RuntimeException('JSON encoding failed with error ' . json_last_error());
        }
        return $json;
    }

    /**
     * @param  string $json
     * @return mixed  the decoded value with objects as arrays, or null for invalid JSON
     */
    public static function decode($json)
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $value = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $value : null;
    }
}
