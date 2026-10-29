<?php
/**
 * Error and warning codes returned to Exely.
 */
class Exely_Code
{
    // Error codes defined by the Exely specification.
    const HOTEL_NOT_CONNECTED = 375;
    const HOTEL_NOT_FOUND = 361;
    const INVALID_ROOM_TYPE = 730;
    const INVALID_RATE_PLAN = 436;
    const INVALID_ACCOMMODATION = 368;
    const INVALID_CURRENCY = 360;

    // Adapter error codes (the specification lets a channel define its own).
    const INVALID_CREDENTIALS = 901;
    const INVALID_REQUEST = 902;
    const UNKNOWN_ACTION = 903;
    const RATE_LIMITED = 904;
    const UPSTREAM_ERROR = 905;
    const INTERNAL_ERROR = 906;

    // Adapter warning codes.
    const UNSUPPORTED_VALUE = 951;
    const STILL_PROCESSING = 952;
    const ROOM_SKIPPED = 953;
    const UNKNOWN_BOOKING = 954;
    const NOT_PERMITTED = 955;
}
