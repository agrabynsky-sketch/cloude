<?php
/**
 * Bakuun error codes (Buyer API (Push) → Error Code) plus the adapter's own warning codes.
 */
class Bakuun_Code
{
    const BAD_REQUEST = 400;
    const INVALID_HOTEL = 401;
    const INVALID_ROOM = 402;
    const INVALID_RATE_PLAN = 403;
    const INVALID_START_DATE = 404;
    const INVALID_END_DATE = 405;
    const INVALID_DATE_RANGE = 406;
    const DATA_MISSING = 407;
    const PROCESSING_ERROR = 408;
    const UNAUTHORISED = 410;
    const INVALID_DATA = 411;
    const INVALID_REQUEST = 412;
    const TOKEN_EXPIRED = 414;
    const RESERVATION_ALREADY_COMPLETED = 416;
    const TOKEN_MISSING = 421;
    const INVALID_TOKEN = 424;
    const INVALID_ACTION = 430;
    const INVALID_MAPPING = 435;
    const RESERVATION_ALREADY_CANCELLED = 438;
    const RESERVATION_ID_EXISTS = 448;
    const INVALID_ROOM_MAPPING = 451;
    const INVALID_RATE_PLAN_MAPPING = 452;
    const MAPPING_NOT_ACTIVE = 453;
    const INVALID_RATE_TYPE = 475;
    const RESERVATION_NOT_FOUND = 476;
    const NO_BOOKING = 477;
    const OCCUPANCY_NOT_ALLOWED = 478;
    const INTERNAL_ERROR = 500;
    const BAD_GATEWAY = 502;
    const SERVICE_UNAVAILABLE = 503;
    const TIMEOUT = 504;

    // Adapter warning codes (Bakuun defines none).
    const UNSUPPORTED_VALUE = 901;
    const STILL_PROCESSING = 902;
    const NOT_PERMITTED = 903;

    /**
     * HTTP status for a response whose first error has this code.
     */
    public static function httpStatus($code)
    {
        if ($code === self::UNAUTHORISED) {
            return 401;
        }
        if (in_array($code, array(self::INTERNAL_ERROR, self::BAD_GATEWAY, self::SERVICE_UNAVAILABLE, self::TIMEOUT), true)) {
            return $code;
        }
        return 400;
    }

    /**
     * Whether a Bakuun error is worth retrying: its side was unavailable, not the request wrong.
     */
    public static function isTransient($code)
    {
        return in_array((int) $code, array(
            self::PROCESSING_ERROR, self::INTERNAL_ERROR, self::BAD_GATEWAY, self::SERVICE_UNAVAILABLE, self::TIMEOUT,
        ), true);
    }
}
