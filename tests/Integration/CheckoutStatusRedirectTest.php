<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Checkout\CheckoutController;
use OwnPay\Controller\Checkout\PaymentIntentCheckoutController;
use OwnPay\Http\Request;

/**
 * Regression: the checkout STATUS page must only render for transactions/intents that have had a real
 * payment EVENT. A pre-payment state (txn 'pending'/'created', intent 'pending') means the customer is
 * still on the gateway-selection step - visiting /status then must redirect back to the checkout page,
 * not show a misleading "pending payment" status screen.
 */
final class CheckoutStatusRedirectTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private CheckoutController $checkout;
    private PaymentIntentCheckoutController $intentCheckout;
    private int $merchantId = 1;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);
        $this->container->instance(Container::class, $this->container);

        $checkout = $this->container->get(CheckoutController::class);
        $this->assertInstanceOf(CheckoutController::class, $checkout);
        $this->checkout = $checkout;

        $intentCheckout = $this->container->get(PaymentIntentCheckoutController::class);
        $this->assertInstanceOf(PaymentIntentCheckoutController::class, $intentCheckout);
        $this->intentCheckout = $intentCheckout;

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
        $this->db->execute("DELETE FROM op_transactions WHERE trx_id LIKE 'zztest-%'");
        $this->db->execute("DELETE FROM op_payment_intents WHERE token LIKE 'zztest-%'");
    }

    private function insertTransaction(string $trxId, string $uuid, string $status): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, gateway_slug, amount, net_amount, currency, method, status)
             VALUES (:mid, :uuid, :trx, 'manual', '100.00', '100.00', 'BDT', 'manual', :status)",
            ['mid' => $this->merchantId, 'uuid' => $uuid, 'trx' => $trxId, 'status' => $status]
        );
    }

    private function insertIntent(string $token, string $uuid, string $status): void
    {
        $this->db->execute(
            "INSERT INTO op_payment_intents (merchant_id, uuid, token, amount, currency, status, expires_at)
             VALUES (:mid, :uuid, :token, '100.00', 'BDT', :status, DATE_ADD(NOW(6), INTERVAL 1 DAY))",
            ['mid' => $this->merchantId, 'uuid' => $uuid, 'token' => $token, 'status' => $status]
        );
    }

    private function statusRequest(string $token): Request
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET'], [], []);
        $req->setRouteParams(['token' => $token]);
        return $req;
    }

    public function testPendingTransactionRedirectsToCheckout(): void
    {
        $this->insertTransaction('zztest-pending', '11111111-2222-4333-8444-zztestpend01', 'pending');

        $resp = $this->checkout->status($this->statusRequest('zztest-pending'));

        $this->assertSame(302, $resp->getStatusCode(), 'pre-payment txn must redirect, not show status');
        $this->assertSame('/checkout/zztest-pending', $resp->getHeaders()['Location'] ?? null);
    }

    public function testCompletedTransactionRendersStatusPage(): void
    {
        $this->insertTransaction('zztest-complete', '11111111-2222-4333-8444-zztestcomp01', 'completed');

        $resp = $this->checkout->status($this->statusRequest('zztest-complete'));

        $this->assertSame(200, $resp->getStatusCode(), 'a paid txn must still show the status page');
    }

    public function testPendingIntentRedirectsToCheckout(): void
    {
        $this->insertIntent('zztest-intent-pending', '22222222-3333-4444-8555-zztestintp01', 'pending');

        $resp = $this->intentCheckout->status($this->statusRequest('zztest-intent-pending'));

        $this->assertSame(302, $resp->getStatusCode(), 'pre-payment intent must redirect, not show status');
        $this->assertSame('/checkout/intent/zztest-intent-pending', $resp->getHeaders()['Location'] ?? null);
    }

    public function testCompletedIntentRendersStatusPage(): void
    {
        $this->insertIntent('zztest-intent-complete', '22222222-3333-4444-8555-zztestintc01', 'completed');

        $resp = $this->intentCheckout->status($this->statusRequest('zztest-intent-complete'));

        $this->assertSame(200, $resp->getStatusCode(), 'a paid intent must still show the status page');
    }
}
