<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/ccavenue/CCAvenueGateway.php';

use OwnPay\Modules\Gateways\CCAvenue\CCAvenueGateway;

/**
 * CCAvenue authenticate-and-decrypt callbacks.
 *
 * `GatewayApiService::handleCallback()` treats any `success => false` from
 * verify() as "Verification failed" and mutates no state, so the contract these
 * tests pin down is the one that matters: a payload that cannot be decrypted
 * must never report success, whatever it contains. `openssl_decrypt()` returns
 * false (not an empty string) on failure, and hex2bin() returns false for
 * odd-length hex - verify() must handle both explicitly.
 *
 * @see https://github.com/own-pay/OwnPay/issues/616
 */
final class CCAvenueGatewayVerifyTest extends TestCase
{
    private const WORKING_KEY = 'test-working-key';

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'merchant_id'  => 'M1',
            'access_code'  => 'A1',
            'working_key'  => self::WORKING_KEY,
            'mode'         => 'test',
        ];
    }

    private function encrypt(string $plaintext): string
    {
        $iv = pack('C*', 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
        $key = openssl_digest(self::WORKING_KEY, 'md5', true);
        $this->assertIsString($key);

        $cipher = openssl_encrypt($plaintext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $this->assertIsString($cipher);

        return bin2hex($cipher);
    }

    public function testSuccessfulOrderIsDecrypted(): void
    {
        $encResp = $this->encrypt(http_build_query([
            'order_status' => 'Success',
            'tracking_id'  => 'TRK-1',
            'amount'       => '1500.00',
            'order_id'     => 'trx_abc',
        ]));

        $result = (new CCAvenueGateway())->verify(['encResp' => $encResp], $this->credentials());

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('TRK-1', $result['gateway_trx_id']);
        $this->assertSame('trx_abc', $result['trx_id']);
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $encResp = $this->encrypt(http_build_query([
            'order_status' => 'Success',
            'order_id'     => 'trx_abc',
        ]));
        // Flip the tail so the CBC padding check fails.
        $tampered = substr($encResp, 0, -2) . (str_ends_with($encResp, '00') ? 'ff' : '00');

        $result = (new CCAvenueGateway())->verify(['encResp' => $tampered], $this->credentials());

        $this->assertFalse($result['success'], 'A payload that fails to decrypt must not report success.');
        $this->assertSame('failed', $result['status']);
    }

    public function testGarbagePayloadIsRejected(): void
    {
        $result = (new CCAvenueGateway())->verify(['encResp' => 'deadbeefdeadbeef'], $this->credentials());

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['gateway_trx_id']);
    }

    public function testOddLengthHexPayloadIsRejectedWithoutWarning(): void
    {
        // hex2bin() returns false for odd-length input; that must not be fed to
        // openssl_decrypt() as an empty string.
        $result = @(new CCAvenueGateway())->verify(['encResp' => 'abc'], $this->credentials());

        $this->assertFalse($result['success']);
    }

    public function testMissingEncRespIsRejected(): void
    {
        $result = (new CCAvenueGateway())->verify([], $this->credentials());

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
    }
}
