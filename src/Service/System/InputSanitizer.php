<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Service facilitating centralized input sanitization and verification.
 *
 * Implements context-aware sanitization routines to prevent common vulnerabilities
 * such as Cross-Site Scripting (XSS) and injection attacks, aligning with OWASP best practices.
 */
final class InputSanitizer
{
    /**
     * Sanitizes input to prevent HTML injection and XSS exploits.
     *
     * Processes input arrays recursively. Note: In compliance with database architecture
     * guidelines, this method avoids pre-escaping characters with `htmlspecialchars` to prevent
     * double-encoding, since data is stored raw and escaped dynamically by templates (e.g. Twig).
     *
     * @param mixed $input Value to sanitize (array, string, numeric, object, etc.).
     * @return mixed The stripped and trimmed output matching the source structure type.
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
            // BUG-24 FIX: Do NOT pre-escape with htmlspecialchars here.
            // Data is stored raw; Twig's auto-escaping handles output.
            // Pre-escaping causes double-encoding (e.g., &amp;amp;).
            return trim(strip_tags($input));
        }
        return $input;
    }

    /**
     * Encodes a string for safe output within HTML attributes.
     *
     * @param string $input String to sanitize.
     * @return string Attribute-safe encoded string.
     */
    public static function attr(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitizes a string by stripping HTML tags and trimming outer whitespace.
     *
     * @param string $input String to sanitize.
     * @return string Cleansed string.
     */
    public static function string(string $input): string
    {
        return trim(strip_tags($input));
    }

    /**
     * Sanitizes an email address, removing forbidden characters.
     *
     * @param string $input Email string candidate.
     * @return string Sanitized email string.
     */
    public static function email(string $input): string
    {
        return filter_var(trim($input), FILTER_SANITIZE_EMAIL) ?: '';
    }

    /**
     * Sanitizes a numeric value into an integer structure.
     *
     * @param mixed $input Target variable to sanitize.
     * @return int Sanitized integer value.
     */
    public static function int(mixed $input): int
    {
        return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitizes a currency or decimal value string, removing non-numeric markers.
     *
     * @param mixed $input Decimal candidate representation.
     * @return string Normalized decimal string.
     */
    public static function decimal(mixed $input): string
    {
        $strInput = is_scalar($input) ? (string) $input : '';
        $cleaned = preg_replace('/[^0-9.\-]/', '', $strInput);
        return is_numeric($cleaned) ? $cleaned : '0.00';
    }

    /**
     * Sanitizes a URL, stripping forbidden path and protocol characters.
     *
     * @param string $input URL string candidate.
     * @return string Sanitized URL.
     */
    public static function url(string $input): string
    {
        return filter_var(trim($input), FILTER_SANITIZE_URL) ?: '';
    }

    /**
     * Formats a string to compile with standard alphanumeric slug conventions.
     *
     * Converts characters to lowercase and transforms non-alphanumeric elements to hyphens.
     *
     * @param string $input Source string candidate.
     * @return string Slug formatted string.
     */
    public static function slug(string $input): string
    {
        $slug = strtolower(trim($input));
        $slug = (string) preg_replace('/[^a-z0-9\-_]/', '-', $slug);
        return (string) preg_replace('/-+/', '-', trim($slug, '-'));
    }

    /**
     * Sanitizes a telephone string, preserving standard phone symbols.
     *
     * @param string $input Telephone input string candidate.
     * @return string Sanitized phone number.
     */
    public static function phone(string $input): string
    {
        return preg_replace('/[^0-9+\-() ]/', '', $input) ?: '';
    }

    /**
     * Processes arrays recursively using a selected local sanitization routine.
     *
     * Refuses execution if an unregistered helper method is requested, preventing
     * dynamic invocation exploits.
     *
     * @param array<mixed> $input Target dataset array.
     * @param string $method Target sanitization filter method name.
     * @return array<mixed> The sanitized array output.
     * @throws \InvalidArgumentException If the selected sanitization method name is not permitted.
     */
    public static function array(array $input, string $method = 'string'): array
    {
        // BUG-022 FIX: Only allow known sanitization methods to prevent
        // arbitrary static method invocation via dynamic dispatch.
        $allowedMethods = ['string', 'html', 'email', 'url', 'phone', 'slug', 'attr', 'trim'];
        if (!in_array($method, $allowedMethods, true)) {
            throw new \InvalidArgumentException("Unsupported sanitization method: {$method}");
        }

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
     * Recursively trims whitespace from strings and array elements.
     *
     * @param mixed $input Value candidate to trim.
     * @return mixed The trimmed value matching the source structure type.
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
     * Validates and returns a string if it conforms strictly to alphanumeric conventions.
     *
     * Allows letters, numbers, hyphens, and underscores.
     *
     * @param string $input Alphanumeric string candidate.
     * @return string|null The trimmed validated string, or null if empty or containing invalid markers.
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
