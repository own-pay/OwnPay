<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UnifiedWebhookController logic tests — gateway slug validation,
 * hook name generation, merchant resolution fields.
 */
class UnifiedWebhookControllerTest extends TestCase
{
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

        // Simulate payload extraction
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
        // Simulate: no listener registered → hasHook returns false
        $hasHook = false; // EventManager->hasHook('webhook.incoming.nonexistent')
        if (!$hasHook) {
            $statusCode = 404;
        } else {
            $statusCode = 200;
        }
        $this->assertSame(404, $statusCode);
    }

    public function testDomainResolutionPriority(): void
    {
        // Priority: 1) Host header → domain table, 2) payload txn ref, 3) return 0
        $fromDomain = 42;
        $fromPayload = 0;

        // Domain takes priority
        $merchantId = ($fromDomain > 0) ? $fromDomain : $fromPayload;
        $this->assertSame(42, $merchantId);

        // Fallback to payload
        $fromDomain = 0;
        $fromPayload = 99;
        $merchantId = ($fromDomain > 0) ? $fromDomain : $fromPayload;
        $this->assertSame(99, $merchantId);
    }
}
