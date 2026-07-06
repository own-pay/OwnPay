<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Checkout\CheckoutController;
use OwnPay\Http\Request;
use OwnPay\Repository\TransactionRepository;

/**
 * Regression for the checkout double-submit / double-gateway-session race: CheckoutController::
 * pay() and expressPay() used to read transaction status unlocked and only flip it to
 * 'processing' AFTER the external gateway call succeeded, so two near-simultaneous requests
 * (double-click, two tabs) could both pass the status check and both create a live payment
 * session at the gateway. TransactionRepository::claimPendingForPay() closes this with an
 * atomic `UPDATE ... WHERE status = 'pending'` compare-and-swap, called BEFORE any gateway call.
 *
 * True concurrent requests can't be simulated in a single-threaded PHPUnit run, so this proves
 * the underlying atomicity guarantee directly (a second claim attempt on an already-claimed row
 * always loses), then confirms CheckoutController::pay() actually calls it before doing anything
 * external for the manual-gateway path (no live gateway needed to exercise that branch).
 */
final class CheckoutClaimPendingForPayTest extends IntegrationTestCase
{
    private Database $db;
    private TransactionRepository $txnRepo;
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

        $txnRepo = $container->get(TransactionRepository::class);
        $this->assertInstanceOf(TransactionRepository::class, $txnRepo);
        $this->txnRepo = $txnRepo;

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
        $this->db->execute("DELETE FROM op_transactions WHERE trx_id LIKE 'zzclaim-%'");
        $this->db->execute("DELETE FROM op_manual_gateways WHERE slug = 'zzclaim-manual-bank' AND merchant_id = :mid", ['mid' => $this->merchantId]);
    }

    private function ensureActiveManualGateway(): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM op_manual_gateways WHERE slug = 'zzclaim-manual-bank' AND merchant_id = :mid",
            ['mid' => $this->merchantId]
        );
        if ($existing === null) {
            $this->db->insert(
                "INSERT INTO op_manual_gateways (merchant_id, slug, name, status) VALUES (:mid, 'zzclaim-manual-bank', 'Test Bank', 'active')",
                ['mid' => $this->merchantId]
            );
        }
    }

    private function insertTransaction(string $trxId, string $uuid): int
    {
        $this->db->execute(
            "INSERT INTO op_transactions (merchant_id, uuid, trx_id, gateway_slug, amount, net_amount, currency, method, status)
             VALUES (:mid, :uuid, :trx, '', '100.00', '100.00', 'BDT', 'manual', 'pending')",
            ['mid' => $this->merchantId, 'uuid' => $uuid, 'trx' => $trxId]
        );
        $row = $this->db->fetchOne("SELECT id FROM op_transactions WHERE trx_id = :t", ['t' => $trxId]);
        return (int) $row['id'];
    }

    public function testSecondClaimOnAlreadyClaimedTransactionFails(): void
    {
        $txnId = $this->insertTransaction('zzclaim-txn-1', '11111111-2222-4333-8444-zzclaimtxn1');

        $first = $this->txnRepo->claimPendingForPay($txnId, 'bkash-api', 'processing', $this->merchantId);
        $this->assertTrue($first, 'first claim on a pending transaction must win');

        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE id = :id", ['id' => $txnId]);
        $this->assertSame('processing', $row['status']);

        $second = $this->txnRepo->claimPendingForPay($txnId, 'bkash-api', 'processing', $this->merchantId);
        $this->assertFalse($second, 'a second claim once status is no longer pending must lose - this is what prevents two concurrent /pay requests from both reaching the gateway');
    }

    public function testClaimIsScopedToMerchantId(): void
    {
        $txnId = $this->insertTransaction('zzclaim-txn-2', '11111111-2222-4333-8444-zzclaimtxn2');

        $wrongMerchant = $this->txnRepo->claimPendingForPay($txnId, 'bkash-api', 'processing', 99999999);
        $this->assertFalse($wrongMerchant, 'claim must not succeed for a merchant that does not own the row');

        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE id = :id", ['id' => $txnId]);
        $this->assertSame('pending', $row['status'], 'status must be untouched by a wrong-merchant claim attempt');
    }

    public function testRevertToPendingAllowsARetryClaim(): void
    {
        $txnId = $this->insertTransaction('zzclaim-txn-3', '11111111-2222-4333-8444-zzclaimtxn3');

        $this->assertTrue($this->txnRepo->claimPendingForPay($txnId, 'bkash-api', 'processing', $this->merchantId));

        // Simulate a gateway call that failed - the controller reverts the claim.
        $this->txnRepo->setGatewayAndStatus($txnId, 'bkash-api', 'pending', $this->merchantId);

        $retryClaim = $this->txnRepo->claimPendingForPay($txnId, 'nagad', 'processing', $this->merchantId);
        $this->assertTrue($retryClaim, 'after reverting to pending, a retry (even with a different gateway) must be claimable again');
    }

    private function manualPayRequest(string $token, string $hash): Request
    {
        $req = new Request(
            [],
            ['checkout_hash' => $hash, 'gateway' => 'zzclaim-manual-bank', 'gateway_mode' => 'manual'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "/checkout/{$token}/pay"]
        );
        $req->setRouteParams(['token' => $token]);
        return $req;
    }

    public function testManualPayDoubleSubmitSecondCallIsRejected(): void
    {
        $this->ensureActiveManualGateway();
        $this->insertTransaction('zzclaim-txn-4', '11111111-2222-4333-8444-zzclaimtxn4');
        $hash = hash_hmac('sha256', '100.00|BDT|zzclaim-txn-4', 'test-hmac-key');

        $res1 = $this->checkout->pay($this->manualPayRequest('zzclaim-txn-4', $hash));
        $this->assertSame(302, $res1->getStatusCode(), 'first submission must succeed and redirect to the status page');

        $row = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzclaim-txn-4']);
        $this->assertSame('awaiting_verification', $row['status']);

        $res2 = $this->checkout->pay($this->manualPayRequest('zzclaim-txn-4', $hash));
        $this->assertSame(200, $res2->getStatusCode(), 'second submission renders the status page rather than re-processing');

        $rowAfter = $this->db->fetchOne("SELECT status FROM op_transactions WHERE trx_id = :t", ['t' => 'zzclaim-txn-4']);
        $this->assertSame('awaiting_verification', $rowAfter['status'], 'status must be unaffected by the rejected second submission');
    }

    public function testManualPayRejectsGatewayNotConfiguredForMerchant(): void
    {
        $this->insertTransaction('zzclaim-txn-5', '11111111-2222-4333-8444-zzclaimtxn5');
        $hash = hash_hmac('sha256', '100.00|BDT|zzclaim-txn-5', 'test-hmac-key');

        $req = new Request(
            [],
            ['checkout_hash' => $hash, 'gateway' => 'not-a-real-configured-gateway', 'gateway_mode' => 'manual'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/checkout/zzclaim-txn-5/pay']
        );
        $req->setRouteParams(['token' => 'zzclaim-txn-5']);

        $res = $this->checkout->pay($req);

        $this->assertSame(200, $res->getStatusCode());
        $row = $this->db->fetchOne("SELECT status, gateway_slug FROM op_transactions WHERE trx_id = :t", ['t' => 'zzclaim-txn-5']);
        $this->assertSame('pending', $row['status'], 'an unconfigured gateway must not be able to claim the transaction at all');
        $this->assertSame('', $row['gateway_slug']);
    }
}
