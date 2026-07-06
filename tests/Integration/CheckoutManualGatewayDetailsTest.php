<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Checkout\CheckoutController;
use OwnPay\Http\Request;

/**
 * Regression for checkout-gateway-bugs spec bugs 2 and 3: CheckoutController's $manualDetails
 * payload excluded logo_path and qr_code_path entirely (so the manual-gateway popup could only
 * ever show text initials, never the real logo or QR code image), and derived payment_number by
 * scanning input_fields for an entry the admin UI had no way to create. All three now come
 * straight off the op_manual_gateways row.
 */
final class CheckoutManualGatewayDetailsTest extends IntegrationTestCase
{
    private Database $db;
    private CheckoutController $checkout;
    private int $merchantId = 1;

    protected function setUp(): void
    {
        $_ENV['HMAC_KEY'] = 'test-hmac-key';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);
        $container->instance(Container::class, $container);

        $checkout = $container->get(CheckoutController::class);
        $this->assertInstanceOf(CheckoutController::class, $checkout);
        $this->checkout = $checkout;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_transactions WHERE trx_id LIKE 'zzmgd-%'");
        $this->db->execute("DELETE FROM op_manual_gateways WHERE slug = 'zzmgd-manual-bank' AND merchant_id = :mid", ['mid' => $this->merchantId]);
    }

    private function insertManualGateway(?string $logoPath, ?string $qrPath, ?string $paymentNumber): void
    {
        $this->db->execute(
            "INSERT INTO op_manual_gateways (merchant_id, slug, name, logo_path, qr_code_path, payment_number, status)
             VALUES (:mid, 'zzmgd-manual-bank', 'Test Bank', :logo, :qr, :pn, 'active')",
            ['mid' => $this->merchantId, 'logo' => $logoPath, 'qr' => $qrPath, 'pn' => $paymentNumber]
        );
    }

    private function insertTransaction(string $trxId, string $uuid): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, gateway_slug, amount, net_amount, currency, method, status)
             VALUES (:mid, :uuid, :trx, '', '100.00', '100.00', 'BDT', 'manual', 'pending')",
            ['mid' => $this->merchantId, 'uuid' => $uuid, 'trx' => $trxId]
        );
    }

    private function fetchManualGateways(string $trxId): array
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/checkout/{$trxId}"]);
        $req->setRouteParams(['token' => $trxId]);
        $res = $this->checkout->show($req);
        $html = $res->getBody();

        $this->assertMatchesRegularExpression('/data-manual-gateways="([^"]*)"/', $html);
        preg_match('/data-manual-gateways="([^"]*)"/', $html, $matches);
        $decoded = json_decode(html_entity_decode($matches[1]), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    public function testManualDetailsIncludesLogoQrAndPaymentNumberWhenConfigured(): void
    {
        $this->insertManualGateway('/uploads/gw/logo.png', '/uploads/gw/qr.png', '01711-XXXXXX');
        $this->insertTransaction('zzmgd-txn-1', '11111111-2222-4333-8444-zzmgdtxn1');

        $details = $this->fetchManualGateways('zzmgd-txn-1');

        $this->assertSame('/uploads/gw/logo.png', $details['zzmgd-manual-bank']['logo_path']);
        $this->assertSame('/uploads/gw/qr.png', $details['zzmgd-manual-bank']['qr_code_path']);
        $this->assertSame('01711-XXXXXX', $details['zzmgd-manual-bank']['payment_number']);
    }

    public function testManualDetailsOmitsUnsetFieldsWithoutErroring(): void
    {
        $this->insertManualGateway(null, null, null);
        $this->insertTransaction('zzmgd-txn-2', '11111111-2222-4333-8444-zzmgdtxn2');

        $details = $this->fetchManualGateways('zzmgd-txn-2');

        $this->assertNull($details['zzmgd-manual-bank']['logo_path']);
        $this->assertNull($details['zzmgd-manual-bank']['qr_code_path']);
        $this->assertSame('', $details['zzmgd-manual-bank']['payment_number']);
    }
}
