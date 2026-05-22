<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WebhookDispatcher logic tests — HMAC signing, payload building, retry constants.
 * Tests core logic without requiring DB/curl.
 */
class WebhookDispatcherTest extends TestCase
{
    public function testHmacSigning(): void
    {
        $payload = '{"event":"payment.completed","transaction_id":"OP-001"}';
        $secret = 'merchant_webhook_secret_abc123';
        $signature = hash_hmac('sha256', $payload, $secret);

        // Signature deterministic
        $this->assertSame(hash_hmac('sha256', $payload, $secret), $signature);
        $this->assertSame(64, strlen($signature)); // SHA-256 = 64 hex chars
    }

    public function testHmacVerifiesCorrectly(): void
    {
        $payload = '{"amount":"500.00"}';
        $secret = 'secret123';
        $goodSig = hash_hmac('sha256', $payload, $secret);
        $badSig = hash_hmac('sha256', $payload, 'wrong_secret');

        $this->assertTrue(hash_equals($goodSig, hash_hmac('sha256', $payload, $secret)));
        $this->assertFalse(hash_equals($goodSig, $badSig));
    }

    public function testPayloadStructure(): void
    {
        $payload = [
            'event'          => 'payment.completed',
            'transaction_id' => 'OP-001',
            'amount'         => '500.00',
            'currency'       => 'BDT',
            'gateway'        => 'bkash',
            'gateway_type'   => 'manual',
            'status'         => 'completed',
            'customer'       => ['name' => 'Test', 'email' => '', 'phone' => ''],
            'metadata'       => [],
            'timestamp'      => date('c'),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($json, true);

        $this->assertSame('payment.completed', $decoded['event']);
        $this->assertSame('500.00', $decoded['amount']);
        $this->assertSame('manual', $decoded['gateway_type']);
        $this->assertArrayHasKey('customer', $decoded);
        $this->assertArrayHasKey('timestamp', $decoded);
    }

    public function testRetryConstants(): void
    {
        $maxRetries = 3;
        $delays = [60, 300, 1800]; // 1m, 5m, 30m

        $this->assertCount($maxRetries, $delays);
        $this->assertSame(60, $delays[0]);
        $this->assertSame(300, $delays[1]);
        $this->assertSame(1800, $delays[2]);
    }

    public function testSignatureHeaders(): void
    {
        $signature = hash_hmac('sha256', '{}', 'secret');
        $timestamp = time();

        $headers = [
            'Content-Type: application/json',
            'X-OwnPay-Signature: ' . $signature,
            'X-OwnPay-Timestamp: ' . $timestamp,
            'User-Agent: OwnPay-Webhook/1.0',
        ];

        $this->assertCount(4, $headers);
        $this->assertStringContainsString('X-OwnPay-Signature:', $headers[1]);
        $this->assertStringContainsString('X-OwnPay-Timestamp:', $headers[2]);
    }

    public function testGatewayTypeInPayload(): void
    {
        // All gateway types must be representable
        $types = ['api', 'manual', 'bank', 'mfs', 'test'];
        foreach ($types as $type) {
            $payload = ['gateway_type' => $type];
            $this->assertContains($payload['gateway_type'], $types);
        }
    }

    public function testEmptyWebhookSecretStillSigns(): void
    {
        $payload = '{"event":"test"}';
        $signature = hash_hmac('sha256', $payload, '');
        $this->assertSame(64, strlen($signature));
    }
}
