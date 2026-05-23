<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Class LogSanitizer
 *
 * Provides functionality to sanitize log logs, arrays, strings, and JSON objects
 * by redacting or masking sensitive data fields (e.g., credentials, tokens, PII, payment card numbers)
 * in compliance with OWASP guidelines and PCI DSS requirements.
 *
 * @package OwnPay\Security
 */
final class LogSanitizer
{
    /**
     * @var bool Flag indicating if strict scanning rules are active.
     */
    private bool $strict;

    /**
     * @var array<int, string> List of keys designated for absolute redaction.
     */
    private const REDACT_KEYS = [
        'password', 'password_hash', 'password_confirm',
        'totp_secret', 'totp_secret_enc',
        'secret', 'key_hash', 'api_key', 'bearer_token',
        'jwt', 'token', 'refresh_token',
        'credentials_enc', 'webhook_secret',
        'credit_card', 'card_number', 'cvv', 'cvc',
        'ssn', 'social_security', 'authorization', 'signing_secret'
    ];

    /**
     * @var array<int, string> List of keys designated for masking or partial redaction.
     */
    private const MASK_KEYS = [
        'email', 'phone', 'name', 'ip_address',
        'email_enc', 'phone_enc', 'name_enc',
    ];

    /**
     * LogSanitizer constructor.
     *
     * @param bool $strict If true, applies strict validation rules (e.g. redacting NID numbers).
     */
    public function __construct(bool $strict = false)
    {
        $this->strict = $strict;
    }

    /**
     * Sanitizes an array recursively to redact or mask sensitive parameters before logging.
     *
     * @param array<string, mixed> $data The raw key-value dataset.
     * @return array<string, mixed> The sanitized key-value dataset.
     */
    public static function sanitize(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);

            // Execute full redaction rules.
            if (in_array($lowerKey, self::REDACT_KEYS, true) || self::containsSensitiveKey($lowerKey)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            // Execute partial masking rules.
            if (in_array($lowerKey, self::MASK_KEYS, true)) {
                if ($lowerKey === 'email' || $lowerKey === 'email_enc') {
                    $result[$key] = '[EMAIL_REDACTED]';
                } elseif ($lowerKey === 'phone' || $lowerKey === 'phone_enc') {
                    $result[$key] = '[PHONE_REDACTED]';
                } elseif ($lowerKey === 'name' || $lowerKey === 'name_enc') {
                    $result[$key] = '[NAME_REDACTED]';
                } else {
                    $result[$key] = '[REDACTED]';
                }
                continue;
            }

            // Traverse nested arrays recursively.
            if (is_array($value)) {
                $stringKeyedValue = [];
                foreach ($value as $k => $v) {
                    $stringKeyedValue[(string) $k] = $v;
                }
                $result[$key] = self::sanitize($stringKeyedValue);
                continue;
            }

            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Non-static wrapper to sanitize a key-value array.
     *
     * @param array<string, mixed> $data The raw key-value dataset.
     * @return array<string, mixed> The sanitized key-value dataset.
     */
    public function sanitizeArray(array $data): array
    {
        return self::sanitize($data);
    }

    /**
     * Sanitizes raw log messages to mask sensitive signatures such as Bearer tokens, JWTs, and API keys.
     *
     * @param string $message The raw log message string.
     * @return string The sanitized log message.
     */
    public static function sanitizeMessage(string $message): string
    {
        // Mask bearer tokens.
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        // Mask API keys.
        $message = preg_replace('/op_[a-zA-Z0-9]{8}\.[a-zA-Z0-9]+/', 'op_[REDACTED]', $message) ?? $message;
        // Mask JWTs.
        $message = preg_replace('/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', '[JWT_REDACTED]', $message) ?? $message;

        return $message;
    }

    /**
     * Non-static wrapper to sanitize a string payload, applying patterns for email, phone, and payment card detection.
     *
     * Implements Luhn checksum validation to distinguish actual credit card numbers from general IDs.
     *
     * @param string $input The raw log text input.
     * @return string The sanitized log text.
     */
    public function sanitizeString(string $input): string
    {
        // Redact email addresses.
        $input = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_REDACTED]', $input) ?? $input;

        // Redact phone numbers.
        $input = preg_replace('/(?:\+?88)?01[3-9]\d{8}/', '[PHONE_REDACTED]', $input) ?? $input;

        // Redact Maestro/UnionPay 13-19 digit card patterns using Luhn checksum validation.
        $input = preg_replace_callback('/\b(?:\d[ -]*?){13,19}\b/', function (array $match): string {
            $digits = (string) preg_replace('/\D/', '', $match[0]);
            return self::passesLuhn($digits) ? '[CARD_REDACTED]' : $match[0];
        }, $input) ?? $input;

        // Redact Bangladesh NID identifiers under strict-mode checks (13 or 17 digit numeric sequences).
        if ($this->strict) {
            $input = preg_replace('/\b\d{17}\b/', '[NID_REDACTED]', $input) ?? $input;
            $input = preg_replace('/\b\d{13}\b/', '[NID_REDACTED]', $input) ?? $input;
        }

        return self::sanitizeMessage($input);
    }

    /**
     * Non-static wrapper to sanitize a JSON-formatted string.
     *
     * @param string $json The raw JSON string.
     * @return string The sanitized JSON string.
     */
    public function sanitizeJson(string $json): string
    {
        $data = json_decode($json, true);
        if (is_array($data)) {
            $stringKeyed = [];
            foreach ($data as $k => $v) {
                $stringKeyed[(string) $k] = $v;
            }
            return json_encode($this->sanitizeArray($stringKeyed)) ?: '{}';
        }
        return $json;
    }

    /**
     * Validates a numerical digit sequence against the Luhn algorithm (modulus 10).
     *
     * Used to prevent false positive redactions of non-card numeric identifiers.
     *
     * @param string $number The digit sequence to evaluate.
     * @return bool True if the sequence matches the Luhn check, otherwise false.
     */
    private static function passesLuhn(string $number): bool
    {
        $sum = 0;
        $len = strlen($number);
        $parity = $len % 2;
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $number[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        return $sum % 10 === 0;
    }

    /**
     * Helper to verify if a given key pattern contains indicators of sensitive keys.
     *
     * @param string $key The key name to verify.
     * @return bool True if a sensitive pattern is matched, otherwise false.
     */
    private static function containsSensitiveKey(string $key): bool
    {
        $patterns = ['_secret', '_key', '_token', '_hash'];
        foreach ($patterns as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
