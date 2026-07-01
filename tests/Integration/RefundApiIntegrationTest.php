<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Controller\Api\RefundController;

final class RefundApiIntegrationTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private RefundController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $_ENV['ENCRYPTION_KEY'] = $_ENV['PII_ENCRYPTION_KEY'] ?? 'cd4c6edf857c4ad19cb41784e849adf79ec3fc20319c28e735bd3fbd801eca33';

        $this->db = Database::getInstance();
        $this->container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);

        $this->controller = $this->container->get(RefundController::class);

        // Clean up any stale records
        $this->cleanup();

        // Seed merchant and transaction
        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (99998, 'test-merchant-uuid-99998', 'Refund Test Merchant', 'refund-test', 'refund@test.com', 'active', '{}')"
        );

        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_trx_id, gateway_slug, amount, fee, net_amount, currency, status, created_at)
             VALUES (999981, 99998, 'tx-uuid-999981', 'OP-TRX-TEST-1', 'BKASH-GW-1', 'bkash', 500.00, 10.00, 490.00, 'BDT', 'completed', '2026-06-16 10:00:00')"
        );
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_trx_id, gateway_slug, amount, fee, net_amount, currency, status, created_at)
             VALUES (999982, 99998, 'tx-uuid-999982', 'OP-TRX-TEST-2', 'BKASH-GW-2', 'bkash', 300.00, 6.00, 294.00, 'BDT', 'completed', '2026-06-17 12:00:00')"
        );
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
        $this->db->execute("DELETE FROM op_refunds WHERE merchant_id = 99998");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 99998");
        $this->db->execute("DELETE FROM op_merchants WHERE id = 99998");
    }

    public function testRefundListAndFiltering(): void
    {
        // 1. Insert two refunds under merchant 99998
        $this->db->execute(
            "INSERT INTO op_refunds (id, merchant_id, transaction_id, uuid, amount, reason, status, created_at)
             VALUES (999981, 99998, 999981, 'refund-uuid-1', 100.00, 'Faulty product', 'completed', '2026-06-16 11:00:00')"
        );
        $this->db->execute(
            "INSERT INTO op_refunds (id, merchant_id, transaction_id, uuid, amount, reason, status, created_at)
             VALUES (999982, 99998, 999982, 'refund-uuid-2', 50.00, 'Customer requested', 'pending', '2026-06-17 13:00:00')"
        );

        // 2. Query all refunds without filters
        $req = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/refunds'
        ]);
        $req->setAttribute('merchant_id', 99998);

        $res = $this->controller->index($req);
        $this->assertSame(200, $res->getStatusCode());

        $body = json_decode($res->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertCount(2, $body['data']);
        $this->assertSame('refund-uuid-2', $body['data'][0]['uuid']); // Order by created_at DESC
        $this->assertSame('refund-uuid-1', $body['data'][1]['uuid']);
        $this->assertSame('OP-TRX-TEST-2', $body['data'][0]['trx_id']);
        $this->assertSame('OP-TRX-TEST-1', $body['data'][1]['trx_id']);
        $this->assertSame('BKASH-GW-2', $body['data'][0]['gateway_trx_id']);
        $this->assertSame('BKASH-GW-1', $body['data'][1]['gateway_trx_id']);

        // Check metadata
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(25, $body['meta']['per_page']);
        $this->assertSame(2, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['total_pages']);

        // 3. Filter by status
        $reqStatus = new Request(['status' => 'completed'], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/refunds'
        ]);
        $reqStatus->setAttribute('merchant_id', 99998);

        $resStatus = $this->controller->index($reqStatus);
        $bodyStatus = json_decode($resStatus->getBody(), true);
        $this->assertCount(1, $bodyStatus['data']);
        $this->assertSame('refund-uuid-1', $bodyStatus['data'][0]['uuid']);
        $this->assertSame('completed', $bodyStatus['data'][0]['status']);

        // 4. Filter by trx_id
        $reqTrx = new Request(['trx_id' => 'OP-TRX-TEST-2'], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/refunds'
        ]);
        $reqTrx->setAttribute('merchant_id', 99998);

        $resTrx = $this->controller->index($reqTrx);
        $bodyTrx = json_decode($resTrx->getBody(), true);
        $this->assertCount(1, $bodyTrx['data']);
        $this->assertSame('refund-uuid-2', $bodyTrx['data'][0]['uuid']);

        // 5. Filter by date range (from/to)
        $reqDate = new Request(['from' => '2026-06-17', 'to' => '2026-06-17'], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/refunds'
        ]);
        $reqDate->setAttribute('merchant_id', 99998);

        $resDate = $this->controller->index($reqDate);
        $bodyDate = json_decode($resDate->getBody(), true);
        $this->assertCount(1, $bodyDate['data']);
        $this->assertSame('refund-uuid-2', $bodyDate['data'][0]['uuid']);
    }
}
