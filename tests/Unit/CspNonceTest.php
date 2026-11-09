<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Security\CspNonce;
use PHPUnit\Framework\TestCase;

/**
 * Tests the CspNonce encapsulation service.
 */
final class CspNonceTest extends TestCase
{
    public function testNonceSetterAndGetter(): void
    {
        $nonce = new CspNonce();
        $this->assertSame('', $nonce->getNonce());
        $this->assertSame('', (string) $nonce);

        $val = base64_encode(random_bytes(16));
        $nonce->setNonce($val);

        $this->assertSame($val, $nonce->getNonce());
        $this->assertSame($val, (string) $nonce);
    }
}
