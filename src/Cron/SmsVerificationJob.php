<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\Payment\LedgerService;
use OwnPay\Service\System\Logger;

/**
 * SMS verification job — matches parsed SMS to pending transactions.
 *
 * Fires: mobile.sms.matched
 */
final class SmsVerificationJob
{
    private SmsParsedRepository $smsParsed;
    private TransactionRepository $transactions;
    private TransactionService $transactionService;
    private LedgerService $ledgerService;
    private EventManager $events;
    private Database $db;
    private Logger $logger;

    public function __construct(
        SmsParsedRepository $smsParsed,
        TransactionRepository $transactions,
        TransactionService $transactionService,
        LedgerService $ledgerService,
        EventManager $events,
        Database $db,
        Logger $logger
    ) {
        $this->smsParsed = $smsParsed;
        $this->transactions = $transactions;
        $this->transactionService = $transactionService;
        $this->ledgerService = $ledgerService;
        $this->events = $events;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Run matching cycle — find unmatched SMS and try to link to transactions.
     */
    public function run(): array
    {
        // D2: Query distinct merchant_ids with pending SMS first
        $merchants = $this->db->fetchAll(
            "SELECT DISTINCT merchant_id FROM op_sms_parsed WHERE match_status = 'pending'"
        );

        $matched = 0;
        $failed = 0;
        $total = 0;

        foreach ($merchants as $row) {
            $mid = (int) $row['merchant_id'];
            $unmatched = $this->smsParsed->forTenant($mid)->getUnmatched(100);
            $total += count($unmatched);

            foreach ($unmatched as $sms) {
                $merchantId = (int) $sms['merchant_id'];
                $trxId = $sms['trx_id'] ?? null;       // A3: was parsed_trx_id
                $amount = $sms['amount'] ?? null;       // A3: was parsed_amount

                if ($trxId === null && $amount === null) {
                    $failed++;
                    continue;
                }

                // Try match by TRX ID first
                $transaction = null;
                if ($trxId !== null) {
                    $transaction = $this->transactions->forTenant($merchantId)->findByTrxId($trxId);
                }

                // Fallback: match by amount + gateway
                if ($transaction === null && $amount !== null) {
                    $gatewaySlug = $sms['gateway_slug'] ?? null;
                    // D1: findPendingMatch takes (merchantId, amount, gatewaySlug) — no tenant scope needed
                    $transaction = $this->transactions->findPendingMatch($merchantId, (string) $amount, (string) ($gatewaySlug ?? ''));
                }

                if ($transaction !== null && $transaction['status'] === 'pending') {
                    try {
                        $this->db->transaction(function () use ($sms, $transaction, $merchantId) {
                            $this->smsParsed->forTenant($merchantId)
                                ->linkToTransaction((int) $sms['id'], (int) $transaction['id']);

                            // AUD-008: Complete transaction state + post ledger entries
                            $this->transactionService->complete((int) $transaction['id'], $merchantId);
                            $this->ledgerService->recordPaymentReceived(
                                $merchantId,
                                (int) $transaction['id'],
                                $transaction['amount'],
                                $transaction['fee'] ?? '0.00',
                                $transaction['currency']
                            );
                        });

                        $this->events->doAction('mobile.sms.matched', $sms, $transaction);
                        $matched++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->logger->error(
                            "Failed to process matched SMS: sms_id={$sms['id']} transaction_id={$transaction['id']} error={$e->getMessage()}"
                        );
                    }
                } else {
                    $failed++;
                }
            }
        }

        return ['matched' => $matched, 'failed' => $failed, 'total' => $total];
    }
}
