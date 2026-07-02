<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/paytm/PaytmGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/cashfree/CashfreeGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/payu/PayuGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/instamojo/InstamojoGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/mobikwik/MobikwikGateway.php';

use OwnPay\Modules\Gateways\Paytm\PaytmGateway;
use OwnPay\Modules\Gateways\Cashfree\CashfreeGateway;
use OwnPay\Modules\Gateways\Payu\PayuGateway;
use OwnPay\Modules\Gateways\Instamojo\InstamojoGateway;
use OwnPay\Modules\Gateways\Mobikwik\MobikwikGateway;

class IndiaMfsGatewayTest extends TestCase
{
    public function testIndiaGatewayManifestSpecs(): void
    {
        $root = dirname(__DIR__, 2);
        $gateways = ['paytm', 'cashfree', 'payu', 'instamojo', 'mobikwik'];

        foreach ($gateways as $slug) {
            $manifestPath = $root . '/modules/gateways/' . $slug . '/manifest.json';
            $this->assertFileExists($manifestPath, "Manifest for {$slug} should exist.");

            $data = json_decode(file_get_contents($manifestPath), true);
            $this->assertNotNull($data, "Manifest for {$slug} should be valid JSON.");

            $this->assertSame($slug, $data['slug'] ?? null);
            $this->assertSame('gateway', $data['type'] ?? null);
            $this->assertNotEmpty($data['entrypoint'] ?? null);
            $this->assertNotEmpty($data['name'] ?? null);
        }
    }

    public function testPaytmChecksumEncryptionAndHashing(): void
    {
        $merchantKey = 'd87f7396c00f121d';
        $params = [
            'MID' => 'OWNPAY_MID_100',
            'ORDER_ID' => 'TX1000',
            'TXN_AMOUNT' => '1500.50',
            'CUST_ID' => 'CUST_123'
        ];

        $checksum = PaytmGateway::generateSignature($params, $merchantKey);
        $this->assertNotEmpty($checksum);

        $isValid = PaytmGateway::verifySignature($params, $merchantKey, $checksum);
        $this->assertTrue($isValid);

        $params['TXN_AMOUNT'] = '1500.51';
        $isInvalid = PaytmGateway::verifySignature($params, $merchantKey, $checksum);
        $this->assertFalse($isInvalid);
    }

    public function testPayuSignatureAndCallbackHash(): void
    {
        $gateway = new PayuGateway();
        $credentials = [
            'merchant_key' => 'PAYU_MCH_KEY_123',
            'salt' => 'PAYU_SALT_XYZ',
            'mode' => 'sandbox',
        ];

        $params = [
            'amount' => '450.75',
            'currency' => 'INR',
            'trx_id' => 'TX2000',
            'redirect_url' => 'https://example.com/callback',
            'cancel_url' => 'https://example.com/cancel',
        ];

        $initRes = $gateway->initiate($params, $credentials);
        $this->assertNotEmpty($initRes['form_html']);

        $productInfo = 'Order Payment TX2000';
        $firstname = 'OwnPay Customer';
        $email = 'customer@ownpay.test';
        $expectedHashString = "PAYU_MCH_KEY_123|TX2000|450.75|{$productInfo}|{$firstname}|{$email}|||||||||||PAYU_SALT_XYZ";
        $expectedHash = strtolower(hash('sha512', $expectedHashString));
        $this->assertStringContainsString($expectedHash, $initRes['form_html']);

        $callbackData = [
            'key' => 'PAYU_MCH_KEY_123',
            'txnid' => 'TX2000',
            'amount' => '450.75',
            'productinfo' => $productInfo,
            'firstname' => $firstname,
            'email' => $email,
            'status' => 'success',
            'mihpayid' => '987654321',
            'hash' => '',
        ];

        $callbackHashString = "PAYU_SALT_XYZ|success|||||||||||{$email}|{$firstname}|{$productInfo}|450.75|TX2000|PAYU_MCH_KEY_123";
        $callbackData['hash'] = strtolower(hash('sha512', $callbackHashString));

        $verifyRes = $gateway->verify($callbackData, $credentials);
        $this->assertTrue($verifyRes['success']);
        $this->assertSame('987654321', $verifyRes['gateway_trx_id']);
        $this->assertSame('450.75', $verifyRes['amount']);
    }

    public function testMobikwikZaakpayChecksum(): void
    {
        $gateway = new MobikwikGateway();
        $credentials = [
            'merchant_id' => 'MK_MID_100',
            'secret_key' => 'MK_SECRET_KEY_XYZ',
            'mode' => 'sandbox',
        ];

        $params = [
            'amount' => '250.00',
            'currency' => 'INR',
            'trx_id' => 'TX3000',
            'redirect_url' => 'https://example.com/callback',
        ];

        $res = $gateway->initiate($params, $credentials);
        $this->assertNotEmpty($res['form_html']);

        $dataToCheck = [
            'merchantIdentifier' => 'MK_MID_100',
            'orderId' => 'TX3000',
            'amount' => '25000',
            'currency' => 'INR',
            'returnUrl' => 'https://example.com/callback',
            'buyerEmail' => 'customer@ownpay.test',
            'buyerPhone' => '9999999999',
            'buyerName' => 'OwnPay Customer',
        ];
        ksort($dataToCheck);
        $expectedString = '';
        foreach ($dataToCheck as $k => $v) {
            $expectedString .= $k . '=' . $v . '&';
        }
        $expectedString .= 'secret=MK_SECRET_KEY_XYZ';
        $expectedHash = hash('sha256', $expectedString);
        $this->assertStringContainsString($expectedHash, $res['form_html']);

        $callbackData = $dataToCheck;
        $callbackData['responseCode'] = '100';
        $callbackData['responseDescription'] = 'Approved';
        ksort($callbackData);
        $expectedCallbackString = '';
        foreach ($callbackData as $k => $v) {
            $expectedCallbackString .= $k . '=' . $v . '&';
        }
        $expectedCallbackString .= 'secret=MK_SECRET_KEY_XYZ';
        $callbackData['checksum'] = hash('sha256', $expectedCallbackString);

        $verifyRes = $gateway->verify($callbackData, $credentials);
        $this->assertTrue($verifyRes['success']);
        $this->assertSame('250.00', $verifyRes['amount']);
    }

    public function testInstamojoWebhookSignature(): void
    {
        $gateway = new InstamojoGateway();
        $credentials = [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'salt' => 'test_secret_salt',
            'mode' => 'sandbox',
        ];

        $webhookPayload = [
            'payment_id' => 'MOJO1000',
            'payment_request_id' => 'REQ1000',
            'status' => 'Credit',
            'amount' => '100.00',
        ];

        $dataToSign = $webhookPayload;
        ksort($dataToSign, SORT_STRING | SORT_FLAG_CASE);
        $msg = implode('|', $dataToSign);
        $expectedMac = hash_hmac('sha1', $msg, 'test_secret_salt');

        $webhookPayload['mac'] = $expectedMac;
        $isValid = $gateway->verifyWebhook(http_build_query($webhookPayload), [], $credentials);
        $this->assertTrue($isValid);

        $webhookPayload['mac'] = 'invalid_mac_here';
        $isInvalid = $gateway->verifyWebhook(http_build_query($webhookPayload), [], $credentials);
        $this->assertFalse($isInvalid);
    }

    public function testSandboxSimulatorLiveModeBypassBlocking(): void
    {
        $cashfree = new CashfreeGateway();

        $liveCreds = [
            'client_id' => 'TEST_CLIENT_ID',
            'client_secret' => 'TEST_CLIENT_SECRET',
            'mid' => 'TEST_MID_100',
            'merchant_key' => 'TEST_KEY_123',
            'merchant_id' => 'TEST_MID',
            'secret_key' => 'TEST_SECRET',
            'salt' => 'TEST_SALT',
            'mode' => 'live',
        ];

        $params = [
            'amount' => '100.00',
            'currency' => 'INR',
            'trx_id' => 'SIM_123',
            'redirect_url' => 'https://example.com/callback',
        ];

        $this->expectException(\RuntimeException::class);
        $cashfree->initiate($params, $liveCreds);
    }
}
