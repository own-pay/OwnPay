<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Gateway\GatewayDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the exact (float-free) money conversion helpers used by gateway
 * adapters. The previous `(int) bcmul((string)(float)$amount, '100', 0)`
 * pattern corrupted large amounts and accepted scientific notation,
 * negatives, and arrays.
 */
final class GatewayDefaultsAmountTest extends TestCase
{
    /** @var object Anonymous trait host exposing the protected helpers. */
    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new class {
            use GatewayDefaults;

            public function minor(mixed $amount, int $exponent = 2): int
            {
                return $this->toMinorUnits($amount, $exponent);
            }

            public function decimal(mixed $amount, int $decimals = 2): string
            {
                return $this->toDecimalString($amount, $decimals);
            }
        };
    }

    public function testConvertsTypicalAmounts(): void
    {
        $this->assertSame(1005, $this->gateway->minor('10.05'));
        $this->assertSame(100, $this->gateway->minor('1'));
        $this->assertSame(0, $this->gateway->minor('0.00'));
        $this->assertSame(99999999, $this->gateway->minor('999999.99'));
    }

    public function testConvertsLargeAmountsExactly(): void
    {
        // DECIMAL(15,2) maximum — the float path returns a corrupted value here.
        $this->assertSame(999999999999999, $this->gateway->minor('9999999999999.99'));
        $this->assertSame(123456789012345, $this->gateway->minor('1234567890123.45'));
    }

    public function testSupportsAlternateExponents(): void
    {
        $this->assertSame(10, $this->gateway->minor('10.00', 0));
        $this->assertSame(10000, $this->gateway->minor('10', 3));
    }

    public function testTruncatesBeyondExponentLikeLegacyBehavior(): void
    {
        // bcmul at scale 0 truncates — identical to the previous behavior for
        // sub-cent precision, which cannot occur for DECIMAL(15,2) amounts.
        $this->assertSame(1055, $this->gateway->minor('10.555'));
    }

    public function testRejectsInvalidAmounts(): void
    {
        foreach (['1e3', '-5', '10,00', 'abc', '', ' 10', '0x1A', '1.2.3', null, [], true, '99999999999999.00'] as $bad) {
            try {
                $this->gateway->minor($bad);
                $this->fail('Expected InvalidArgumentException for: ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDecimalStringNormalization(): void
    {
        $this->assertSame('10.00', $this->gateway->decimal('10'));
        $this->assertSame('10.05', $this->gateway->decimal('10.05'));
        $this->assertSame('10.55', $this->gateway->decimal('10.556'));
        $this->assertSame('9999999999999.99', $this->gateway->decimal('9999999999999.99'));
    }

    public function testDecimalStringRejectsInvalidAmounts(): void
    {
        foreach (['1e3', '-5', 'abc', ''] as $bad) {
            try {
                $this->gateway->decimal($bad);
                $this->fail('Expected InvalidArgumentException for: ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
