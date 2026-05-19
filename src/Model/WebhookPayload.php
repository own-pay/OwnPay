<?php
declare(strict_types=1);

namespace OwnPay\Model;

/**
 * Immutable webhook payload — passed to gateway plugin listeners.
 * Contains raw body for HMAC verification, parsed helpers for convenience.
 */
final readonly class WebhookPayload
{
    public function __construct(
        public string $gateway,
        public int    $merchantId,
        public string $rawBody,
        public array  $headers,
        public string $ip,
        public string $method = 'POST',
    ) {}

    /**
     * Parse body as JSON. Returns empty array on failure.
     */
    public function json(): array
    {
        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get single header (case-insensitive).
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
     * Parse body as form-urlencoded data.
     */
    public function formData(): array
    {
        parse_str($this->rawBody, $data);
        return $data;
    }

    /**
     * SHA-256 hash of raw body — for idempotency / dedup.
     */
    public function bodyHash(): string
    {
        return hash('sha256', $this->rawBody);
    }
}
