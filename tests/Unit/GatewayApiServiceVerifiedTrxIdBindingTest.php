<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Service\Payment\GatewayApiService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for the browser-return transaction-reference binding.
 *
 * A browser return must be rejected when the provider-authenticated reference
 * does not match the loaded transaction's expected reference.
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
}
