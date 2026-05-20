<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Input sanitizer — centralized XSS/injection prevention.
 *
 * Per OWASP: context-aware output encoding, SQL param binding (in DB layer).
 */
final class InputSanitizer
{
    /**
     * Sanitize string for HTML output (XSS prevention).
     * If array, maps recursively. Strips tags and trims strings.
     */
    public static function html(mixed $input): mixed
    {
        if (is_array($input)) {
            $result = [];
            foreach ($input as $k => $v) {
                $result[$k] = self::html($v);
            }
            return $result;
        }
        if (is_string($input)) {
            $stripped = strip_tags($input);
            $trimmed = trim($stripped);
            return htmlspecialchars($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $input;
    }

    /**
     * Sanitize for attribute output.
     */
    public static function attr(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitize string — strip HTML tags, trim.
     */
    public static function string(string $input): string
    {
        return trim(strip_tags($input));
    }

    /**
     * Sanitize email address.
     */
    public static function email(string $input): string
    {
        return filter_var(trim($input), FILTER_SANITIZE_EMAIL) ?: '';
    }

    /**
     * Sanitize integer.
     */
    public static function int(mixed $input): int
    {
        return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitize float/decimal.
     */
    public static function decimal(mixed $input): string
    {
        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $input);
        return is_numeric($cleaned) ? $cleaned : '0.00';
    }

    /**
     * Sanitize URL.
     */
    public static function url(string $input): string
    {
        return filter_var(trim($input), FILTER_SANITIZE_URL) ?: '';
    }

    /**
     * Sanitize slug (alphanumeric + hyphens).
     */
    public static function slug(string $input): string
    {
        $slug = strtolower(trim($input));
        $slug = preg_replace('/[^a-z0-9\-_]/', '-', $slug);
        return preg_replace('/-+/', '-', trim($slug, '-'));
    }

    /**
     * Sanitize phone number.
     */
    public static function phone(string $input): string
    {
        return preg_replace('/[^0-9+\-() ]/', '', $input) ?: '';
    }

    /**
     * Sanitize array recursively.
     */
    public static function array(array $input, string $method = 'string'): array
    {
        return array_map(function ($value) use ($method) {
            if (is_array($value)) {
                return self::array($value, $method);
            }
            if (is_string($value)) {
                return self::$method($value);
            }
            return $value;
        }, $input);
    }

    /**
     * Trim surrounding whitespace recursively.
     */
    public static function trim(mixed $input): mixed
    {
        if (is_array($input)) {
            $result = [];
            foreach ($input as $k => $v) {
                $result[$k] = self::trim($v);
            }
            return $result;
        }
        if (is_string($input)) {
            return trim($input);
        }
        return $input;
    }

    /**
     * Validate slug (alphanumeric + hyphen + underscores).
     */
    public static function alphanumeric(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (preg_match('/^[a-zA-Z0-9\-_]+$/', $input)) {
            return $input;
        }
        return null;
    }
}
