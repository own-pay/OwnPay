<?php
declare(strict_types=1);

namespace OwnPay\Model;

/**
 * Class WebhookPayload
 *
 * An immutable data container representing an incoming payment gateway webhook notification payload.
 * Encapsulates the raw payload body to support HMAC signature verification workflows, maps target merchant scoping
 * parameters, and provides parsed helper utilities for parsing JSON and form-urlencoded HTTP POST requests.
 *
 * @package OwnPay\Model
 */
final readonly class WebhookPayload
{
    /**
     * WebhookPayload constructor.
     *
     * @param string $gateway The unique slug identifying the payment gateway adapter.
     * @param int $merchantId The merchant brand scoping ID associated with this callback.
     * @param string $rawBody The raw, unparsed HTTP request request body.
     * @param array<string, string|string[]> $headers The HTTP headers array matching the request.
     * @param string $ip The source IP address that initiated the callback request.
     * @param string $method The HTTP request method utilized (e.g. POST, GET).
     */
    public function __construct(
        public string $gateway,
        public int    $merchantId,
        public string $rawBody,
        public array  $headers,
        public string $ip,
        public string $method = 'POST',
    ) {}

    /**
     * Parse the raw request body as JSON.
     *
     * Returns an empty array if decoding fails or target content is invalid.
     *
     * @return array<string, mixed> The decoded JSON object tree as an associative array.
     */
    public function json(): array
    {
        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Retrieve a specific HTTP header value by key, performing a case-insensitive match.
     *
     * @param string $key The key name of the target HTTP header.
     * @return string|null The header value string, or null if the key is not set.
     */
    public function header(string $key): ?string
    {
        $key = strtolower($key);
        foreach ($this->headers as $name => $value) {
            if (strtolower($name) === $key) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }
        return null;
    }

    /**
     * Parse the raw request body as form-urlencoded data.
     *
     * @return array<int|string, mixed> The parsed query string variables as an associative array.
     */
    public function formData(): array
    {
        parse_str($this->rawBody, $data);
        /** @var array<int|string, array<mixed>|string> $data */
        return $data;
    }

    /**
     * Generate a cryptographic SHA-256 hash of the raw body payload.
     *
     * Frequently utilized for request deduplication and message idempotency verification checks.
     *
     * @return string The hex-encoded SHA-256 hash representation of the raw body.
     */
    public function bodyHash(): string
    {
        return hash('sha256', $this->rawBody);
    }
}

