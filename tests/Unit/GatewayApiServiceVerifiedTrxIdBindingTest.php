<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Service\Payment\GatewayApiService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for the transaction-reference binding.
 *
 * A browser return must be rejected when the provider-authenticated reference
 * does not match the loaded transaction's expected reference.
 *
 * A webhook carries no expected reference, so the reference the adapter authenticated
 * itself is the only one allowed to settle - a callback body may name any transaction.
 */
final class GatewayApiServiceVerifiedTrxIdBindingTest extends TestCase
{
    private GatewayApiService $service;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(GatewayApiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $verification
     * @param array<string, mixed> $callbackData
     */
    private function resolve(array $verification, array $callbackData, ?string $expectedTrxId): ?string
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('resolveTransactionReference');
        $method->setAccessible(true);
        /** @var string|null $result */
        $result = $method->invoke($this->service, $verification, $callbackData, $expectedTrxId);
        return $result;
    }

    public function testMatchingVerifiedTrxIdResolvesToExpected(): void
    {
        $result = $this->resolve(
            ['success' => true, 'verified_trx_id' => 'trx_abc'],
            [],
            'trx_abc'
        );
        $this->assertSame('trx_abc', $result);
    }

    public function testMismatchedVerifiedTrxIdReturnsNull(): void
    {
        $result = $this->resolve(
            ['success' => true, 'verified_trx_id' => 'trx_other'],
            [],
            'trx_abc'
        );
        $this->assertNull($result);
    }

    public function testMissingVerifiedTrxIdReturnsNull(): void
    {
        $result = $this->resolve(
            ['success' => true],
            [],
            'trx_abc'
        );
        $this->assertNull($result);
    }

    public function testEmptyExpectedTrxIdReturnsNull(): void
    {
        $result = $this->resolve(
            ['success' => true, 'verified_trx_id' => 'trx_abc'],
            [],
            ''
        );
        $this->assertNull($result);
    }

    public function testBrowserReturnDoesNotFallBackToCallbackSuppliedTrxId(): void
    {
        $result = $this->resolve(
            ['success' => true, 'trx_id' => 'trx_abc', 'gateway_trx_id' => 'gw_123'],
            ['trx_id' => 'trx_abc', 'tran_id' => 'trx_abc', 'order_id' => 'trx_abc'],
            'trx_abc'
        );
        $this->assertNull($result);
    }

    public function testBrowserReturnDoesNotFallBackToGatewayTrxId(): void
    {
        $result = $this->resolve(
            ['success' => true, 'gateway_trx_id' => 'trx_abc'],
            [],
            'trx_abc'
        );
        $this->assertNull($result);
    }

    public function testWebhookSettlesTheProviderAuthenticatedReference(): void
    {
        $result = $this->resolve(
            ['success' => true, 'verified_trx_id' => 'trx_paid'],
            ['trx_id' => 'trx_other', 'reference' => 'trx_other'],
            null
        );
        $this->assertSame('trx_paid', $result);
    }

    public function testWebhookWithoutAProviderAuthenticatedReferenceUsesTheCallbackBody(): void
    {
        $result = $this->resolve(
            ['success' => true, 'trx_id' => 'trx_paid'],
            ['trx_id' => 'trx_paid'],
            null
        );
        $this->assertSame('trx_paid', $result);
    }

    public function testWebhookWithAnEmptyVerifiedReferenceUsesTheCallbackBody(): void
    {
        $result = $this->resolve(
            ['success' => true, 'verified_trx_id' => ''],
            ['reference' => 'trx_from_callback'],
            null
        );
        $this->assertSame('trx_from_callback', $result);
    }
}
