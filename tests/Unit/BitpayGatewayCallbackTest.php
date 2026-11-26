<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 2) . '/modules/gateways/bitpay/BitpayGateway.php';

use OwnPay\Modules\Gateways\Bitpay\BitpayGateway;

/**
 * Regression coverage for the BitPay callback path.
 *
 * The invoice id in a BitPay callback is caller-supplied and is used to build the invoice
 * lookup URL, which also carries the merchant's API token. A value that can terminate the
 * path segment or append a query/fragment would let the caller replace the merchant's token
 * with one of their own and settle a transaction against an invoice on another account.
 */
final class BitpayGatewayCallbackTest extends TestCase
{
    private const INVOICE_ID = 'G3viJEJgE8Jk2oekSdgT2A';

    private BitpayGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new BitpayGateway();
    }

    private function callPrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(BitpayGateway::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->gateway, ...$arguments);
    }

    public static function provideInjectionPayloads(): array
    {
        return [
            'query terminator'      => ['abc?token=attacker'],
            'fragment terminator'   => ['abc#'],
            'query and fragment'    => ['abc?token=attacker#'],
            'path traversal'        => ['../../invoices/other'],
            'ampersand injection'   => ['abc&token=attacker'],
            'space and quote'       => ['abc" token=attacker'],
            'percent encoded query' => ['abc%3Ftoken%3Dattacker'],
            'trailing newline'      => ["abc\n"],
        ];
    }

    #[DataProvider('provideInjectionPayloads')]
    public function testInvoiceLookupUrlAlwaysCarriesTheMerchantToken(string $invoiceId): void
    {
        $url = $this->callPrivate('buildInvoiceUrl', ['https://bitpay.com', $invoiceId, 'merchant-token']);
        $this->assertIsString($url);

        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query);
        $this->assertSame('token=merchant-token', $query);
    }

    #[DataProvider('provideInjectionPayloads')]
    public function testInjectedInvoiceIdIsRejectedBeforeAnyLookup(string $invoiceId): void
    {
        $this->assertFalse($this->callPrivate('isValidInvoiceId', [$invoiceId]));
    }

    public function testPlainInvoiceIdIsAccepted(): void
    {
        $this->assertTrue($this->callPrivate('isValidInvoiceId', [self::INVOICE_ID]));
        $this->assertTrue($this->callPrivate('isValidInvoiceId', ['abc_def-123']));
        $this->assertTrue($this->callPrivate('isValidInvoiceId', [str_repeat('a', 64)]));
        $this->assertFalse($this->callPrivate('isValidInvoiceId', [str_repeat('a', 65)]));
        $this->assertFalse($this->callPrivate('isValidInvoiceId', ['']));
    }

    public function testVerifyRejectsInjectedInvoiceId(): void
    {
        $result = $this->gateway->verify(
            ['id' => self::INVOICE_ID . '?token=attacker-token#'],
            ['api_token' => 'merchant-token', 'mode' => 'live']
        );

        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertSame('', $result['gateway_trx_id']);
        $this->assertSame('failed', $result['status']);
    }

    public function testVerifyFailsClosedWithoutAnApiToken(): void
    {
        $result = $this->gateway->verify(
            ['id' => self::INVOICE_ID],
            ['mode' => 'live']
        );

        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertSame('', $result['gateway_trx_id']);
        $this->assertSame('failed', $result['status']);
    }

    public function testPaidInvoiceReportsTheProviderAuthenticatedReference(): void
    {
        $result = $this->callPrivate('interpretInvoiceResponse', [
            ['data' => [
                'id'      => self::INVOICE_ID,
                'status'  => 'confirmed',
                'price'   => 100.1,
                'orderId' => 'OP-ABCDEF0123',
            ]],
            self::INVOICE_ID,
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('OP-ABCDEF0123', $result['verified_trx_id']);
        $this->assertSame('100.10', $result['amount']);
        $this->assertSame('completed', $result['status']);
    }

    public function testResponseAboutAnotherInvoiceIsRejected(): void
    {
        $result = $this->callPrivate('interpretInvoiceResponse', [
            ['data' => [
                'id'      => 'someoneElsesInvoiceId',
                'status'  => 'confirmed',
                'price'   => 100.10,
                'orderId' => 'OP-ABCDEF0123',
            ]],
            self::INVOICE_ID,
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('verified_trx_id', $result);
    }

    public function testResponseWithoutAnInvoiceIdIsRejected(): void
    {
        $result = $this->callPrivate('interpretInvoiceResponse', [
            ['data' => [
                'status'  => 'confirmed',
                'price'   => 100.10,
                'orderId' => 'OP-ABCDEF0123',
            ]],
            self::INVOICE_ID,
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testPaidInvoiceWithoutTheOwnPayReferenceIsRejected(): void
    {
        $result = $this->callPrivate('interpretInvoiceResponse', [
            ['data' => [
                'id'     => self::INVOICE_ID,
                'status' => 'confirmed',
                'price'  => 100.10,
            ]],
            self::INVOICE_ID,
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('verified_trx_id', $result);
    }

    public function testUnpaidInvoiceDoesNotSettle(): void
    {
        $result = $this->callPrivate('interpretInvoiceResponse', [
            ['data' => [
                'id'      => self::INVOICE_ID,
                'status'  => 'new',
                'price'   => 100.10,
                'orderId' => 'OP-ABCDEF0123',
            ]],
            self::INVOICE_ID,
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
    }

    public function testPriceIsNormalisedToADecimalString(): void
    {
        $result = $this->callPrivate('interpretInvoiceResponse', [
            ['data' => [
                'id'      => self::INVOICE_ID,
                'status'  => 'complete',
                'price'   => 1.0E-7,
                'orderId' => 'OP-ABCDEF0123',
            ]],
            self::INVOICE_ID,
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('0.00', $result['amount']);
    }
}
