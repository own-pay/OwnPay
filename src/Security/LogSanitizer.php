<?php

declare(strict_types=1);

namespace OwnPay\Security;

/**
 * LogSanitizer — scrubs PII from log entries and webhook payloads.
 *
 * Detects PII via regex patterns and replaces with [REDACTED].
 * Applied to audit logs, webhook payloads, and error messages.
 */
final class LogSanitizer
{
    /**
     * PII detection patterns (regex => label).
     */
    private const PATTERNS = [
        // Email addresses
        '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Z|a-z]{2,}\b/' => '[EMAIL_REDACTED]',

        // Bangladesh phone numbers (+880...)
        '/(?:\+?880|0)[\s\-]?\d[\s\-]?\d{4}[\s\-]?\d{4}\b/' => '[PHONE_REDACTED]',

        // International phone numbers (generic)
        '/\+\d{1,3}[\s\-]?\d{4,14}\b/' => '[PHONE_REDACTED]',

        // Credit/debit card numbers (13-19 digits)
        '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{1,7}\b/' => '[CARD_REDACTED]',

        // National ID (10-17 digit sequences that look like NID)
        '/\b\d{10,17}\b/' => null, // Only redact in strict mode
    ];

    /**
     * Fields to always redact in structured data.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'secret',
        'token',
        'key_hash',
        'signing_secret',
        'api_key',
        'bearer',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
        'pin',
        'ssn',
        'nid',
        'passport_number',
    ];

    private bool $strictMode;

    /**
     * @param bool $strictMode When true, more aggressive pattern matching
     */
    public function __construct(bool $strictMode = false)
    {
        $this->strictMode = $strictMode;
    }

    /**
     * Sanitize a string by replacing detected PII.
     */
    public function sanitizeString(string $input): string
    {
        $output = $input;

        foreach (self::PATTERNS as $pattern => $replacement) {
            if ($replacement === null && !$this->strictMode) {
                continue; // Skip pattern in non-strict mode
            }

            $output = preg_replace($pattern, $replacement ?? '[REDACTED]', $output);
        }

        return $output;
    }

    /**
     * Sanitize structured data (arrays/objects).
     * Recursively processes nested structures.
     */
    public function sanitize(mixed $data): mixed
    {
        if (is_string($data)) {
            return $this->sanitizeString($data);
        }

        if (is_array($data)) {
            return $this->sanitizeArray($data);
        }

        return $data;
    }

    /**
     * Sanitize an associative array.
     * Sensitive field names are fully redacted regardless of content.
     */
    public function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            // Fully redact known sensitive fields
            if (is_string($key) && $this->isSensitiveField($key)) {
                $data[$key] = '[REDACTED]';
                continue;
            }

            // Recurse into nested arrays
            if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
                continue;
            }

            // Sanitize string values
            if (is_string($value)) {
                $data[$key] = $this->sanitizeString($value);
            }
        }

        return $data;
    }

    /**
     * Sanitize a JSON string (parse, sanitize, re-encode).
     */
    public function sanitizeJson(string $json): string
    {
        $data = json_decode($json, true);
        if ($data === null) {
            return $this->sanitizeString($json);
        }

        $sanitized = $this->sanitizeArray($data);
        return json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Check if a field name is considered sensitive.
     */
    private function isSensitiveField(string $fieldName): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $fieldName));

        foreach (self::SENSITIVE_FIELDS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
