<?php
/**
 * The {"success", "data", "warnings", "errors"} envelope Bakuun expects back.
 */
class Bakuun_Response
{
    /**
     * @param  mixed          $data
     * @param  Bakuun_Issue[] $warnings
     * @return array
     */
    public static function success($data = null, array $warnings = array())
    {
        $body = array('success' => true);
        if ($data !== null) {
            $body['data'] = $data;
        }
        $body['warnings'] = self::_issues($warnings);
        $body['errors'] = array();
        return $body;
    }

    /**
     * @param  Bakuun_Issue[] $errors
     * @param  Bakuun_Issue[] $warnings
     * @return array
     */
    public static function failure(array $errors, array $warnings = array())
    {
        return array('success' => false, 'warnings' => self::_issues($warnings), 'errors' => self::_issues($errors));
    }

    /**
     * HTTP status for a response body built by this class.
     */
    public static function httpStatus(array $body)
    {
        if ($body['success']) {
            return 200;
        }
        return Bakuun_Code::httpStatus(isset($body['errors'][0]['code']) ? $body['errors'][0]['code'] : Bakuun_Code::BAD_REQUEST);
    }

    protected static function _issues(array $issues)
    {
        $list = array();
        foreach ($issues as $issue) {
            $list[] = $issue->toArray();
        }
        return $list;
    }
}
