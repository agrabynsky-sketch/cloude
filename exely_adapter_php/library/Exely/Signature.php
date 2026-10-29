<?php
/**
 * The HMAC-SHA256 signature Unit.Travel puts on its webhooks.
 */
class Exely_Signature
{
    const HEADER = 'X-UnitTravel-Signature';

    /**
     * @param  string      $secret
     * @param  string      $body      raw request body
     * @param  string|null $signature header value: hex digest, optionally "sha256=" prefixed
     * @return bool
     */
    public static function verify($secret, $body, $signature)
    {
        if (!is_string($signature) || trim($signature) === '' || (string) $secret === '') {
            return false;
        }
        $received = strtolower(trim($signature));
        if (strpos($received, 'sha256=') === 0) {
            $received = substr($received, strlen('sha256='));
        }
        return Exely_Values::equalsConstantTime(hash_hmac('sha256', (string) $body, $secret), $received);
    }

    /**
     * @return string the header value for a body, as Unit.Travel would send it
     */
    public static function sign($secret, $body)
    {
        return hash_hmac('sha256', (string) $body, $secret);
    }
}
