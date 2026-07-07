<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Checkout\CheckoutController;
use OwnPay\Controller\Checkout\PaymentIntentCheckoutController;
use OwnPay\Http\Request;

/**
 * Regression for the checkout back-navigation business-logic gap (GitHub-reported): a customer
 * who picks an API gateway and then returns to the plain checkout URL - either right after
 * picking (before ever reaching the gateway) or by hitting back FROM the external gateway's own
 * page - must see the checkout page again so they can pick a different gateway, instead of being
 * stuck on a permanent "Payment Processing" status screen.
 *
 * The gateway's own return navigation always targets /status (see DomainUrlService::
 * buildLegacyCallbackUrl/buildCallbackUrl), never the plain checkout URL - so GET /checkout/{token}
 * (this test) is unambiguously "the customer came back on their own", and /status (untouched,
 * see CheckoutStatusRedirectTest) keeps working exactly as before for genuine gateway returns.
 */
final class CheckoutBackNavigationTest extends IntegrationTestCase
{
    private Database $db;
    private CheckoutController $checkout;
    private PaymentIntentCheckoutController $intentCheckout;
    private int $merchantId = 1;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
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

        $intentCheckout = $container->get(PaymentIntentCheckoutController::class);
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
        $this->db->execute("DELETE FROM op_transactions WHERE trx_id LIKE 'zzback-%'");
        $this->db->execute("DELETE FROM op_payment_intents WHERE token LIKE 'zzback-%'");
    }

    private function insertTransaction(string $trxId, string $uuid, string $status, string $gateway = 'bkash-api', ?int $intentId = null): int
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, payment_intent_id, gateway_slug, amount, net_amount, currency, method, status)
             VALUES (:mid, :uuid, :trx, :pi, :gw, '100.00', '100.00', 'BDT', 'api', :status)",
            ['mid' => $this->merchantId, 'uuid' => $uuid, 'trx' => $trxId, 'pi' => $intentId, 'gw' => $gateway, 'status' => $status]
        );
        $row = $this->db->fetchOne("SELECT id FROM op_transactions WHERE trx_id = :t", ['t' => $trxId]);
        return (int) $row['id'];
    }

    private function insertIntent(string $token, string $uuid, string $status): int
    {
        $this->db->execute(
            "INSERT INTO op_payment_intents (merchant_id, uuid, token, amount, currency, status, expires_at)
             VALUES (:mid, :uuid, :token, '100.00', 'BDT', :status, DATE_ADD(NOW(6), INTERVAL 1 DAY))",
            ['mid' => $this->merchantId, 'uuid' => $uuid, 'token' => $token, 'status' => $status]
        );
        $row = $this->db->fetchOne("SELECT id FROM op_payment_intents WHERE token = :t", ['t' => $token]);
        return (int) $row['id'];
    }

    private function showRequest(string $token): Request
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET'], [], []);
        $req->setRouteParams(['token' => $token]);
        return $req;
    }

    public function testProcessingTransactionShowsCheckoutPageNotStatus(): void
    {
        $this->insertTransaction('zzback-txn-1', '11111111-2222-4333-8444-zzbacktxn01', 'processing');

        $resp = $this->checkout->show($this->showRequest('zzback-txn-1'));

        $this->assertSame(200, $resp->getStatusCode(), 'must render the checkout page directly, not redirect/expire');
        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzback-txn-1']);
        $this->assertSame('pending', $row['status'], 'transaction must be reverted to pending so a retry is accepted');
    }

    public function testCompletedTransactionStillShowsStatusPageNotCheckout(): void
    {
        // Safety: reactivateForRetry must never touch a genuinely-finished transaction.
        $this->insertTransaction('zzback-txn-2', '11111111-2222-4333-8444-zzbacktxn02', 'completed');

        $resp = $this->checkout->show($this->showRequest('zzback-txn-2'));

        $this->assertSame(200, $resp->getStatusCode());
        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzback-txn-2']);
        $this->assertSame('completed', $row['status'], 'a completed transaction must never be reverted');
    }

    public function testProcessingIntentShowsCheckoutPageNotStatus(): void
    {
        $intentId = $this->insertIntent('zzback-intent-1', '22222222-3333-4444-8555-zzbackint01', 'processing');
        $this->insertTransaction('zzback-txn-3', '11111111-2222-4333-8444-zzbacktxn03', 'processing', 'bkash-api', $intentId);

        $resp = $this->intentCheckout->show($this->showRequest('zzback-intent-1'));

        $this->assertSame(200, $resp->getStatusCode(), 'must render the checkout page directly, not redirect/expire');
        $intentRow = $this->db->fetchOne("SELECT status FROM op_payment_intents WHERE token = :t", ['t' => 'zzback-intent-1']);
        $this->assertSame('pending', $intentRow['status']);
        $txnRow = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzback-txn-3']);
        $this->assertSame('pending', $txnRow['status'], 'the linked transaction must also be reverted so pay() accepts a retry');
    }

    public function testCompletedIntentStillShowsStatusPageNotCheckout(): void
    {
        $intentId = $this->insertIntent('zzback-intent-2', '22222222-3333-4444-8555-zzbackint02', 'completed');
        $this->insertTransaction('zzback-txn-4', '11111111-2222-4333-8444-zzbacktxn04', 'completed', 'bkash-api', $intentId);

        $resp = $this->intentCheckout->show($this->showRequest('zzback-intent-2'));

        $this->assertSame(200, $resp->getStatusCode());
        $intentRow = $this->db->fetchOne("SELECT status FROM op_payment_intents WHERE token = :t", ['t' => 'zzback-intent-2']);
        $this->assertSame('completed', $intentRow['status'], 'a completed intent must never be reverted');
    }
}
