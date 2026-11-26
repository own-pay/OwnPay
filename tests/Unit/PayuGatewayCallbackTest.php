<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/payu/PayuGateway.php';

use OwnPay\Modules\Gateways\Payu\PayuGateway;

/**
 * Regression coverage for the PayU callback reference binding.
 *
 * PayU signs the callback with a sha512 reverse hash over the merchant's own `txnid`, so
 * the reference it echoes back is provider-authenticated. The core now settles a webhook
 * on that reference rather than on the reference named in the callback body, so the
 * adapter must keep reporting the OwnPay reference it was given.
 */
final class PayuGatewayCallbackTest extends TestCase
{
    private const MERCHANT_KEY = 'merchant-key';
    private const SALT = 'merchant-salt';
    private const TRX_ID = 'OP-ABCDEF0123';

    private PayuGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new PayuGateway();
    }

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'merchant_key' => self::MERCHANT_KEY,
            'salt'         => self::SALT,
            'mode'         => 'sandbox',
        ];
    }

    /**
     * Builds the PayU reverse hash for a callback payload.
     *
     * @param array<string, string> $fields Callback fields.
     * @return string Lowercase sha512 reverse hash.
     */
    private function reverseHash(array $fields): string
    {
        $hashString = self::SALT . '|' . $fields['status']
            . '||||||' . $fields['udf5'] . '|' . $fields['udf4'] . '|' . $fields['udf3']
            . '|' . $fields['udf2'] . '|' . $fields['udf1'] . '|' . $fields['email']
            . '|' . $fields['firstname'] . '|' . $fields['productinfo']
            . '|' . $fields['amount'] . '|' . $fields['txnid'] . '|' . $fields['key'];

        return strtolower(hash('sha512', $hashString));
    }

    public function testSignedCallbackReportsTheProviderAuthenticatedReference(): void
    {
        $fields = [
            'status'      => 'success',
            'amount'      => '100.00',
            'txnid'       => self::TRX_ID,
            'key'         => self::MERCHANT_KEY,
            'productinfo' => 'Test order',
            'firstname'   => 'OwnPay Customer',
            'email'       => 'customer@ownpay.test',
            'udf1'        => '',
            'udf2'        => '',
            'udf3'        => '',
            'udf4'        => '',
            'udf5'        => '',
            'mihpayid'    => 'MHP1234',
        ];
        $fields['hash'] = $this->reverseHash($fields);

        $result = $this->gateway->verify($fields, $this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame(self::TRX_ID, $result['verified_trx_id']);
        $this->assertSame(self::TRX_ID, $result['trx_id']);
    }

    public function testForgedCallbackIsRejected(): void
    {
        $fields = [
            'status'      => 'success',
            'amount'      => '100.00',
            'txnid'       => self::TRX_ID,
            'key'         => self::MERCHANT_KEY,
            'productinfo' => 'Test order',
            'firstname'   => 'OwnPay Customer',
            'email'       => 'customer@ownpay.test',
            'udf1'        => '',
            'udf2'        => '',
            'udf3'        => '',
            'udf4'        => '',
            'udf5'        => '',
            'mihpayid'    => 'MHP1234',
            'hash'        => str_repeat('0', 128),
        ];

        $result = $this->gateway->verify($fields, $this->credentials());

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('verified_trx_id', $result);
    }
}
