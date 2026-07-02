<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\LedgerService;
use OwnPay\Service\Payment\ReconciliationService;
use OwnPay\Repository\LedgerRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Event\EventManager;

class LedgerServiceTest extends IntegrationTestCase
{
    private LedgerService $ledgerService;
    private ReconciliationService $reconciliationService;
    private Database $db;
    private LedgerRepository $ledgerRepo;
    private TransactionRepository $transactionRepo;
    private EventManager $events;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            return;
        }

        $this->db = Database::getInstance();

        $this->db->execute("DELETE FROM op_ledger_entries");
        $this->db->execute("DELETE FROM op_ledger_transactions");
        $this->db->execute("DELETE FROM op_ledger_accounts");
        $this->db->execute("DELETE FROM op_refunds");
        $this->db->execute("DELETE FROM op_transactions");

        $this->events = new EventManager();
        $this->ledgerRepo = new LedgerRepository($this->db);
        $this->transactionRepo = new TransactionRepository($this->db);
        $this->ledgerService = new LedgerService($this->ledgerRepo, $this->events, $this->transactionRepo);
        $this->reconciliationService = new ReconciliationService($this->db, $this->ledgerService);

        $merchant = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = 1 LIMIT 1");
        if ($merchant === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (1, 'merchant-uuid-1', 'Test Merchant', 'test-merchant-1', 'test1@example.com', 'active', '{}')"
            );
        }
        $merchant2 = $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = 2 LIMIT 1");
        if ($merchant2 === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (2, 'merchant-uuid-2', 'Test Merchant 2', 'test-merchant-2', 'test2@example.com', 'active', '{}')"
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
        }
        parent::tearDown();
    }

    public function test3EntryPaymentReceivedBalanced(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1001, 1, 'tx-uuid-1', 'TRX1001', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed', 1001)"
        );

        $this->ledgerService->recordPaymentReceived(1, 1001, '100.00', '5.00', 'BDT');

        $cashAcc = $this->ledgerRepo->findOrCreateAccount('CASH', 'asset', 'BDT', 1);
        $payableAcc = $this->ledgerRepo->findOrCreateAccount('MERCHANT_PAYABLE', 'liability', 'BDT', 1);
        $feeAcc = $this->ledgerRepo->findOrCreateAccount('PLATFORM_FEE_REVENUE', 'revenue', 'BDT', 1);

        $this->assertSame('100.00', bcadd($cashAcc['balance'], '0', 2));
        $this->assertSame('95.00', bcadd($payableAcc['balance'], '0', 2));
        $this->assertSame('5.00', bcadd($feeAcc['balance'], '0', 2));

        $balance = $this->ledgerService->calculateBalance(1, 'BDT');
        $this->assertSame('95.00', bcadd($balance, '0', 2));
    }

    public function testProportionalRefundCalculation(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1002, 1, 'tx-uuid-2', 'TRX1002', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed', 1002)"
        );

        $this->ledgerService->recordPaymentReceived(1, 1002, '100.00', '5.00', 'BDT');

        // Refund 40.00: proportional fee = 40.00 * (5.00 / 100.00) = 2.00, net refund = 38.00
        $this->db->execute(
            "INSERT INTO op_refunds (id, merchant_id, uuid, transaction_id, amount, status)
             VALUES (2001, 1, 'refund-uuid-1', 1002, 40.00, 'completed')"
        );

        $this->ledgerService->recordRefund(1, 2001, 1002, '40.00', 'BDT');

        $cashAcc = $this->ledgerRepo->findOrCreateAccount('CASH', 'asset', 'BDT', 1);
        $payableAcc = $this->ledgerRepo->findOrCreateAccount('MERCHANT_PAYABLE', 'liability', 'BDT', 1);
        $feeAcc = $this->ledgerRepo->findOrCreateAccount('PLATFORM_FEE_REVENUE', 'revenue', 'BDT', 1);

        $this->assertSame('60.00', bcadd($cashAcc['balance'], '0', 2));
        $this->assertSame('57.00', bcadd($payableAcc['balance'], '0', 2));
        $this->assertSame('3.00', bcadd($feeAcc['balance'], '0', 2));
    }

    public function testTenantIsolation(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1003, 1, 'tx-uuid-3', 'TRX1003', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed', 1003)"
        );
        $this->ledgerService->recordPaymentReceived(1, 1003, '100.00', '5.00', 'BDT');

        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1004, 2, 'tx-uuid-4', 'TRX1004', 'stripe', 200.00, 10.00, 190.00, 'BDT', 'completed', 1004)"
        );
        $this->ledgerService->recordPaymentReceived(2, 1004, '200.00', '10.00', 'BDT');

        $bal1 = $this->ledgerService->calculateBalance(1, 'BDT');
        $bal2 = $this->ledgerService->calculateBalance(2, 'BDT');

        $this->assertSame('95.00', bcadd($bal1, '0', 2));
        $this->assertSame('190.00', bcadd($bal2, '0', 2));
    }

    public function testDoublePostingPrevention(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1006, 1, 'tx-uuid-6', 'TRX1006', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed', 1006)"
        );

        $this->ledgerService->recordPaymentReceived(1, 1006, '100.00', '5.00', 'BDT');
        $this->ledgerService->recordPaymentReceived(1, 1006, '100.00', '5.00', 'BDT');

        $cashAcc = $this->ledgerRepo->findOrCreateAccount('CASH', 'asset', 'BDT', 1);
        $payableAcc = $this->ledgerRepo->findOrCreateAccount('MERCHANT_PAYABLE', 'liability', 'BDT', 1);
        $feeAcc = $this->ledgerRepo->findOrCreateAccount('PLATFORM_FEE_REVENUE', 'revenue', 'BDT', 1);

        $this->assertSame('100.00', bcadd($cashAcc['balance'], '0', 2));
        $this->assertSame('95.00', bcadd($payableAcc['balance'], '0', 2));
        $this->assertSame('5.00', bcadd($feeAcc['balance'], '0', 2));
    }

    public function testReconciliationService(): void
    {
        $this->db->execute(
            "INSERT INTO op_transactions (id, merchant_id, uuid, trx_id, gateway_slug, amount, fee, net_amount, currency, status, payment_intent_id)
             VALUES (1005, 1, 'tx-uuid-5', 'TRX1005', 'stripe', 100.00, 5.00, 95.00, 'BDT', 'completed', 1005)"
        );
        $this->ledgerService->recordPaymentReceived(1, 1005, '100.00', '5.00', 'BDT');

        $this->db->execute(
            "INSERT INTO op_refunds (id, merchant_id, uuid, transaction_id, amount, status)
             VALUES (2002, 1, 'refund-uuid-2', 1005, 40.00, 'completed')"
        );
        $this->ledgerService->recordRefund(1, 2002, 1005, '40.00', 'BDT');

        $res = $this->reconciliationService->reconcile(1, 'BDT');

        $this->assertTrue($res['balanced']);
        $this->assertSame('95.00', bcadd($res['transaction_total'], '0', 2));
        $this->assertSame('38.00', bcadd($res['refund_total'], '0', 2));
        $this->assertSame('0.00', bcadd($res['settlement_total'], '0', 2));
        $this->assertSame('57.00', bcadd($res['expected_balance'], '0', 2));
        $this->assertSame('57.00', bcadd($res['ledger_balance'], '0', 2));
        $this->assertSame('0.00', bcadd($res['difference'], '0', 2));
    }
}
