<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Modern replacement for procedural sanitize_html() and clean_input().
 *
 * Provides static methods for input sanitization:
 *   - html(): XSS-safe output encoding (strip_tags + htmlspecialchars)
 *   - trim(): Whitespace trimming for DB-bound values (PDO handles escaping)
 */
final class InputSanitizer
{
    /**
     * Sanitize a value for safe HTML output (XSS prevention).
     *
     * Strips HTML tags and encodes special characters. Use when displaying
     * user input in HTML templates.
     *
     * Replaces: sanitize_html()
     *
     * @param mixed $value String, array, or other value
     * @return mixed Sanitized value (same type as input)
     */
    public static function html(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'html'], $value);
        }

        if (is_string($value)) {
            return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $value;
    }

    /**
     * Trim whitespace from input for use with parameterized queries.
     *
     * Only trims — PDO handles SQL escaping via prepared statements.
     *
     * Replaces: clean_input()
     *
     * @param mixed $value String, array, or other value
     * @return mixed Trimmed value (same type as input)
     */
    public static function trim(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'trim'], $value);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Validate that a value is strictly alphanumeric with hyphens/underscores.
     *
     * Returns the sanitized value or null if it contains invalid characters.
     * Useful for slug/ID validation.
     *
     * @param string $value Input to validate
     * @return string|null Validated value or null
     */
    public static function alphanumeric(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $value)) {
            return null;
        }

        return self::html($value);
    }
}
