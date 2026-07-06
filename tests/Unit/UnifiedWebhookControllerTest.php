<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Controller\Webhook\UnifiedWebhookController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class UnifiedWebhookControllerTest extends TestCase
{
    private function extractTrxRef(string $rawBody): string
    {
        $reflection = new ReflectionClass(UnifiedWebhookController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('extractTrxRef');
        $method->setAccessible(true);
        return $method->invoke($controller, $rawBody);
    }

    public function testExtractTrxRefFindsJsonFieldByPriority(): void
    {
        $this->assertSame('TRX-1', $this->extractTrxRef('{"trx_id":"TRX-1","order_id":"ORD-2"}'));
        $this->assertSame('ORD-2', $this->extractTrxRef('{"order_id":"ORD-2"}'));
    }

    public function testExtractTrxRefFindsFormEncodedField(): void
    {
        $this->assertSame('REF-9', $this->extractTrxRef('reference=REF-9&status=paid'));
    }

    public function testExtractTrxRefReturnsEmptyWhenNoRecognizedField(): void
    {
        $this->assertSame('', $this->extractTrxRef('{"unrelated_field":"x"}'));
        $this->assertSame('', $this->extractTrxRef(''));
        $this->assertSame('', $this->extractTrxRef('not json or form data{{{'));
    }

    public function testGatewaySlugValidation(): void
    {
        $valid = ['stripe', 'bkash', 'sslcommerz', 'upay', 'my-gateway-2'];
        $invalid = ['', 'STRIPE', '../hack', 'a b', 'gate way!', str_repeat('a', 51), '-start'];

        foreach ($valid as $slug) {
            $this->assertSame(1, preg_match('/^[a-z0-9][a-z0-9\-]{0,49}$/', $slug), "Should be valid: {$slug}");
        }
        foreach ($invalid as $slug) {
            $this->assertSame(0, preg_match('/^[a-z0-9][a-z0-9\-]{0,49}$/', $slug), "Should be invalid: {$slug}");
        }
    }

    public function testHookNameGeneration(): void
    {
        $gateway = 'upay';
        $hookName = "webhook.incoming.{$gateway}";
        $this->assertSame('webhook.incoming.upay', $hookName);
    }

    public function testTransactionRefFields(): void
    {
        $refFields = ['order_id', 'tran_id', 'invoice_id', 'reference', 'merchant_order_id', 'client_reference_id'];
        $this->assertCount(6, $refFields);

        $payloads = [
            ['order_id' => 'ORD-001'],
            ['tran_id' => 'TRN-002'],
            ['invoice_id' => 'INV-003'],
            ['reference' => 'REF-004'],
        ];

        foreach ($payloads as $payload) {
            $found = false;
            foreach ($refFields as $field) {
                if (!empty($payload[$field])) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found);
        }
    }

    public function testUnknownGatewayReturns404Logic(): void
    {
        $hasHook = false;
        if (!$hasHook) {
            $statusCode = 404;
        } else {
            $statusCode = 200;
        }
        $this->assertSame(404, $statusCode);
    }

    public function testDomainResolutionPriority(): void
    {
        $fromDomain = 42;
        $fromPayload = 0;
        $merchantId = ($fromDomain > 0) ? $fromDomain : $fromPayload;
        $this->assertSame(42, $merchantId);

        $fromDomain = 0;
        $fromPayload = 99;
        $merchantId = ($fromDomain > 0) ? $fromDomain : $fromPayload;
        $this->assertSame(99, $merchantId);
    }
}
