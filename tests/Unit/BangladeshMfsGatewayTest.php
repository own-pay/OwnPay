<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Dynamically import MFS gateway plugin classes before execution
require_once dirname(__DIR__, 2) . '/modules/gateways/portwallet/PortWalletGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/nexuspay/NexusPayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/cellfin/CellFinGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/tap/TapGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/ok-wallet/OkWalletGateway.php';

use OwnPay\Modules\Gateways\PortWallet\PortWalletGateway;
use OwnPay\Modules\Gateways\NexusPay\NexusPayGateway;
use OwnPay\Modules\Gateways\CellFin\CellFinGateway;
use OwnPay\Modules\Gateways\Tap\TapGateway;
use OwnPay\Modules\Gateways\OkWallet\OkWalletGateway;

/**
 * Unit and contract tests for the new Bangladesh MFS payment gateways.
 */
class BangladeshMfsGatewayTest extends TestCase
{
    /**
     * Test the manifest validation logic for the new gateways.
     */
    public function testMfsGatewayManifestSpecs(): void
    {
        $root = dirname(__DIR__, 2);
        $gateways = ['portwallet', 'nexuspay', 'cellfin', 'tap', 'ok-wallet'];

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
     * Test high-precision BCMath math is applied to amount formatting and formatting conforms.
     */
    public function testHighPrecisionAmountFormatting(): void
    {
        $portwallet = new PortWalletGateway();
        $nexuspay = new NexusPayGateway();
        $cellfin = new CellFinGateway();
        $tap = new TapGateway();
        $okwallet = new OkWalletGateway();

        $params = [
            'amount' => '1234.56',
            'currency' => 'BDT',
            'trx_id' => 'TX1000',
            'redirect_url' => 'https://example.com/redirect',
            'cancel_url' => 'https://example.com/cancel',
        ];

        // PortWallet initiate test in sandbox mode
        $portCreds = [
            'app_key' => 'test_app_key',
            'secret_key' => 'test_secret_key',
            'mode' => 'sandbox',
        ];
        $res = $portwallet->initiate($params, $portCreds);
        $this->assertNotEmpty($res['redirect_url']);

        // NexusPay verify test signature in sandbox mode
        $nexusCreds = [
            'merchant_id' => 'test_merchant',
            'secret_key' => 'test_secret',
            'mode' => 'sandbox',
        ];
        $verifyRes = $nexuspay->verify([
            'trx_id' => 'TX1000',
            'gateway_trx_id' => 'SIM_123',
            'amount' => '1234.56',
            'signature' => hash('sha256', 'test_merchantTX10001234.56test_secretSIM'),
        ], $nexusCreds);
        $this->assertTrue($verifyRes['success']);
        $this->assertSame('1234.56', $verifyRes['amount']);
    }

    /**
     * Verify that sandbox simulator validation strictly blocks SIM_ prefixes in live mode.
     */
    public function testSandboxSimulatorLiveModeBypassBlocking(): void
    {
        $portwallet = new PortWalletGateway();
        $nexuspay = new NexusPayGateway();
        $cellfin = new CellFinGateway();
        $tap = new TapGateway();
        $okwallet = new OkWalletGateway();

        $liveCreds = [
            'app_key' => 'live_app_key',
            'merchant_id' => 'live_merchant',
            'api_key' => 'live_api_key',
            'secret_key' => 'live_secret_key',
            'mode' => 'live',
        ];

        // 1. PortWallet live mode bypass blocking
        $verifyRes = $portwallet->verify([
            'invoice_id' => 'SIM_123',
            'amount' => '100.00',
        ], $liveCreds);
        $this->assertFalse($verifyRes['success']);

        // 2. NexusPay live mode bypass blocking
        $verifyRes = $nexuspay->verify([
            'trx_id' => 'TX1000',
            'gateway_trx_id' => 'SIM_123',
            'amount' => '100.00',
            'signature' => hash('sha256', 'live_merchantTX1000100.00live_secret_keySIM'),
        ], $liveCreds);
        $this->assertFalse($verifyRes['success']);

        // 3. CellFin live mode bypass blocking
        $verifyRes = $cellfin->verify([
            'trx_id' => 'TX1000',
            'gateway_trx_id' => 'SIM_123',
            'amount' => '100.00',
            'signature' => hash_hmac('sha256', 'live_merchantTX1000100.00SIM', 'live_secret_key'),
        ], $liveCreds);
        $this->assertFalse($verifyRes['success']);

        // 4. Tap live mode bypass blocking
        $verifyRes = $tap->verify([
            'trx_id' => 'TX1000',
            'gateway_trx_id' => 'SIM_123',
            'amount' => '100.00',
            'signature' => hash('sha256', 'live_merchantTX1000100.00live_secret_keySIM'),
        ], $liveCreds);
        $this->assertFalse($verifyRes['success']);

        // 5. OK Wallet live mode bypass blocking
        $verifyRes = $okwallet->verify([
            'trx_id' => 'TX1000',
            'gateway_trx_id' => 'SIM_123',
            'amount' => '100.00',
            'signature' => hash('sha256', 'live_merchantTX1000100.00live_secret_keySIM'),
        ], $liveCreds);
        $this->assertFalse($verifyRes['success']);
    }
}
