<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Controller\Api\PaymentController;
use OwnPay\Service\Notification\WebhookDispatcher;

final class PaymentCurrencyResolutionTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private PaymentController $paymentController;
    private WebhookDispatcher $webhookDispatcher;

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
        $this->webhookDispatcher = $this->container->get(WebhookDispatcher::class);

        $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 99995");
        $this->db->execute("DELETE FROM op_payment_intents WHERE merchant_id = 99995");
        $this->db->execute("DELETE FROM op_merchants WHERE id = 99995");

        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (99995, 'test-merchant-uuid-99995', 'Currency Resolution Merchant', 'curr-resol-test', 'curr-resol-test@test.com', 'active', '{}')"
        );

        // Create a USD payment intent
        $this->db->execute(
            "INSERT INTO op_payment_intents (id, merchant_id, uuid, token, amount, currency, status, expires_at, created_at)
             VALUES (999955, 99995, '01eb81ef-2479-4bfb-96d6-146ac41813d5', 'test-token-999955', 100.00, 'USD', 'completed', NOW() + INTERVAL 1 HOUR, NOW())"
        );

        // Create a BDT transaction (conversion occurred: 100 USD -> 12000 BDT)
        // With fee = 240 BDT (so proportionally scaled to USD, it should be 2.00 USD)
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, payment_intent_id, gateway_trx_id, gateway_slug, amount, fee, net_amount, currency, status, created_at)
             VALUES (999951, 99995, 'tx-uuid-999951', 'OP-TESTTRXCURR', 999955, 'GATEWAY12345', 'bkash', 12000.00, 240.00, 11760.00, 'BDT', 'completed', NOW())"
        );
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_transactions WHERE merchant_id = 99995");
            $this->db->execute("DELETE FROM op_payment_intents WHERE merchant_id = 99995");
            $this->db->execute("DELETE FROM op_merchants WHERE id = 99995");
        }
        parent::tearDown();
    }

    public function testPaymentControllerResolution(): void
    {
        $req = new Request();
        $req->setRouteParams(['payment_id' => '01eb81ef-2479-4bfb-96d6-146ac41813d5']);
        $req->setAttribute('merchant_id', 99995);

        $res = $this->paymentController->show($req);
        $this->assertSame(200, $res->getStatusCode());

        $body = json_decode($res->getBody(), true);
        $this->assertTrue($body['success']);
        
        // Assert that currency is resolved back to USD, amount back to 100.00, and fee scaled down to 2.00
        $this->assertEquals('100.00', $body['data']['amount']);
        $this->assertSame('USD', $body['data']['currency']);
        $this->assertEquals('2.00', $body['data']['fee']);
    }

    public function testWebhookDispatcherPayloadResolution(): void
    {
        $txn = $this->db->fetchOne("SELECT * FROM op_transactions WHERE id = 999951");
        $this->assertNotNull($txn);

        $payload = $this->webhookDispatcher->buildPayload('payment.completed', $txn);

        // Assert that currency is resolved back to USD, amount back to 100.00, and fee scaled down to 2.00
        $this->assertEquals('100.00', $payload['amount']);
        $this->assertSame('USD', $payload['currency']);
        $this->assertEquals('2.00', $payload['fee']);
    }
}
