<?php
/**
 * Unit.Travel failures → Bakuun error codes.
 */
class Bakuun_ErrorMap
{
    /**
     * @return Bakuun_Issue[]
     */
    public static function fromUnitTravel(UnitTravel_Exception $e)
    {
        $status = $e->getStatus();
        if ($status === 409) {
            return array(new Bakuun_Issue(Bakuun_Code::MAPPING_NOT_ACTIVE, 'Unit.Travel: ' . $e->getMessage()));
        }
        if ($status === 404) {
            return array(new Bakuun_Issue(Bakuun_Code::INVALID_HOTEL, 'Unit.Travel: ' . $e->getMessage()));
        }
        if ($status === 429) {
            $wait = $e->getRetryAfter() ? ', retry in ' . $e->getRetryAfter() . 's' : '';
            return array(new Bakuun_Issue(Bakuun_Code::SERVICE_UNAVAILABLE, 'Unit.Travel rate limit exceeded' . $wait));
        }
        if ($status === 400 || $status === 422) {
            return self::fromAriErrors(array(array(
                'code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'details' => $e->getDetails(),
            )));
        }
        if ($status === 401 || $status === 403) {
            return array(new Bakuun_Issue(Bakuun_Code::INTERNAL_ERROR, 'The adapter is not authorised in Unit.Travel'));
        }
        return array(new Bakuun_Issue(Bakuun_Code::BAD_GATEWAY, 'Unit.Travel is unavailable: ' . $e->getMessage()));
    }

    /**
     * @param  array $errors Unit.Travel Error objects
     * @return Bakuun_Issue[]
     */
    public static function fromAriErrors(array $errors)
    {
        $issues = array();
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $code = isset($error['code']) ? (string) $error['code'] : '';
            $message = !empty($error['message']) ? (string) $error['message'] : ($code !== '' ? $code : 'error');
            $details = isset($error['details']) && is_array($error['details']) ? $error['details'] : array();
            if (!$details) {
                $issues[] = new Bakuun_Issue(self::_classify($code), 'Unit.Travel: ' . $message);
            }
            foreach ($details as $detail) {
                $path = isset($detail['path']) ? (string) $detail['path'] : '';
                $reason = !empty($detail['reason']) ? (string) $detail['reason'] : $message;
                $issues[] = new Bakuun_Issue(
                    self::_classify("$path $code"), 'Unit.Travel: ' . ($path !== '' ? "$path: $reason" : $reason)
                );
            }
        }
        if (!$issues) {
            $issues[] = new Bakuun_Issue(Bakuun_Code::PROCESSING_ERROR, 'Unit.Travel failed to apply the update');
        }
        return $issues;
    }

    protected static function _classify($text)
    {
        $text = strtolower($text);
        if (strpos($text, 'connection') !== false) {
            return Bakuun_Code::MAPPING_NOT_ACTIVE;
        }
        if (strpos($text, 'occupanc') !== false || strpos($text, 'pricing_model') !== false) {
            return Bakuun_Code::OCCUPANCY_NOT_ALLOWED;
        }
        if (strpos($text, 'rate_plan') !== false) {
            return Bakuun_Code::INVALID_RATE_PLAN_MAPPING;
        }
        if (strpos($text, 'room') !== false) {
            return Bakuun_Code::INVALID_ROOM_MAPPING;
        }
        if (strpos($text, 'date') !== false) {
            return Bakuun_Code::INVALID_DATE_RANGE;
        }
        return Bakuun_Code::INVALID_DATA;
    }
}
