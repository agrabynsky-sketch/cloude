<?php
/**
 * RFC 4122 UUIDs without extensions beyond what PHP 5.3 ships.
 */
class Exely_Uuid
{
    /**
     * @return string random UUID
     */
    public static function v4()
    {
        $bytes = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(16) : null;
        if (!is_string($bytes) || strlen($bytes) !== 16) {
            $bytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }
        return self::_format($bytes, 4);
    }

    /**
     * Name-based (SHA-1) UUID, identical to Python's uuid.uuid5().
     *
     * @param  string $namespace UUID in canonical form
     * @param  string $name
     * @return string
     */
    public static function v5($namespace, $name)
    {
        $hash = sha1(pack('H*', str_replace('-', '', $namespace)) . $name, true);
        return self::_format(substr($hash, 0, 16), 5);
    }

    protected static function _format($bytes, $version)
    {
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | ($version << 4));
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
