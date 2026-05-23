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
 * Class SmsVerificationJob
 *
 * Enterprise cron job executing SMS transaction matching logic for companion device integrations.
 * Scopes pending parsed SMS notifications from `op_sms_parsed` by brand context and attempts to map them
 * atomically to pending transaction instances in `op_transactions` by either transaction ID or transacted amount.
 *
 * Fires system hooks:
 * - mobile.sms.matched: Dispatched when a parsed SMS record is successfully linked to an open pending transaction.
 *
 * @package OwnPay\Cron
 */
final class SmsVerificationJob
{
    /**
     * @var SmsParsedRepository Repository managing parsed SMS records from mobile companion devices.
     */
    private SmsParsedRepository $smsParsed;

    /**
     * @var TransactionRepository Repository managing gateway transactions.
     */
    private TransactionRepository $transactions;
    private TransactionService $transactionService;
    private LedgerService $ledgerService;
    private EventManager $events;

    /**
     * @var Database The database connection instance.
     */
    private Database $db;
    private Logger $logger;

    /**
     * SmsVerificationJob constructor.
     *
     * @param SmsParsedRepository   $smsParsed    Repository managing parsed SMS records from mobile companion devices.
     * @param TransactionRepository $transactions Repository managing gateway transactions.
     * @param EventManager          $events       The enterprise event hook and action dispatcher.
     * @param Database              $db           The database connection instance.
     */
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
     * Runs the matching execution sequence for pending SMS records.
     *
     * Filters distinct brands with pending SMS, queries unmatched records using forTenant,
     * attempts exact transaction ID matching or fallback gateway/amount matching, and fires trigger actions.
     *
     * @return array{matched: int, failed: int, total: int} Matching execution results matrix.
     */
    public function run(): array
    {
        // Query distinct brand identifiers having unresolved pending SMS entries.
        $merchants = $this->db->fetchAll(
            "SELECT DISTINCT merchant_id FROM op_sms_parsed WHERE match_status = 'pending'"
        );

        $matched = 0;
        $failed = 0;
        $total = 0;

        foreach ($merchants as $row) {
            if (!isset($row['merchant_id']) || !is_scalar($row['merchant_id'])) {
                continue;
            }
            $mid = (int) $row['merchant_id'];
            $unmatched = $this->smsParsed->forTenant($mid)->getUnmatched(100);
            $total += count($unmatched);

            foreach ($unmatched as $sms) {
                if (!isset($sms['merchant_id']) || !is_scalar($sms['merchant_id'])) {
                    $failed++;
                    continue;
                }
                $merchantId = (int) $sms['merchant_id'];
                $trxId = isset($sms['trx_id']) && is_scalar($sms['trx_id']) ? (string) $sms['trx_id'] : null;
                $amount = isset($sms['amount']) && is_scalar($sms['amount']) ? (string) $sms['amount'] : null;

                if ($trxId === null && $amount === null) {
                    $failed++;
                    continue;
                }

                // Attempt to resolve the transaction record utilizing the direct gateway transaction identifier.
                $transaction = null;
                if ($trxId !== null) {
                    $transaction = $this->transactions->forTenant($merchantId)->findByTrxId($trxId);
                }

                // Fallback: Resolve transaction record matching by exact transacted amount and gateway provider.
                if ($transaction === null && $amount !== null) {
                    $gatewaySlug = isset($sms['gateway_slug']) && is_scalar($sms['gateway_slug']) ? (string) $sms['gateway_slug'] : null;
                    // Lookup pending transaction matching merchant context parameters without specific tenant repository scoping.
                    $transaction = $this->transactions->findPendingMatch($merchantId, $amount, $gatewaySlug ?? '');
                }

                if ($transaction !== null && isset($transaction['status']) && $transaction['status'] === 'pending') {
                    if (!isset($sms['id']) || !is_scalar($sms['id']) ||
                        !isset($transaction['id']) || !is_scalar($transaction['id']) ||
                        !isset($transaction['amount']) || !is_scalar($transaction['amount']) ||
                        !isset($transaction['currency']) || !is_scalar($transaction['currency'])) {
                        $failed++;
                        continue;
                    }
                    $smsId = (int) $sms['id'];
                    $transactionId = (int) $transaction['id'];
                    $txAmount = (string) $transaction['amount'];
                    $txFee = isset($transaction['fee']) && is_scalar($transaction['fee']) ? (string) $transaction['fee'] : '0.00';
                    $txCurrency = (string) $transaction['currency'];

                    try {
                        $this->db->transaction(function () use ($smsId, $transactionId, $merchantId, $txAmount, $txFee, $txCurrency) {
                            $this->smsParsed->forTenant($merchantId)
                                ->linkToTransaction($smsId, $transactionId);

                            // AUD-008: Complete transaction state + post ledger entries
                            $this->transactionService->complete($transactionId, $merchantId);
                            $this->ledgerService->recordPaymentReceived(
                                $merchantId,
                                $transactionId,
                                $txAmount,
                                $txFee,
                                $txCurrency
                            );
                        });

                        $this->events->doAction('mobile.sms.matched', $sms, $transaction);
                        $matched++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->logger->error(
                            "Failed to process matched SMS: sms_id={$smsId} transaction_id={$transactionId} error={$e->getMessage()}"
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
