<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\GatewayApiService;
use OwnPay\Service\Payment\RefundService;
use OwnPay\Service\Payment\LedgerService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\Payment\FeeService;
use OwnPay\Repository\LedgerRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\RefundRepository;
use OwnPay\Repository\GatewayRepository;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\FeeRuleRepository;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayBridge;

final class FinancialLeakageAuditTest extends IntegrationTestCase
{
    private Database $db;
    private EventManager $events;
    private LedgerRepository $ledgerRepo;
    private TransactionRepository $transactionRepo;
    private RefundRepository $refundRepo;
    private GatewayRepository $gatewayRepo;
    private AuditLogRepository $auditRepo;
    private SettingsRepository $settingsRepo;
    private FeeRuleRepository $feeRuleRepo;

    private LedgerService $ledgerService;
    private TransactionService $transactionService;
    private FeeService $feeService;
    private GatewayApiService $gatewayApiService;
    private RefundService $refundService;

    private GatewayBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            return;
        }

        $this->db = Database::getInstance();
        $this->db->execute("SET SESSION innodb_lock_wait_timeout = 2");

        $this->db->execute("DELETE FROM op_ledger_entries");
        $this->db->execute("DELETE FROM op_ledger_transactions");
        $this->db->execute("DELETE FROM op_ledger_accounts");
        $this->db->execute("DELETE FROM op_refunds");
        $this->db->execute("DELETE FROM op_transactions");
        $this->db->execute("DELETE FROM op_payment_intents");

        $this->events = new EventManager();
        $this->ledgerRepo = new LedgerRepository($this->db);
        $this->transactionRepo = new TransactionRepository($this->db);
        $this->refundRepo = new RefundRepository($this->db);
        $this->gatewayRepo = new GatewayRepository($this->db);
        $this->auditRepo = new AuditLogRepository($this->db);
        $this->settingsRepo = new SettingsRepository($this->db);
        $this->feeRuleRepo = new FeeRuleRepository($this->db);

        $this->ledgerService = new LedgerService($this->ledgerRepo, $this->events, $this->transactionRepo);
        $this->transactionService = new TransactionService($this->transactionRepo, $this->events, $this->auditRepo);
        $this->feeService = new FeeService($this->events, $this->settingsRepo, $this->feeRuleRepo);

        $configsRepo = new GatewayConfigRepository($this->db);
        $encryptor = new FieldEncryptor('test-encryption-key-32-chars-long!');
        $this->bridge = new GatewayBridge($configsRepo, $encryptor, $this->events, $this->settingsRepo);

        $adapter = $this->createStub(GatewayAdapterInterface::class);
        $adapter->method('slug')->willReturn('stripe');
        $adapter->method('initiate')->willReturn(['success' => true, 'redirect_url' => 'https://stripe.com/checkout']);
        $adapter->method('supportedCurrencies')->willReturn(['BDT', 'USD']);
        $adapter->method('verify')->willReturn(['success' => true, 'gateway_trx_id' => 'GW_TRX_123', 'amount' => '100.00']);
        $adapter->method('refund')->willReturn(['success' => true]);

        $this->bridge->registerAdapter($adapter);

        $this->gatewayApiService = new GatewayApiService(
            $this->bridge,
            $this->gatewayRepo,
            $this->transactionService,
            $this->feeService,
            $this->ledgerService
        );

        $this->refundService = new RefundService(
            $this->refundRepo,
            $this->transactionRepo,
            $this->bridge,
            $this->ledgerService
        );

        $merchant = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = 1 LIMIT 1");
        if ($merchant === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (1, 'merchant-uuid-1', 'Test Merchant', 'test-merchant', 'test@example.com', 'active', '{}')"
            );
        }

        $gateway = $this->db->fetchOne("SELECT * FROM op_gateways WHERE id = 1 LIMIT 1");
        if ($gateway === null) {
            $this->db->execute(
                "INSERT INTO op_gateways (id, slug, name, type, is_builtin, status)
                 VALUES (1, 'stripe', 'Stripe Card', 'api', 1, 'active')"
            );
        }
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_ledger_entries");
            $this->db->execute("DELETE FROM op_ledger_transactions");
            $this->db->execute("DELETE FROM op_ledger_accounts");
            $this->db->execute("DELETE FROM op_refunds");
            $this->db->execute("DELETE FROM op_transactions");
            $this->db->execute("DELETE FROM op_payment_intents");
        }
        parent::tearDown();
    }

    public function testGatewayCallbackConcurrencyPrevention(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1001, 1, 'tx-uuid-1', 'TRX1001', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'pending', 1001)"
        );

        $res1 = $this->gatewayApiService->handleCallback(1, 'stripe', ['trx_id' => 'TRX1001']);
        $this->assertTrue($res1['success']);

        $res2 = $this->gatewayApiService->handleCallback(1, 'stripe', ['trx_id' => 'TRX1001']);
        $this->assertFalse($res2['success']);
        $this->assertSame('Transaction not found or already processed', $res2['error']);

        $cashAcc = $this->ledgerRepo->findOrCreateAccount('CASH', 'asset', 'BDT', 1);
        $payableAcc = $this->ledgerRepo->findOrCreateAccount('MERCHANT_PAYABLE', 'liability', 'BDT', 1);
        $feeAcc = $this->ledgerRepo->findOrCreateAccount('PLATFORM_FEE_REVENUE', 'revenue', 'BDT', 1);

        $this->assertSame('100.00', bcadd($cashAcc['balance'], '0', 2));
        $this->assertSame('95.00', bcadd($payableAcc['balance'], '0', 2));
        $this->assertSame('5.00', bcadd($feeAcc['balance'], '0', 2));
    }

    public function testCallbackWithMismatchedAmountIsRejected(): void
    {
        $forge = $this->createStub(GatewayAdapterInterface::class);
        $forge->method('slug')->willReturn('forgegw');
        $forge->method('supportedCurrencies')->willReturn(['BDT']);
        $forge->method('verify')->willReturn(['success' => true, 'gateway_trx_id' => 'GW_FORGE', 'amount' => '1.00']);
        $this->bridge->registerAdapter($forge);

        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (2002, 1, 'tx-uuid-2', 'TRX2002', 'forgegw', 100.00, 5.00, 95.00, 'BDT', 'pending', 2002)"
        );

        $res = $this->gatewayApiService->handleCallback(1, 'forgegw', ['trx_id' => 'TRX2002', 'amount' => '1.00']);

        $this->assertFalse($res['success'], 'Mismatched-amount callback must not succeed.');
        $this->assertSame('Transaction amount mismatch', $res['error'] ?? null);

        $txn = $this->db->fetchOne("SELECT status FROM op_transactions WHERE id = 2002");
        $this->assertSame('pending', $txn['status'] ?? null);
        $ledgerCount = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM op_ledger_transactions");
        $this->assertSame(0, $ledgerCount, 'No ledger postings may occur for a rejected callback.');
    }

    public function testRefundServiceRejectsExcessRefunds(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1002, 1, 'tx-uuid-2', 'TRX1002', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed', 1002)"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund amount cannot exceed transaction amount');

        $this->refundService->create(1, [
            'transaction_id' => 1002,
            'amount' => '150.00',
            'reason' => 'Excessive Refund Attempt'
        ]);
    }

    public function testRefundServiceAllowsPartialRefundsAndRejectsExcess(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1003, 1, 'tx-uuid-3', 'TRX1003', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed', 1003)"
        );

        $this->ledgerService->recordPaymentReceived(1, 1003, '100.00', '5.00', 'BDT');

        $ref1 = $this->refundService->create(1, [
            'transaction_id' => 1003,
            'amount' => '40.00',
            'reason' => 'First partial refund'
        ]);
        $this->assertSame('completed', $ref1['status']);

        $ref2 = $this->refundService->create(1, [
            'transaction_id' => 1003,
            'amount' => '50.00',
            'reason' => 'Second partial refund'
        ]);
        $this->assertSame('completed', $ref2['status']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund amount cannot exceed transaction amount');

        $this->refundService->create(1, [
            'transaction_id' => 1003,
            'amount' => '20.00',
            'reason' => 'Third partial refund'
        ]);
    }

    public function testPaymentIntentCheckoutConcurrencyPrevention(): void
    {
        $this->db->execute(
            "INSERT INTO op_payment_intents (id, merchant_id, uuid, token, customer_id, amount, currency, status, expires_at)
             VALUES (2001, 1, 'intent-uuid-1', 'test-intent-token', NULL, 100.00, 'BDT', 'pending', DATE_ADD(NOW(), INTERVAL 1 HOUR))"
        );

        $_ENV['HMAC_KEY'] = 'test-hmac-key';
        $checkoutHash = hash_hmac('sha256', '100.00|BDT|test-intent-token', 'test-hmac-key');

        $container = new \OwnPay\Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(\OwnPay\Core\Database::class, $this->db);
        \OwnPay\Core\Database::setInstance($this->db);
        $container->instance(\OwnPay\Event\EventManager::class, $this->events);
        $container->instance(\OwnPay\Repository\TransactionRepository::class, $this->transactionRepo);
        $container->instance(\OwnPay\Repository\SettingsRepository::class, $this->settingsRepo);
        $container->instance(\OwnPay\Service\Payment\TransactionService::class, $this->transactionService);
        $container->instance(\OwnPay\Gateway\GatewayBridge::class, $this->bridge);
        $container->instance(\OwnPay\Service\Payment\GatewayApiService::class, $this->gatewayApiService);
        $container->instance(\OwnPay\Service\Payment\LedgerService::class, $this->ledgerService);
        $container->instance(\OwnPay\Repository\LedgerRepository::class, $this->ledgerRepo);
        $container->instance(\OwnPay\Service\Payment\FeeService::class, $this->feeService);

        $controller = $container->get(\OwnPay\Controller\Checkout\PaymentIntentCheckoutController::class);

        $req1 = new \OwnPay\Http\Request(
            query: [],
            post: [
                'checkout_hash' => $checkoutHash,
                'gateway' => 'stripe',
                'gateway_mode' => 'api'
            ],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/checkout/intent/test-intent-token/pay'
            ]
        );
        $req1->setRouteParams(['token' => 'test-intent-token']);
        $req1->setAttribute('merchant_id', 1);

        $res1 = $controller->pay($req1);
        $this->assertNotNull($res1);

        $countBefore = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM op_transactions WHERE payment_intent_id = 2001");
        $this->assertSame(1, $countBefore);

        $intent = $this->db->fetchOne("SELECT status FROM op_payment_intents WHERE id = 2001");
        $this->assertSame('processing', $intent['status']);

        $req2 = new \OwnPay\Http\Request(
            query: [],
            post: [
                'checkout_hash' => $checkoutHash,
                'gateway' => 'stripe',
                'gateway_mode' => 'api'
            ],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/checkout/intent/test-intent-token/pay',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'
            ]
        );
        $req2->setRouteParams(['token' => 'test-intent-token']);
        $req2->setAttribute('merchant_id', 1);

        $res2 = $controller->pay($req2);

        $this->assertSame(409, $res2->getStatusCode());

        $countAfter = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM op_transactions WHERE payment_intent_id = 2001");
        $this->assertSame(1, $countAfter);
    }
}
