<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Log sanitizer — strips sensitive fields before logging.
 *
 * Per OWASP + security skill: never log passwords, tokens, keys, PII.
 */
final class LogSanitizer
{
    private bool $strict;

    /** Fields to completely redact */
    private const REDACT_KEYS = [
        'password', 'password_hash', 'password_confirm',
        'totp_secret', 'totp_secret_enc',
        'secret', 'key_hash', 'api_key', 'bearer_token',
        'jwt', 'token', 'refresh_token',
        'credentials_enc', 'webhook_secret',
        'credit_card', 'card_number', 'cvv', 'cvc',
        'ssn', 'social_security', 'authorization', 'signing_secret'
    ];

    /** Fields to mask (show partial or redact) */
    private const MASK_KEYS = [
        'email', 'phone', 'name', 'ip_address',
        'email_enc', 'phone_enc', 'name_enc',
    ];

    public function __construct(bool $strict = false)
    {
        $this->strict = $strict;
    }

    /**
     * Sanitize array for logging.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);

            // Full redaction
            if (in_array($lowerKey, self::REDACT_KEYS, true) || self::containsSensitiveKey($lowerKey)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            // Partial mask
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

            // Recurse into nested arrays
            if (is_array($value)) {
                $result[$key] = self::sanitize($value);
                continue;
            }

            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Non-static wrapper for array sanitization.
     */
    public function sanitizeArray(array $data): array
    {
        return self::sanitize($data);
    }

    /**
     * Sanitize a string message (strip inline secrets).
     */
    public static function sanitizeMessage(string $message): string
    {
        // Mask bearer tokens
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        // Mask API keys
        $message = preg_replace('/op_[a-zA-Z0-9]{8}\.[a-zA-Z0-9]+/', 'op_[REDACTED]', $message) ?? $message;
        // Mask JWTs
        $message = preg_replace('/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', '[JWT_REDACTED]', $message) ?? $message;

        return $message;
    }

    /**
     * Non-static wrapper for string sanitization.
     */
    public function sanitizeString(string $input): string
    {
        // Email redaction
        $input = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_REDACTED]', $input) ?? $input;

        // Phone redaction
        $input = preg_replace('/(?:\+?88)?01[3-9]\d{8}/', '[PHONE_REDACTED]', $input) ?? $input;

        // Maestro/UnionPay 13-19 digit card redaction (with space/hyphen separators)
        $input = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[CARD_REDACTED]', $input) ?? $input;

        // Bangladesh NID strict-mode checks (13 or 17 digit numeric sequences)
        if ($this->strict) {
            $input = preg_replace('/\b\d{17}\b/', '[NID_REDACTED]', $input) ?? $input;
            $input = preg_replace('/\b\d{13}\b/', '[NID_REDACTED]', $input) ?? $input;
        }

        return self::sanitizeMessage($input);
    }

    /**
     * Non-static wrapper for JSON sanitization.
     */
    public function sanitizeJson(string $json): string
    {
        $data = json_decode($json, true);
        if (is_array($data)) {
            return json_encode($this->sanitizeArray($data)) ?: '{}';
        }
        return $json;
    }

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
