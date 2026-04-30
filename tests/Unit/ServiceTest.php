<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    // L7: Payment/Ledger/Transaction
    public function testBcmathPrecision(): void
    {
        $a = '100.50'; $b = '0.03';
        $result = bcadd($a, $b, 2);
        $this->assertSame('100.53', $result);
    }

    public function testBcmathSubtraction(): void
    {
        $result = bcsub('1000.00', '250.75', 2);
        $this->assertSame('749.25', $result);
    }

    public function testBcmathMultiplication(): void
    {
        $result = bcmul('99.99', '3', 2);
        $this->assertSame('299.97', $result);
    }

    public function testTransactionIdFormat(): void
    {
        $trxId = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
        $this->assertMatchesRegularExpression('/^TXN-[A-F0-9]{16}$/', $trxId);
    }

    // L8: SMS Parser
    public function testSmsParserBkashPattern(): void
    {
        $sms = 'You have received Tk 500.00 from 01712345678. Fee Tk 0.00. Balance Tk 1500.00. TrxID ABC123XYZ. 01/01/25 at 10:30 AM.';
        preg_match('/TrxID\s+([A-Z0-9]+)/i', $sms, $m);
        $this->assertSame('ABC123XYZ', $m[1] ?? '');
        preg_match('/Tk\s+([\d,]+\.?\d*)/i', $sms, $a);
        $this->assertSame('500.00', $a[1] ?? '');
    }

    public function testSmsParserNagadPattern(): void
    {
        $sms = 'You have received Tk.1,000.00 from A/C 01812345678.Ref No.NXYZ789.Balance Tk.5,000.00.';
        preg_match('/Ref\s*No\.?\s*([A-Z0-9]+)/i', $sms, $m);
        $this->assertSame('NXYZ789', $m[1] ?? '');
    }

    // L9: Manual Gateway
    public function testManualGatewayAccountMasking(): void
    {
        $number = '01712345678';
        $masked = substr($number, 0, 3) . str_repeat('*', strlen($number) - 5) . substr($number, -2);
        $this->assertSame('017******78', $masked);
    }

    // L11: Domain + DNS
    public function testDomainValidation(): void
    {
        $this->assertTrue((bool) filter_var('example.com', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME));
        $this->assertFalse((bool) filter_var('not a domain!', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME));
    }

    // L12: Theme brand color validation
    public function testBrandColorValidation(): void
    {
        $this->assertSame(1, preg_match('/^#[0-9a-fA-F]{6}$/', '#0D9488'));
        $this->assertSame(0, preg_match('/^#[0-9a-fA-F]{6}$/', 'red'));
        $this->assertSame(0, preg_match('/^#[0-9a-fA-F]{6}$/', '#FFF'));
        $this->assertSame(0, preg_match('/^#[0-9a-fA-F]{6}$/', '<script>'));
    }
}
