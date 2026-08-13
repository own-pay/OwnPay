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
 * gateway's identity.
 *
 * Issue #345 (PAY-17): the `pending` eligibility rule was tightened - a `pending` row with a
 * NON-empty `gateway_slug` is no longer auto-eligible for any gateway's callback. Only truly
 * unclaimed `pending` rows (NULL or empty `gateway_slug`) accept any callback. A non-empty
 * slug on a `pending` row indicates the row was reverted from `processing` without clearing
 * the slug, and a stale callback from the abandoned gateway must not hijack the completion.
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

    public function testPendingTransactionWithEmptySlugEligibleForAnyGateway(): void
    {
        // Truly unclaimed: no gateway has ever been recorded against this row, so any
        // gateway's webhook may complete it. This is the original pre-PAY-17 behavior
        // for genuinely new pending rows, preserved as a sub-case of the new rule.
        $this->assertTrue($this->isEligible(['status' => 'pending', 'gateway_slug' => ''], 'nagad-api'));
        $this->assertTrue($this->isEligible(['status' => 'pending', 'gateway_slug' => null], 'bkash-api'));
        $this->assertTrue($this->isEligible(['status' => 'pending'], 'bkash-api'));
    }

    public function testPendingTransactionWithNonEmptySlugNotEligibleWhenGatewayMismatched(): void
    {
        // PAY-17: a pending row that still carries a non-empty gateway_slug must NOT
        // be eligible for a webhook from a different gateway. Such a row was likely
        // reverted from processing without clearing the slug; accepting a stale
        // callback from the abandoned gateway would hijack the completion.
        $this->assertFalse($this->isEligible(['status' => 'pending', 'gateway_slug' => 'bkash-api'], 'nagad-api'));
    }

    public function testPendingTransactionWithNonEmptySlugEligibleWhenGatewayMatches(): void
    {
        // The original gateway can still complete its own reverted transaction.
        $this->assertTrue($this->isEligible(['status' => 'pending', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
    }

    public function testTerminalStatusNeverEligible(): void
    {
        $this->assertFalse($this->isEligible(['status' => 'completed', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
        $this->assertFalse($this->isEligible(['status' => 'failed', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
        $this->assertFalse($this->isEligible(['status' => 'cancelled', 'gateway_slug' => 'bkash-api'], 'bkash-api'));
    }
}
