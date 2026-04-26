<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

/**
 * RequestSignatureMiddleware — HMAC request signing for high-value operations.
 *
 * Validates that the request body is signed with a per-key signing secret.
 * This provides an additional layer of authentication beyond Bearer tokens,
 * preventing stolen tokens from being used without the signing secret.
 *
 * Header format:
 *   X-OP-Signature: sha256=HMAC(timestamp.body, signing_secret)
 *   X-OP-Timestamp: Unix epoch seconds
 *
 * Signing secret is stored alongside the API key and returned once at creation.
 */
final class RequestSignatureMiddleware
{
    private const MAX_TIMESTAMP_SKEW = 300; // 5 minutes

    /**
     * Verify the request signature.
     *
     * @param string $rawBody       Raw request body
     * @param string $signingSecret The key's signing secret
     * @return array{valid: bool, error: string}
     */
    public function verify(string $rawBody, string $signingSecret): array
    {
        // Extract headers
        $signature = $this->getHeader('X-OP-Signature');
        $timestamp = $this->getHeader('X-OP-Timestamp');

        // Both headers required
        if (empty($signature) || empty($timestamp)) {
            return [
                'valid' => false,
                'error' => 'Missing X-OP-Signature and/or X-OP-Timestamp headers.',
            ];
        }

        // Validate timestamp freshness
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_SKEW) {
            return [
                'valid' => false,
                'error' => 'Request timestamp expired or invalid (±5 minute window).',
            ];
        }

        // Parse signature format: sha256=<hex>
        if (!str_starts_with($signature, 'sha256=')) {
            return [
                'valid' => false,
                'error' => 'Invalid signature format. Expected: sha256=<hex>',
            ];
        }

        $providedHash = substr($signature, 7);

        // Compute expected signature
        $payload = "{$timestamp}.{$rawBody}";
        $expectedHash = hash_hmac('sha256', $payload, $signingSecret);

        // Timing-safe comparison
        if (!hash_equals($expectedHash, $providedHash)) {
            return [
                'valid' => false,
                'error' => 'Request signature verification failed.',
            ];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Enforce request signature — sends 401 and exits if invalid.
     *
     * @param string $rawBody       Raw request body
     * @param string $signingSecret The key's signing secret
     */
    public function enforce(string $rawBody, string $signingSecret): void
    {
        $result = $this->verify($rawBody, $signingSecret);

        if (!$result['valid']) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'code' => 'INVALID_SIGNATURE',
                    'message' => $result['error'],
                ],
            ]);
            exit;
        }
    }

    /**
     * Check if the current request has signature headers present.
     * Used to determine if signature verification should be applied.
     */
    public function hasSignatureHeaders(): bool
    {
        return !empty($this->getHeader('X-OP-Signature'));
    }

    /**
     * Get a header value from $_SERVER.
     */
    private function getHeader(string $name): string
    {
        // Convert Header-Name to HTTP_HEADER_NAME
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$serverKey] ?? '';
    }
}
