<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Controller\Api\PaymentController;
use OwnPay\Controller\Api\TransactionController;
use OwnPay\Controller\Api\RefundController;

final class TrxIdLookupApiTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private PaymentController $paymentController;
    private TransactionController $transactionController;
    private RefundController $refundController;

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

        $this->paymentController = $this->container->get(PaymentController::class);
        $this->transactionController = $this->container->get(TransactionController::class);
        $this->refundController = $this->container->get(RefundController::class);

        $this->db->execute("DELETE FROM op_refunds WHERE merchant_id = 99997");
        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 99997");
        $this->db->execute("DELETE FROM op_payment_intents WHERE merchant_id = 99997");
        $this->db->execute("DELETE FROM op_merchants WHERE id = 99997");

        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (99997, 'test-merchant-uuid-99997', 'Trx ID Test Merchant', 'trx-test', 'trx-test@test.com', 'active', '{}')"
        );

        $this->db->execute(
            "INSERT INTO op_payment_intents (id, merchant_id, uuid, token, amount, currency, status, expires_at, created_at)
             VALUES (999975, 99997, '01eb81ef-2479-4bfb-96d6-146ac41813d8', 'test-token-999975', 100.00, 'BDT', 'completed', NOW() + INTERVAL 1 HOUR, NOW())"
        );

        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, payment_intent_id, gateway_trx_id, gateway_slug, amount, fee, net_amount, currency, status, created_at)
             VALUES (999971, 99997, 'tx-uuid-999971', 'OP-TESTTRX123', 999975, 'GATEWAY123', 'bkash', 100.00, 2.00, 98.00, 'BDT', 'completed', NOW())"
        );

        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, payment_intent_id, gateway_trx_id, gateway_slug, amount, fee, net_amount, currency, status, created_at)
             VALUES (999973, 99997, 'tx-uuid-999973', 'OP_TRX_TEST123', NULL, 'GATEWAY456', 'bkash', 100.00, 2.00, 98.00, 'BDT', 'completed', NOW())"
        );

        $this->db->execute(
            "INSERT INTO op_refunds (id, merchant_id, uuid, transaction_id, amount, reason, status, created_at)
             VALUES (999972, 99997, 'ref-uuid-999972', 999971, 50.00, 'Customer request', 'completed', NOW())"
        );
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_refunds WHERE merchant_id = 99997");
            $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 99997");
            $this->db->execute("DELETE FROM op_payment_intents WHERE merchant_id = 99997");
            $this->db->execute("DELETE FROM op_merchants WHERE id = 99997");
        }
        parent::tearDown();
    }

    public function testPaymentLookupByPaymentId(): void
    {
        $req1 = new Request();
        $req1->setRouteParams(['payment_id' => '01eb81ef-2479-4bfb-96d6-146ac41813d8']);
        $req1->setAttribute('merchant_id', 99997);

        $res1 = $this->paymentController->show($req1);
        $this->assertSame(200, $res1->getStatusCode());
        $body1 = json_decode($res1->getBody(), true);
        $this->assertTrue($body1['success']);
        $this->assertSame('OP-TESTTRX123', $body1['data']['trx_id']);
        $this->assertSame('GATEWAY123', $body1['data']['gateway_trx_id']);

        $req2 = new Request();
        $req2->setRouteParams(['payment_id' => 'invalid-uuid']);
        $req2->setAttribute('merchant_id', 99997);

        $res2 = $this->paymentController->show($req2);
        $this->assertSame(422, $res2->getStatusCode());

        $req3 = new Request();
        $req3->setRouteParams(['payment_id' => '00000000-0000-0000-0000-000000000000']);
        $req3->setAttribute('merchant_id', 99997);

        $res3 = $this->paymentController->show($req3);
        $this->assertSame(404, $res3->getStatusCode());
        $body3 = json_decode($res3->getBody(), true);
        $this->assertSame('Payment not found', $body3['errors'][0]['message']);
    }

    public function testTransactionLookupByOwnPayAndGatewayTrxId(): void
    {
        $req1 = new Request();
        $req1->setRouteParams(['trx_id' => 'OP-TESTTRX123']);
        $req1->setAttribute('merchant_id', 99997);

        $res1 = $this->transactionController->show($req1);
        $this->assertSame(200, $res1->getStatusCode());
        $body1 = json_decode($res1->getBody(), true);
        $this->assertTrue($body1['success']);
        $this->assertSame('OP-TESTTRX123', $body1['data']['trx_id']);
        $this->assertSame('GATEWAY123', $body1['data']['gateway_trx_id']);

        $req2 = new Request();
        $req2->setRouteParams(['trx_id' => 'GATEWAY123']);
        $req2->setAttribute('merchant_id', 99997);

        $res2 = $this->transactionController->show($req2);
        $this->assertSame(200, $res2->getStatusCode());
        $body2 = json_decode($res2->getBody(), true);
        $this->assertTrue($body2['success']);
        $this->assertSame('OP-TESTTRX123', $body2['data']['trx_id']);
        $this->assertSame('GATEWAY123', $body2['data']['gateway_trx_id']);

        $req2b = new Request();
        $req2b->setRouteParams(['trx_id' => 'OP_TRX_TEST123']);
        $req2b->setAttribute('merchant_id', 99997);

        $res2b = $this->transactionController->show($req2b);
        $this->assertSame(200, $res2b->getStatusCode());
        $body2b = json_decode($res2b->getBody(), true);
        $this->assertTrue($body2b['success']);
        $this->assertSame('OP_TRX_TEST123', $body2b['data']['trx_id']);
        $this->assertSame('GATEWAY456', $body2b['data']['gateway_trx_id']);

        $req3 = new Request();
        $req3->setRouteParams(['trx_id' => 'OP-UNKNOWN']);
        $req3->setAttribute('merchant_id', 99997);

        $res3 = $this->transactionController->show($req3);
        $this->assertSame(404, $res3->getStatusCode());
        $body3 = json_decode($res3->getBody(), true);
        $this->assertSame('Transaction not found', $body3['errors'][0]['message']);

        $req3b = new Request();
        $req3b->setRouteParams(['trx_id' => 'OP_TRX_UNKNOWN']);
        $req3b->setAttribute('merchant_id', 99997);

        $res3b = $this->transactionController->show($req3b);
        $this->assertSame(404, $res3b->getStatusCode());
        $body3b = json_decode($res3b->getBody(), true);
        $this->assertSame('Transaction not found', $body3b['errors'][0]['message']);

        $req4 = new Request();
        $req4->setRouteParams(['trx_id' => 'UNKNOWN_GW_ID']);
        $req4->setAttribute('merchant_id', 99997);

        $res4 = $this->transactionController->show($req4);
        $this->assertSame(404, $res4->getStatusCode());
        $body4 = json_decode($res4->getBody(), true);
        $this->assertSame(
            'Transaction not found using the gateway transaction ID. It may be an incomplete, pending, or failed payment. Try querying with the OwnPay transaction ID.',
            $body4['errors'][0]['message']
        );
    }

    public function testRefundLookupByOwnPayAndGatewayTrxId(): void
    {
        $req1 = new Request();
        $req1->setRouteParams(['trx_id' => 'OP-TESTTRX123']);
        $req1->setAttribute('merchant_id', 99997);

        $res1 = $this->refundController->show($req1);
        $this->assertSame(200, $res1->getStatusCode());
        $body1 = json_decode($res1->getBody(), true);
        $this->assertTrue($body1['success']);
        $this->assertSame(999971, $body1['data']['transaction_id']);

        $req2 = new Request();
        $req2->setRouteParams(['trx_id' => 'GATEWAY123']);
        $req2->setAttribute('merchant_id', 99997);

        $res2 = $this->refundController->show($req2);
        $this->assertSame(200, $res2->getStatusCode());
        $body2 = json_decode($res2->getBody(), true);
        $this->assertTrue($body2['success']);
        $this->assertSame(999971, $body2['data']['transaction_id']);

        $req2b = new Request();
        $req2b->setRouteParams(['trx_id' => 'OP_TRX_TEST123']);
        $req2b->setAttribute('merchant_id', 99997);

        $res2b = $this->refundController->show($req2b);
        $this->assertSame(404, $res2b->getStatusCode());
        $body2b = json_decode($res2b->getBody(), true);
        $this->assertFalse($body2b['success']);
        $this->assertSame('Refund not found', $body2b['errors'][0]['message']);

        $req3 = new Request();
        $req3->setRouteParams(['trx_id' => 'OP-UNKNOWN']);
        $req3->setAttribute('merchant_id', 99997);

        $res3 = $this->refundController->show($req3);
        $this->assertSame(404, $res3->getStatusCode());
        $body3 = json_decode($res3->getBody(), true);
        $this->assertSame('Transaction not found', $body3['errors'][0]['message']);

        $req3b = new Request();
        $req3b->setRouteParams(['trx_id' => 'OP_TRX_UNKNOWN']);
        $req3b->setAttribute('merchant_id', 99997);

        $res3b = $this->refundController->show($req3b);
        $this->assertSame(404, $res3b->getStatusCode());
        $body3b = json_decode($res3b->getBody(), true);
        $this->assertSame('Transaction not found', $body3b['errors'][0]['message']);

        $req4 = new Request();
        $req4->setRouteParams(['trx_id' => 'UNKNOWN_GW_ID']);
        $req4->setAttribute('merchant_id', 99997);

        $res4 = $this->refundController->show($req4);
        $this->assertSame(404, $res4->getStatusCode());
        $body4 = json_decode($res4->getBody(), true);
        $this->assertSame(
            'Transaction not found using the gateway transaction ID. It may be an incomplete, pending, or failed payment. Try querying with the OwnPay transaction ID.',
            $body4['errors'][0]['message']
        );
    }
}
