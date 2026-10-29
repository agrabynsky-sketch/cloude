<?php
/**
 * Lenient parsing of the loosely typed values Exely sends, and money in integer cents.
 *
 * Exely serialises numbers and booleans inconsistently: quotas come as "6", flags as
 * "false", and prices with thousands separators ("15,500.00"; the specification's own
 * samples even contain "5.000.00"). Money is kept in integer cents so no float rounding
 * creeps into prices; this needs a 64-bit PHP build.
 */
class Exely_Values
{
    /** Integer part digits accepted before the value no longer fits a 64-bit integer of cents. */
    const MAX_WHOLE_DIGITS = 15;

    /**
     * Turn "15,500.00", "5.000.00", "1.000,50" or "15,50" into "15500.00" style.
     *
     * @param  mixed $value
     * @return string
     * @throws InvalidArgumentException
     */
    public static function normalizeNumber($value)
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new InvalidArgumentException('not a finite number');
            }
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('expected a number, got ' . gettype($value));
        }
        $s = str_replace(array("\xc2\xa0", ' '), '', trim($value));
        if ($s === '') {
            throw new InvalidArgumentException('empty number');
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $mark = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false) {
            // "1,000" and "1,000,000" group thousands; "15,50" uses a decimal comma.
            $grouped = substr_count($s, ',') > 1 || strlen($s) - $lastComma - 1 == 3;
            $mark = $grouped ? null : ',';
        } elseif ($lastDot !== false) {
            // With several dots ("5.000.00") the last one is the decimal point.
            $mark = '.';
        } else {
            $mark = null;
        }
        if ($mark === null) {
            $normalized = str_replace(',', '', $s);
        } else {
            $position = strrpos($s, $mark);
            $normalized = str_replace(array(',', '.'), '', substr($s, 0, $position)) . '.' . substr($s, $position + 1);
        }
        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $normalized)) {
            throw new InvalidArgumentException('not a number: ' . $value);
        }
        return $normalized;
    }

    /**
     * @param  mixed $value
     * @return int   amount in cents, rounded half up
     * @throws InvalidArgumentException
     */
    public static function parseCents($value)
    {
        list($negative, $whole, $fraction) = self::_split(self::normalizeNumber($value));
        $fraction = str_pad($fraction, 3, '0');
        $cents = (int) $whole * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }
        return $negative ? -$cents : $cents;
    }

    /**
     * @param  mixed $value
     * @return int
     * @throws InvalidArgumentException
     */
    public static function parseInt($value)
    {
        list($negative, $whole, $fraction) = self::_split(self::normalizeNumber($value));
        if (trim($fraction, '0') !== '') {
            throw new InvalidArgumentException('expected an integer, got ' . (is_scalar($value) ? $value : gettype($value)));
        }
        return $negative ? -(int) $whole : (int) $whole;
    }

    /**
     * @param  mixed $value
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function parseBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $text = strtolower(trim($value));
            if (in_array($text, array('true', '1', 'yes'), true)) {
                return true;
            }
            if (in_array($text, array('false', '0', 'no'), true)) {
                return false;
            }
        }
        throw new InvalidArgumentException('expected a boolean, got ' . (is_scalar($value) ? var_export($value, true) : gettype($value)));
    }

    /**
     * @param  int $cents
     * @return string like "675.00"
     */
    public static function formatCents($cents)
    {
        $abs = abs($cents);
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', ($abs - $abs % 100) / 100, $abs % 100);
    }

    /**
     * @param  int $cents
     * @return float|int the amount in currency units, for JSON numbers
     */
    public static function centsToNumber($cents)
    {
        return $cents % 100 === 0 ? $cents / 100 : round($cents / 100, 2);
    }

    /**
     * Split a total into equal parts that add up exactly; the last part takes the remainder.
     *
     * @param  int $total cents
     * @param  int $parts
     * @return int[]
     */
    public static function splitCents($total, $parts)
    {
        $share = (int) floor($total / $parts);
        $amounts = array_fill(0, $parts, $share);
        $amounts[$parts - 1] += $total - $share * $parts;
        return $amounts;
    }

    /**
     * Split a total in proportion to weights; the last part takes the remainder.
     *
     * @param  int   $total   cents
     * @param  int[] $weights
     * @return int[]
     */
    public static function allocateCents($total, array $weights)
    {
        $weightSum = array_sum($weights);
        if ($weightSum <= 0) {
            return self::splitCents($total, count($weights));
        }
        $shares = array();
        $last = count($weights) - 1;
        foreach (array_values($weights) as $index => $weight) {
            if ($index < $last) {
                $shares[] = (int) floor($total * $weight / $weightSum);
            }
        }
        $shares[] = $total - array_sum($shares);
        return $shares;
    }

    /**
     * Integer division rounded half up, for non-negative operands.
     *
     * @param  int $numerator
     * @param  int $denominator
     * @return int
     */
    public static function divideRounded($numerator, $denominator)
    {
        $remainder = $numerator % $denominator;
        $quotient = ($numerator - $remainder) / $denominator;
        return (int) ($remainder * 2 >= $denominator ? $quotient + 1 : $quotient);
    }

    /**
     * Constant-time string comparison (hash_equals() appeared in PHP 5.6).
     *
     * @param  string $known
     * @param  string $given
     * @return bool
     */
    public static function equalsConstantTime($known, $given)
    {
        $known = (string) $known;
        $given = (string) $given;
        if (function_exists('hash_equals')) {
            return hash_equals($known, $given);
        }
        if (strlen($known) !== strlen($given)) {
            return false;
        }
        $difference = 0;
        for ($i = 0, $length = strlen($known); $i < $length; $i++) {
            $difference |= ord($known[$i]) ^ ord($given[$i]);
        }
        return $difference === 0;
    }

    protected static function _split($normalized)
    {
        $negative = $normalized[0] === '-';
        $parts = explode('.', ltrim($normalized, '+-'), 2);
        $whole = ltrim($parts[0], '0');
        if (strlen($whole) > self::MAX_WHOLE_DIGITS) {
            throw new InvalidArgumentException('number too large: ' . $normalized);
        }
        return array($negative, $whole === '' ? '0' : $whole, isset($parts[1]) ? $parts[1] : '');
    }
}
