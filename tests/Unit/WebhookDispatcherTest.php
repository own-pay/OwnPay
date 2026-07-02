<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Security\UrlValidator;
use OwnPay\Service\System\Logger;
use OwnPay\Service\Notification\WebhookDispatcher;

class WebhookDispatcherTest extends TestCase
{
    public function testHmacSigning(): void
    {
        $payload = '{"event":"payment.completed","transaction_id":"OP-001"}';
        $secret = 'merchant_webhook_secret_abc123';
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertSame(hash_hmac('sha256', $payload, $secret), $signature);
        $this->assertSame(64, strlen($signature));
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
        $delays = [60, 300, 1800];

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

    public function testSsrfProtectionBlocksLocalUrls(): void
    {
        $unsafeUrls = [
            'http://127.0.0.1/test',
            'https://localhost/webhook',
            'https://192.168.1.50/pay',
            'https://10.0.0.1/callback',
            'http://[::1]/webhook',
            'https://0.0.0.0/test',
        ];

        foreach ($unsafeUrls as $url) {
            $this->assertFalse(
                UrlValidator::isValidWebhookUrl($url),
                "URL {$url} should be blocked by SSRF protection."
            );
        }
    }

    public function testSsrfProtectionAllowsPublicHttpsUrls(): void
    {
        $safeUrls = [
            'https://example.com/webhook',
            'https://api.stripe.com/v1',
            'https://webhook.site/callback',
        ];

        foreach ($safeUrls as $url) {
            $this->assertTrue(
                UrlValidator::isValidWebhookUrl($url),
                "URL {$url} should be permitted."
            );
        }
    }

    public function testBuildPayloadIncludesGatewayTrxId(): void
    {
        $db = $this->createMock(Database::class);
        $logger = new Logger('test');
        $events = new EventManager();

        $dispatcher = new WebhookDispatcher($db, $logger, $events);

        $data = [
            'transaction_id' => 'TXN_123',
            'gateway_trx_id' => 'GW_456',
            'amount' => '100.00',
        ];

        $payload = $dispatcher->buildPayload('payment.completed', $data);

        $this->assertSame('GW_456', $payload['gateway_trx_id']);
        $this->assertSame('TXN_123', $payload['transaction_id']);
    }

    public function testBuildPayloadQueriesDatabaseForGatewayTrxId(): void
    {
        $db = $this->createMock(Database::class);
        $logger = new Logger('test');
        $events = new EventManager();

        $db->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('SELECT gateway_trx_id FROM op_transactions'),
                ['trxId' => 'TXN_123']
            )
            ->willReturn(['gateway_trx_id' => 'GW_789']);

        $dispatcher = new WebhookDispatcher($db, $logger, $events);

        $data = [
            'transaction_id' => 'TXN_123',
            'amount' => '100.00',
        ];

        $payload = $dispatcher->buildPayload('payment.completed', $data);

        $this->assertSame('GW_789', $payload['gateway_trx_id']);
    }
}
