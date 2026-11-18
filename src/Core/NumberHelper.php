<?php

declare(strict_types=1);

namespace OwnPay\Core;

/**
 * Small numeric hygiene helpers for feeding bcmath.
 *
 * Money is bcmath strings, never floats. PHP can hold INF/NAN in a float
 * (e.g. `(float) '1e999'`), and PHP 8 bcmath functions throw a ValueError
 * on such input - which previously turned a validation bug into a crash.
 * Routing through toNumericString() gives every call site a predictable
 * failure mode and a static guarantee for PHPStan level 9.
 */
final class NumberHelper
{
    /**
     * Normalizes an int, float or numeric-string into a bcmath-safe numeric string.
     *
     * Refusing garbage is safer than feeding it to money math: an unparseable or
     * non-finite value aborts the operation with a clear exception instead of a
     * plausible-looking result (or a bare ValueError).
     *
     * @param mixed $value The untrusted value to normalize.
     * @return numeric-string A bcmath-safe numeric string.
     * @throws \InvalidArgumentException When the value is not finite numeric input.
     */
    public static function toNumericString(mixed $value): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && is_numeric($trimmed) && is_finite((float) $trimmed)) {
                return $trimmed;
            }
            throw new \InvalidArgumentException('Value is not a finite numeric string; it cannot be used for bcmath.');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Value is not finite; it cannot be used for bcmath.');
            }
            // number_format with a fixed scale, then trim trailing zeros for readability.
            $formatted = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
            if (!is_numeric($formatted)) {
                // number_format with finite input cannot get here; keep the invariant explicit.
                throw new \InvalidArgumentException('Value could not be rendered as a numeric string.');
            }
            return $formatted;
        }

        throw new \InvalidArgumentException('Expected int, float or numeric-string, got ' . get_debug_type($value) . '.');
    }
}
