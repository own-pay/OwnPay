<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Dynamically import Southeast Asia Batch 3 gateway plugin classes before execution
require_once dirname(__DIR__, 2) . '/modules/gateways/shopeepay/ShopeePayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/touch-n-go/TouchNGoGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/billplz/BillplzGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/momo/MomoGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/truemoney/TrueMoneyGateway.php';

use OwnPay\Modules\Gateways\ShopeePay\ShopeePayGateway;
use OwnPay\Modules\Gateways\TouchNGo\TouchNGoGateway;
use OwnPay\Modules\Gateways\Billplz\BillplzGateway;
use OwnPay\Modules\Gateways\Momo\MomoGateway;
use OwnPay\Modules\Gateways\TrueMoney\TrueMoneyGateway;

/**
 * Unit and contract tests for the new Southeast Asia MFS & E-Wallets payment gateways (Batch 3).
 */
class SoutheastAsiaGatewayTest extends TestCase
{
    /**
     * Test the manifest validation logic for the new Batch 3 gateways.
     */
    public function testSoutheastAsiaGatewayManifestSpecs(): void
    {
        $root = dirname(__DIR__, 2);
        $gateways = ['shopeepay', 'touch-n-go', 'billplz', 'momo', 'truemoney'];

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

    /**
     * Test Billplz signature webhook verification.
     */
    public function testBillplzWebhookSignatureVerification(): void
    {
        $gateway = new BillplzGateway();
        $credentials = [
            'api_key' => 'billplz_api_key_123',
            'signature_key' => 'billplz_signature_key_456',
            'collection_id' => 'col_123',
            'mode' => 'sandbox',
        ];

        $rawBody = '{"id":"bill_id_100","collection_id":"col_123","paid":"true","amount":25050}';
        
        // HMAC-SHA256 signature calculated over raw body
        $expectedSignature = hash_hmac('sha256', $rawBody, 'billplz_signature_key_456');

        $headers = [
            'x-signature' => $expectedSignature,
        ];

        $isValid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertTrue($isValid, "Billplz verifyWebhook should authenticate valid signature.");

        $headers['x-signature'] = 'invalid_signature';
        $isInvalid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertFalse($isInvalid, "Billplz verifyWebhook should reject invalid signature.");
    }

    /**
     * Test MoMo dynamic signature calculation and webhook verification.
     */
    public function testMomoWebhookSignatureVerification(): void
    {
        $gateway = new MomoGateway();
        $credentials = [
            'partner_code' => 'MOMO_PARTNER_123',
            'access_key' => 'MOMO_ACCESS_KEY_456',
            'secret_key' => 'MOMO_SECRET_KEY_789',
            'mode' => 'sandbox',
        ];

        $webhookPayload = [
            'amount' => '150000',
            'message' => 'Success',
            'orderId' => 'TX_MOMO_100',
            'partnerCode' => 'MOMO_PARTNER_123',
            'requestId' => 'req_momo_100',
            'resultCode' => '0',
            'transId' => '987654321',
        ];

        // 1. Sort alphabetically
        $dataToSign = $webhookPayload;
        ksort($dataToSign);

        $params = [];
        foreach ($dataToSign as $key => $val) {
            $params[] = $key . '=' . $val;
        }
        $rawHash = implode('&', $params);
        $expectedSignature = hash_hmac('sha256', $rawHash, 'MOMO_SECRET_KEY_789');

        // 2. Add signature to payload
        $webhookPayload['signature'] = $expectedSignature;
        $rawBody = (string) json_encode($webhookPayload);

        // 3. Validate
        $isValid = $gateway->verifyWebhook($rawBody, [], $credentials);
        $this->assertTrue($isValid, "MoMo verifyWebhook should authenticate valid signature.");

        $webhookPayload['signature'] = 'invalid_signature';
        $rawBodyInvalid = (string) json_encode($webhookPayload);
        $isInvalid = $gateway->verifyWebhook($rawBodyInvalid, [], $credentials);
        $this->assertFalse($isInvalid, "MoMo verifyWebhook should reject invalid signature.");
    }

    /**
     * Verify that sandbox simulator validation strictly blocks live mode bypass across all Batch 3 gateways.
     */
    public function testSandboxSimulatorLiveModeBypassBlocking(): void
    {
        $shopeepay = new ShopeePayGateway();
        $touchngo = new TouchNGoGateway();
        $billplz = new BillplzGateway();
        $momo = new MomoGateway();
        $truemoney = new TrueMoneyGateway();

        $liveCreds = [
            'secret_key' => 'skey_test_secret_123',
            'api_key' => 'sandbox_api_key',
            'partner_code' => 'test_partner_code',
            'access_key' => 'test_access_key',
            'collection_id' => 'col_123',
            'mode' => 'live',
        ];

        $params = [
            'amount' => '500.00',
            'currency' => 'MYR',
            'trx_id' => 'SIM_123',
            'redirect_url' => 'https://example.com/callback',
        ];

        // ShopeePay Live Initiate validation
        try {
            $shopeepay->initiate($params, $liveCreds);
            $this->fail("ShopeePay should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // Touch 'n Go Live Initiate validation
        try {
            $touchngo->initiate($params, $liveCreds);
            $this->fail("Touch 'n Go should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // Billplz Live Initiate validation
        try {
            $billplz->initiate($params, $liveCreds);
            $this->fail("Billplz should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // MoMo Live Initiate validation
        try {
            $momo->initiate($params, $liveCreds);
            $this->fail("MoMo should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // TrueMoney Live Initiate validation
        try {
            $truemoney->initiate($params, $liveCreds);
            $this->fail("TrueMoney should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }
    }
}
