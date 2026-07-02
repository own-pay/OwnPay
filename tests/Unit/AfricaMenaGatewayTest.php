<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/mtn-momo/MtnMomoGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/orange-money/OrangeMoneyGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/opay/OpayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/myfatoorah/MyfatoorahGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/tap-payments/TapPaymentsGateway.php';

use OwnPay\Modules\Gateways\MtnMomo\MtnMomoGateway;
use OwnPay\Modules\Gateways\OrangeMoney\OrangeMoneyGateway;
use OwnPay\Modules\Gateways\Opay\OpayGateway;
use OwnPay\Modules\Gateways\Myfatoorah\MyfatoorahGateway;
use OwnPay\Modules\Gateways\TapPayments\TapPaymentsGateway;

class AfricaMenaGatewayTest extends TestCase
{
    public function testAfricaMenaGatewayManifestSpecs(): void
    {
        $root = dirname(__DIR__, 2);
        $gateways = ['mtn-momo', 'orange-money', 'opay', 'myfatoorah', 'tap-payments'];

        foreach ($gateways as $slug) {
            $manifestPath = $root . '/modules/gateways/' . $slug . '/manifest.json';
            $this->assertFileExists($manifestPath, "Manifest for {$slug} should exist.");

            $data = json_decode((string)file_get_contents($manifestPath), true);
            $this->assertNotNull($data, "Manifest for {$slug} should be valid JSON.");

            $this->assertSame($slug, $data['slug'] ?? null);
            $this->assertSame('gateway', $data['type'] ?? null);
            $this->assertNotEmpty($data['entrypoint'] ?? null);
            $this->assertNotEmpty($data['name'] ?? null);
        }
    }

    public function testOpayWebhookSignatureVerification(): void
    {
        $gateway = new OpayGateway();
        $credentials = [
            'merchant_id' => '123456789',
            'public_key'  => 'opay_pub_key_123',
            'secret_key'  => 'opay_sec_key_456',
            'mode'        => 'sandbox',
        ];

        $rawBody = '{"id":"opay_trx_100","amount":{"total":2500},"reference":"TX1000"}';
        $expectedSignature = hash_hmac('sha512', $rawBody, 'opay_sec_key_456');

        $headers = [
            'x-opay-signature' => $expectedSignature,
        ];

        $isValid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertTrue($isValid);

        $headers['x-opay-signature'] = 'invalid_signature';
        $isInvalid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertFalse($isInvalid);
    }

    public function testMyfatoorahWebhookSignatureVerification(): void
    {
        $gateway = new MyfatoorahGateway();
        $credentials = [
            'api_key'        => 'mf_api_key_123',
            'webhook_secret' => 'mf_webhook_secret_456',
            'mode'           => 'sandbox',
        ];

        $rawBody = '{"InvoiceId":12345,"InvoiceStatus":"PAID","InvoiceValue":50.00}';
        $expectedSignature = base64_encode(hash_hmac('sha256', $rawBody, 'mf_webhook_secret_456', true));

        $headers = [
            'myfatoorah-signature' => $expectedSignature,
        ];

        $isValid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertTrue($isValid);

        $headers['myfatoorah-signature'] = 'invalid_signature';
        $isInvalid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertFalse($isInvalid);
    }

    public function testTapPaymentsWebhookSignatureVerification(): void
    {
        $gateway = new TapPaymentsGateway();
        $credentials = [
            'secret_key'     => 'sk_test_123',
            'webhook_secret' => 'tap_webhook_secret_456',
            'mode'           => 'sandbox',
        ];

        $rawBody = '{"id":"chg_123","amount":100.0,"status":"CAPTURED"}';
        $expectedSignature = hash_hmac('sha256', $rawBody, 'tap_webhook_secret_456');

        $headers = [
            'x-tap-sign' => $expectedSignature,
        ];

        $isValid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertTrue($isValid);

        $headers['x-tap-sign'] = 'invalid_signature';
        $isInvalid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertFalse($isInvalid);
    }

    public function testSandboxSimulatorLiveModeBypassBlocking(): void
    {
        $mtnmomo = new MtnMomoGateway();
        $orangemoney = new OrangeMoneyGateway();
        $opay = new OpayGateway();
        $myfatoorah = new MyfatoorahGateway();
        $tappayments = new TapPaymentsGateway();

        $liveCreds = [
            'api_user_id' => 'sandbox-user-id',
            'client_id'   => 'sandbox-client-id',
            'merchant_id' => 'sandbox-merchant-id',
            'public_key'  => 'sandbox-public-key',
            'api_key'     => 'sandbox-api-key',
            'secret_key'  => 'sandbox-secret-key',
            'subscription_key' => 'test-subscription-key',
            'client_secret' => 'test-client-secret',
            'merchant_key' => 'test-merchant-key',
            'mode'        => 'live',
        ];

        $params = [
            'amount'   => '100.00',
            'currency' => 'EUR',
            'trx_id'   => 'SIM_123',
            'redirect_url' => 'https://example.com/callback',
            'cancel_url' => 'https://example.com/cancel',
        ];

        try {
            $mtnmomo->initiate($params, $liveCreds);
            $this->fail('MTN MoMo should throw RuntimeException on live mode sandbox credential usage.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sandbox simulation', $e->getMessage());
        }

        try {
            $orangemoney->initiate($params, $liveCreds);
            $this->fail('Orange Money should throw RuntimeException on live mode sandbox credential usage.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sandbox simulation', $e->getMessage());
        }

        try {
            $opay->initiate($params, $liveCreds);
            $this->fail('OPay should throw RuntimeException on live mode sandbox credential usage.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sandbox simulation', $e->getMessage());
        }

        try {
            $myfatoorah->initiate($params, $liveCreds);
            $this->fail('MyFatoorah should throw RuntimeException on live mode sandbox credential usage.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sandbox simulation', $e->getMessage());
        }

        try {
            $tappayments->initiate($params, $liveCreds);
            $this->fail('Tap Payments should throw RuntimeException on live mode sandbox credential usage.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sandbox simulation', $e->getMessage());
        }
    }
}
