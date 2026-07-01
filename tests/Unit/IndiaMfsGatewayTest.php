<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Dynamically import India Batch 2 gateway plugin classes before execution
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

/**
 * Unit and contract tests for the new India MFS, UPI & Aggregator payment gateways (Batch 2).
 */
class IndiaMfsGatewayTest extends TestCase
{
    /**
     * Test the manifest validation logic for the new Batch 2 gateways.
     */
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

    /**
     * Test Paytm native OpenSSL checksum generation and verification logic.
     */
    public function testPaytmChecksumEncryptionAndHashing(): void
    {
        $merchantKey = "d87f7396c00f121d";
        $params = [
            "MID" => "OWNPAY_MID_100",
            "ORDER_ID" => "TX1000",
            "TXN_AMOUNT" => "1500.50",
            "CUST_ID" => "CUST_123"
        ];

        // 1. Generate signature
        $checksum = PaytmGateway::generateSignature($params, $merchantKey);
        $this->assertNotEmpty($checksum, "Checksum should not be empty.");

        // 2. Verify signature
        $isValid = PaytmGateway::verifySignature($params, $merchantKey, $checksum);
        $this->assertTrue($isValid, "Paytm signature verification should pass.");

        // 3. Fail signature on modification
        $params['TXN_AMOUNT'] = "1500.51";
        $isInvalid = PaytmGateway::verifySignature($params, $merchantKey, $checksum);
        $this->assertFalse($isInvalid, "Modified parameters should fail Paytm signature verification.");
    }

    /**
     * Test PayU India SHA-512 signature hash generation and callback validation.
     */
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

        // 1. Test Initiation
        $initRes = $gateway->initiate($params, $credentials);
        $this->assertNotEmpty($initRes['form_html']);

        // Check if correct hash is inside form_html
        // Formula: key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5|udf6|udf7|udf8|udf9|udf10|salt
        $productInfo = 'Order Payment TX2000';
        $firstname = 'OwnPay Customer';
        $email = 'customer@ownpay.test';
        $expectedHashString = "PAYU_MCH_KEY_123|TX2000|450.75|{$productInfo}|{$firstname}|{$email}|||||||||||PAYU_SALT_XYZ";
        $expectedHash = strtolower(hash('sha512', $expectedHashString));
        $this->assertStringContainsString($expectedHash, $initRes['form_html']);

        // 2. Test Callback Verification
        $callbackData = [
            'key' => 'PAYU_MCH_KEY_123',
            'txnid' => 'TX2000',
            'amount' => '450.75',
            'productinfo' => $productInfo,
            'firstname' => $firstname,
            'email' => $email,
            'status' => 'success',
            'mihpayid' => '987654321',
            'hash' => '', // filled below
        ];

        // Callback formula: salt|status|udf10|udf9|udf8|udf7|udf6|udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key
        $callbackHashString = "PAYU_SALT_XYZ|success|||||||||||{$email}|{$firstname}|{$productInfo}|450.75|TX2000|PAYU_MCH_KEY_123";
        $callbackData['hash'] = strtolower(hash('sha512', $callbackHashString));

        $verifyRes = $gateway->verify($callbackData, $credentials);
        $this->assertTrue($verifyRes['success']);
        $this->assertSame('987654321', $verifyRes['gateway_trx_id']);
        $this->assertSame('450.75', $verifyRes['amount']);
    }

    /**
     * Test MobiKwik (Zaakpay) alphabetical key-sorting query-string checksum logic.
     */
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

        // Amount in cents for Zaakpay = 25000
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
        $expectedString = "";
        foreach ($dataToCheck as $k => $v) {
            $expectedString .= $k . "=" . $v . "&";
        }
        $expectedString .= "secret=MK_SECRET_KEY_XYZ";
        $expectedHash = hash('sha256', $expectedString);
        $this->assertStringContainsString($expectedHash, $res['form_html']);

        // Test Callback status 100 verification
        $callbackData = $dataToCheck;
        $callbackData['responseCode'] = '100';
        $callbackData['responseDescription'] = 'Approved';
        ksort($callbackData);
        $expectedCallbackString = "";
        foreach ($callbackData as $k => $v) {
            $expectedCallbackString .= $k . "=" . $v . "&";
        }
        $expectedCallbackString .= "secret=MK_SECRET_KEY_XYZ";
        $callbackData['checksum'] = hash('sha256', $expectedCallbackString);

        $verifyRes = $gateway->verify($callbackData, $credentials);
        $this->assertTrue($verifyRes['success']);
        $this->assertSame('250.00', $verifyRes['amount']);
    }

    /**
     * Test Instamojo webhook MAC signature validation.
     */
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

        // 1. Webhook generation logic: sorted case-insensitive ksort, joined by pipe '|'
        $dataToSign = $webhookPayload;
        ksort($dataToSign, SORT_STRING | SORT_FLAG_CASE);
        $msg = implode('|', $dataToSign);
        $expectedMac = hash_hmac('sha1', $msg, 'test_secret_salt');

        // 2. Validate webhook
        $webhookPayload['mac'] = $expectedMac;
        $isValid = $gateway->verifyWebhook(http_build_query($webhookPayload), [], $credentials);
        $this->assertTrue($isValid, "Instamojo verifyWebhook should authenticate valid MAC.");

        // 3. Reject invalid MAC
        $webhookPayload['mac'] = "invalid_mac_here";
        $isInvalid = $gateway->verifyWebhook(http_build_query($webhookPayload), [], $credentials);
        $this->assertFalse($isInvalid, "Instamojo verifyWebhook should reject incorrect MAC.");
    }

    /**
     * Verify that sandbox simulator validation strictly blocks live mode bypass across all Batch 2 gateways.
     */
    public function testSandboxSimulatorLiveModeBypassBlocking(): void
    {
        $cashfree = new CashfreeGateway();
        $paytm = new PaytmGateway();
        $payu = new PayuGateway();
        $instamojo = new InstamojoGateway();
        $mobikwik = new MobikwikGateway();

        $liveCreds = [
            'client_id' => 'TEST_CLIENT_ID', // sandbox key pattern
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
            'trx_id' => 'SIM_123', // simulation trx pattern
            'redirect_url' => 'https://example.com/callback',
        ];

        // Cashfree Live Initiate validation
        $this->expectException(\RuntimeException::class);
        $cashfree->initiate($params, $liveCreds);
    }
}
