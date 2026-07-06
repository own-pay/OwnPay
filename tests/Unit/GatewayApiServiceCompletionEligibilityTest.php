<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Service\Payment\GatewayApiService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for GatewayApiService::handleCallback()'s completion-eligibility check.
 *
 * Part of the checkout back-navigation fix: once a customer can return to checkout and pick a
 * DIFFERENT gateway while their first attempt is still `processing`, a late/stale webhook from
 * the ABANDONED first gateway must not be allowed to complete the transaction under the wrong
 * gateway's identity. `pending` transactions are untouched (pre-existing behavior, unrelated to
 * this bug) - the guard only applies once a real gateway attempt (`processing`/
 * `callback_processing`) has been recorded.
 */
final class GatewayApiServiceCompletionEligibilityTest extends TestCase
{
    private GatewayApiService $service;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(GatewayApiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    private function isEligible(array $transaction, string $gatewaySlug): bool
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('isCompletionEligible');
        $method->setAccessible(true);
        return $method->invoke($this->service, $transaction, $gatewaySlug);
    }

    public function testProcessingTransactionEligibleWhenGatewayMatches(): void
    {
        $this->assertTrue($this->isEligible(['status' => 'processing', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
    }

    public function testProcessingTransactionNotEligibleWhenGatewayMismatched(): void
    {
        $this->assertFalse($this->isEligible(['status' => 'processing', 'gateway_slug' => 'bkash-api'], 'nagad-api'));
    }

    public function testCallbackProcessingTransactionNotEligibleWhenGatewayMismatched(): void
    {
        $this->assertFalse($this->isEligible(['status' => 'callback_processing', 'gateway_slug' => 'bkash-api'], 'nagad-api'));
    }

    public function testPendingTransactionEligibleRegardlessOfGatewaySlug(): void
    {
        // Pre-existing behavior, unrelated to this fix - must not change.
        $this->assertTrue($this->isEligible(['status' => 'pending', 'gateway_slug' => 'bkash-api'], 'nagad-api'));
    }

    public function testTerminalStatusNeverEligible(): void
    {
        $this->assertFalse($this->isEligible(['status' => 'completed', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
        $this->assertFalse($this->isEligible(['status' => 'failed', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
        $this->assertFalse($this->isEligible(['status' => 'cancelled', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
    }
}
