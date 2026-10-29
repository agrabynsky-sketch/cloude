<?php
/**
 * The {"success", "data", "errors", "warnings"} envelope Exely expects back.
 */
class Exely_Response
{
    /**
     * @param  mixed         $data
     * @param  Exely_Issue[] $warnings
     * @return array
     */
    public static function success($data = null, array $warnings = array())
    {
        $body = array('success' => true, 'errors' => array(), 'warnings' => self::_issues($warnings));
        if ($data !== null) {
            $body['data'] = $data;
        }
        return $body;
    }

    /**
     * @param  Exely_Issue[] $errors
     * @param  Exely_Issue[] $warnings
     * @return array
     */
    public static function failure(array $errors, array $warnings = array())
    {
        return array('success' => false, 'errors' => self::_issues($errors), 'warnings' => self::_issues($warnings));
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
