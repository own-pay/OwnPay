<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Dynamically import Global Giants Batch 5 gateway plugin classes before execution
require_once dirname(__DIR__, 2) . '/modules/gateways/amazon-pay/AmazonPayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/gocardless/GocardlessGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/affirm/AffirmGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/afterpay/AfterpayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/sezzle/SezzleGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/bitpay/BitpayGateway.php';

use OwnPay\Modules\Gateways\AmazonPay\AmazonPayGateway;
use OwnPay\Modules\Gateways\Gocardless\GocardlessGateway;
use OwnPay\Modules\Gateways\Affirm\AffirmGateway;
use OwnPay\Modules\Gateways\Afterpay\AfterpayGateway;
use OwnPay\Modules\Gateways\Sezzle\SezzleGateway;
use OwnPay\Modules\Gateways\Bitpay\BitpayGateway;

/**
 * Unit and contract tests for the new Global Giants payment gateways (Batch 5).
 */
class GlobalGiantsGatewayTest extends TestCase
{
    /**
     * Test the manifest validation logic for all six Batch 5 gateways.
     */
    public function testGlobalGiantsGatewayManifestSpecs(): void
    {
        $root = dirname(__DIR__, 2);
        $gateways = ['amazon-pay', 'gocardless', 'affirm', 'afterpay', 'sezzle', 'bitpay'];

        foreach ($gateways as $slug) {
            $manifestPath = $root . '/modules/gateways/' . $slug . '/manifest.json';
            $this->assertFileExists($manifestPath, "Manifest for {$slug} should exist.");

            $data = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($data)) {
                $data = [];
            }
            $this->assertNotEmpty($data, "Manifest for {$slug} should not be empty.");

            $this->assertSame($slug, $data['slug'] ?? null);
            $this->assertSame('gateway', $data['type'] ?? null);
            $this->assertNotEmpty($data['entrypoint'] ?? null);
            $this->assertNotEmpty($data['name'] ?? null);
        }
    }

    /**
     * Test GoCardless signature webhook verification.
     */
    public function testGocardlessWebhookSignatureVerification(): void
    {
        $gateway = new GocardlessGateway();
        $credentials = [
            'access_token' => 'gc_token_123',
            'webhook_secret' => 'gc_secret_key_456',
            'mode' => 'sandbox',
        ];

        $rawBody = '{"id":"event_123","action":"paid","amount":25050}';
        
        // HMAC-SHA256 signature calculated over raw body using webhook secret
        $expectedSignature = hash_hmac('sha256', $rawBody, 'gc_secret_key_456');

        $headers = [
            'webhook-signature' => $expectedSignature,
        ];

        $isValid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertTrue($isValid, "GoCardless verifyWebhook should authenticate valid signature.");

        $headers['webhook-signature'] = 'invalid_signature';
        $isInvalid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertFalse($isInvalid, "GoCardless verifyWebhook should reject invalid signature.");
    }

    /**
     * Test Sezzle signature webhook verification.
     */
    public function testSezzleWebhookSignatureVerification(): void
    {
        $gateway = new SezzleGateway();
        $credentials = [
            'public_key' => 'sz_pub_key_123',
            'private_key' => 'sz_priv_key_456',
            'mode' => 'sandbox',
        ];

        $rawBody = '{"uuid":"sz_session_100","order":{"reference_id":"TX1000","order_amount":{"amount_in_cents":3000}}}';

        // HMAC-SHA256 signature calculated over raw body using private key
        $expectedSignature = hash_hmac('sha256', $rawBody, 'sz_priv_key_456');

        $headers = [
            'x-sezzle-signature' => $expectedSignature,
        ];

        $isValid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertTrue($isValid, "Sezzle verifyWebhook should authenticate valid signature.");

        $headers['x-sezzle-signature'] = 'invalid_signature';
        $isInvalid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertFalse($isInvalid, "Sezzle verifyWebhook should reject invalid signature.");
    }

    /**
     * Test Amazon Pay signature webhook verification.
     */
    public function testAmazonPayWebhookSignatureVerification(): void
    {
        $gateway = new AmazonPayGateway();
        $credentials = [
            'merchant_id' => 'amzn_m_123',
            'store_id' => 'amzn_store_456',
            'public_key_id' => 'amzn_pub_789',
            'private_key' => 'mock_private_key',
            'mode' => 'test',
            'region' => 'us',
        ];

        $rawBody = '{"checkoutSessionId":"session_100","status":"PAID"}';

        // Amazon Pay custom test verification check matching expected mock
        $expectedSignature = hash_hmac('sha256', $rawBody, 'amzn_store_456');

        $headers = [
            'x-amz-pay-signature' => $expectedSignature,
        ];

        $isValid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertTrue($isValid, "Amazon Pay verifyWebhook should authenticate valid test signature.");

        $headers['x-amz-pay-signature'] = 'invalid_signature';
        $isInvalid = $gateway->verifyWebhook($rawBody, $headers, $credentials);
        $this->assertFalse($isInvalid, "Amazon Pay verifyWebhook should reject invalid signature.");
    }

    /**
     * Verify that sandbox simulator validation strictly blocks live mode bypass across all Batch 5 gateways.
     */
    public function testSandboxSimulatorLiveModeBypassBlocking(): void
    {
        $amazonPay = new AmazonPayGateway();
        $gocardless = new GocardlessGateway();
        $affirm = new AffirmGateway();
        $afterpay = new AfterpayGateway();
        $sezzle = new SezzleGateway();
        $bitpay = new BitpayGateway();

        $liveCreds = [
            'merchant_id' => 'live-merchant-id',
            'store_id' => 'live-store-id',
            'public_key_id' => 'live-pub-id',
            'private_key' => 'live-private-key-pem',
            'access_token' => 'live-access-token',
            'webhook_secret' => 'live-webhook-secret',
            'public_key' => 'live-public-key',
            'api_token' => 'live-api-token',
            'mode' => 'live',
            'region' => 'us',
        ];

        $params = [
            'amount' => '100.00',
            'currency' => 'USD',
            'trx_id' => 'SIM_123',
            'redirect_url' => 'https://example.com/callback',
            'cancel_url' => 'https://example.com/cancel',
        ];

        // 1. Amazon Pay Live Initiate validation
        try {
            $amazonPay->initiate($params, $liveCreds);
            $this->fail("Amazon Pay should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // 2. GoCardless Live Initiate validation
        try {
            $gocardless->initiate($params, $liveCreds);
            $this->fail("GoCardless should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // 3. Affirm Live Initiate validation
        try {
            $affirm->initiate($params, $liveCreds);
            $this->fail("Affirm should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // 4. Afterpay Live Initiate validation
        try {
            $afterpay->initiate($params, $liveCreds);
            $this->fail("Afterpay should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // 5. Sezzle Live Initiate validation
        try {
            $sezzle->initiate($params, $liveCreds);
            $this->fail("Sezzle should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }

        // 6. BitPay Live Initiate validation
        try {
            $bitpay->initiate($params, $liveCreds);
            $this->fail("BitPay should throw RuntimeException on live mode sandbox credential usage.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Sandbox simulation", $e->getMessage());
        }
    }
}
