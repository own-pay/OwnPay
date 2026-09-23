<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\System\Logger;

/**
 * Server-side auto-verification for manual (MFS / bank transfer) payments.
 *
 * The manual checkout flow records customer-supplied sender/TrxID proof and parks the
 * transaction at `pending_review`; historically only an administrator could complete it.
 * This listener adds a safe automatic path: when the merchant's paired companion device
 * has already captured the matching provider SMS, the submitted TrxID is reconciled
 * against the tenant-scoped SMS inbox and - only on an exact amount match - the
 * transaction is completed through the idempotent {@see TransactionService::complete()}
 * pipeline.
 *
 * Security invariants (a client-supplied transaction_id alone is never trusted):
 * - the lookup is tenant-scoped to the brand that owns the pending transaction;
 * - the provider TrxID must resolve to exactly one row in that brand's inbox;
 * - the SMS amount must match the transaction amount to two decimal places
 *   (`bccomp(..., 2)`, the same comparison the gateway callback applies);
 * - the SMS row is claimed under a row lock and re-checked unmatched so one
 *   notification can never settle two transactions;
 * - the SMS must fall inside the same time window used by {@see \OwnPay\Cron\SmsVerificationJob};
 * - only a non-terminal `pending_review` transaction can be completed.
 *
 * When any check fails the transaction is left untouched at `pending_review` for the
 * existing manual admin review path.
 */
final class ManualPaymentVerificationService
{
    /**
     * Seconds an SMS may predate the transaction and still be matched (anti-replay).
     */
    private const SMS_LOOKBACK_SECONDS = 300;

    /**
     * Seconds an SMS may postdate the transaction and still be matched.
     */
    private const SMS_LOOKAHEAD_SECONDS = 1800;

    /**
     * @var SmsParsedRepository Repository managing parsed SMS records from companion devices.
     */
    private SmsParsedRepository $smsParsed;

    /**
     * @var TransactionRepository Repository managing gateway transactions.
     */
    private TransactionRepository $transactions;

    /**
     * @var TransactionService State service performing the guarded, idempotent completion.
     */
    private TransactionService $transactionService;

    /**
     * @var LedgerService Double-entry ledger writer for the recovered payment.
     */
    private LedgerService $ledger;

    /**
     * @var AuditLogRepository Audit recorder for the automatic settlement decision.
     */
    private AuditLogRepository $audit;

    /**
     * @var EventManager Event dispatcher for the verified action hook.
     */
    private EventManager $events;

    /**
     * @var Database The database connection used to make link + completion atomic.
     */
    private Database $db;

    /**
     * @var Logger Application logger for non-fatal verification failures.
     */
    private Logger $logger;

    /**
     * ManualPaymentVerificationService constructor.
     *
     * @param SmsParsedRepository   $smsParsed          Repository managing parsed SMS records.
     * @param TransactionRepository $transactions       Repository managing gateway transactions.
     * @param TransactionService    $transactionService State service performing guarded completion.
     * @param LedgerService         $ledger             Ledger writer for the payment.
     * @param AuditLogRepository    $audit              Audit trail recorder.
     * @param EventManager          $events             Event dispatcher.
     * @param Database              $db                 Database connection.
     * @param Logger                $logger             Application logger.
     */
    public function __construct(
        SmsParsedRepository $smsParsed,
        TransactionRepository $transactions,
        TransactionService $transactionService,
        LedgerService $ledger,
        AuditLogRepository $audit,
        EventManager $events,
        Database $db,
        Logger $logger
    ) {
        $this->smsParsed = $smsParsed;
        $this->transactions = $transactions;
        $this->transactionService = $transactionService;
        $this->ledger = $ledger;
        $this->audit = $audit;
        $this->events = $events;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Event listener for `checkout.manual_verify.submitted`.
     *
     * Extracts the owning brand, transaction id, and customer-supplied provider TrxID and
     * delegates to {@see attemptVerification()}. Verification is best-effort: any failure
     * leaves the transaction at `pending_review` for manual admin review and never breaks
     * the customer-facing submit flow.
     *
     * @param array<string, mixed> $transaction The pre-update transaction row from the event.
     * @param array<string, mixed> $verifyData  Customer-submitted verification fields.
     * @return void
     */
    public function onManualVerifySubmitted(array $transaction, array $verifyData): void
    {
        $midVal = $transaction['merchant_id'] ?? 0;
        $merchantId = is_scalar($midVal) ? (int) $midVal : 0;
        $txnIdVal = $transaction['id'] ?? 0;
        $transactionId = is_scalar($txnIdVal) ? (int) $txnIdVal : 0;
        $trxIdVal = $verifyData['transaction_id'] ?? '';
        $providerTrxId = is_string($trxIdVal) ? trim($trxIdVal) : '';

        if ($merchantId <= 0 || $transactionId <= 0 || $providerTrxId === '') {
            return;
        }

        try {
            $this->attemptVerification($merchantId, $transactionId, $providerTrxId);
        } catch (\Throwable $e) {
            $this->logger->error(
                "Manual payment auto-verify failed: txn_id={$transactionId} mid={$merchantId} error=" . $e->getMessage()
            );
        }
    }

    /**
     * Attempts to settle a pending manual-review transaction against the brand's SMS inbox.
     *
     * @param int    $merchantId    The brand that owns the transaction.
     * @param int    $transactionId The internal primary identifier of the transaction.
     * @param string $providerTrxId The customer-submitted MFS provider transaction reference.
     * @return bool True when the transaction was verified and completed, false otherwise.
     */
    public function attemptVerification(int $merchantId, int $transactionId, string $providerTrxId): bool
    {
        $trxId = trim($providerTrxId);
        if ($merchantId <= 0 || $transactionId <= 0 || $trxId === '') {
            return false;
        }

        $txnRepo = $this->transactions->forTenant($merchantId);
        $transaction = $txnRepo->findScoped($transactionId);
        if ($transaction === null) {
            return false;
        }

        $statusVal = $transaction['status'] ?? '';
        $status = is_string($statusVal) ? $statusVal : '';
        if ($status !== 'pending_review') {
            return false;
        }

        $expectedAmount = $this->resolveExpectedAmount($transaction);
        if ($expectedAmount === null || !is_numeric($expectedAmount)) {
            return false;
        }

        $createdAtVal = $transaction['created_at'] ?? '';
        $createdAt = is_string($createdAtVal) ? $createdAtVal : '';
        if ($createdAt === '') {
            return false;
        }

        $smsRepo = $this->smsParsed->forTenant($merchantId);
        $candidates = $smsRepo->findUnmatchedByTrxId($trxId, 2);
        if (count($candidates) !== 1) {
            return false;
        }

        $sms = $candidates[0];
        $smsAmountVal = $sms['amount'] ?? null;
        $smsAmount = is_scalar($smsAmountVal) ? (string) $smsAmountVal : '';
        if (!is_numeric($smsAmount) || bccomp($smsAmount, $expectedAmount, 2) !== 0) {
            return false;
        }

        $smsIdVal = $sms['id'] ?? null;
        $smsId = is_scalar($smsIdVal) ? (int) $smsIdVal : 0;
        if ($smsId <= 0) {
            return false;
        }

        if (!$this->isWithinMatchWindow($createdAt, $sms['received_at'] ?? null)) {
            return false;
        }

        $feeVal = $transaction['fee'] ?? '0.00';
        $fee = is_scalar($feeVal) ? (string) $feeVal : '0.00';
        $currencyVal = $transaction['currency'] ?? 'BDT';
        $currency = is_scalar($currencyVal) ? (string) $currencyVal : 'BDT';

        // The ledger must post the transaction's own gross amount (as the gateway callback
        // path does); `converted_amount` is only authoritative for the match check.
        $txnAmountVal = $transaction['amount'] ?? null;
        $ledgerAmount = is_scalar($txnAmountVal) ? (string) $txnAmountVal : '';
        if (!is_numeric($ledgerAmount)) {
            return false;
        }

        try {
            $settled = $this->db->transaction(function () use ($smsRepo, $smsId, $transactionId, $merchantId, $ledgerAmount, $fee, $currency): bool {
                // Serialize concurrent verifiers (double submit, admin approval racing the
                // auto-verifier) on the transaction row so only one path can post the ledger.
                $locked = $this->db->fetchOne(
                    "SELECT status FROM op_transactions WHERE id = :id AND merchant_id = :mid FOR UPDATE",
                    ['id' => $transactionId, 'mid' => $merchantId]
                );
                $lockedStatusVal = $locked['status'] ?? '';
                $lockedStatus = is_string($lockedStatusVal) ? $lockedStatusVal : '';
                if ($lockedStatus !== 'pending_review') {
                    return false;
                }

                // Claim the SMS row under the same lock and re-verify it is still unmatched.
                // Without this, two equal-amount checkouts submitting the same genuine TrxID
                // concurrently could both link (and settle against) the one notification.
                $lockedSms = $this->db->fetchOne(
                    "SELECT id FROM op_sms_parsed
                     WHERE id = :sid AND merchant_id = :smid
                       AND transaction_id IS NULL
                       AND match_status NOT IN ('matched', 'ignored')
                     FOR UPDATE",
                    ['sid' => $smsId, 'smid' => $merchantId]
                );
                if ($lockedSms === null) {
                    return false;
                }

                $smsRepo->linkToTransaction($smsId, $transactionId);
                $this->transactionService->complete($transactionId, $merchantId);
                $this->ledger->recordPaymentReceived($merchantId, $transactionId, $ledgerAmount, $fee, $currency);
                return true;
            });
        } catch (\Throwable $e) {
            $this->logger->error(
                "Manual payment auto-verify settlement failed: txn_id={$transactionId} mid={$merchantId} error=" . $e->getMessage()
            );
            return false;
        }

        if ($settled !== true) {
            return false;
        }

        // Refresh transaction row to reflect completed state for verified event
        $updatedTransaction = $this->transactions->forTenant($merchantId)->findScoped($transactionId);
        $this->events->doAction('checkout.manual_verify.verified', $updatedTransaction ?? $transaction, $sms);

        try {
            $this->audit->record(
                $merchantId,
                null,
                'transaction.manual_verify.auto_completed',
                'transaction',
                $transactionId,
                null,
                ['sms_id' => $smsId, 'provider_trx_id' => $trxId, 'amount' => $ledgerAmount]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                "Manual payment auto-verify audit failed: txn_id={$transactionId} mid={$merchantId} error=" . $e->getMessage()
            );
        }

        return true;
    }

    /**
     * Resolves the amount that must match the recovered SMS amount.
     *
     * Mirrors {@see GatewayApiService::handleCallback()}: when a currency conversion was
     * recorded at checkout time the `converted_amount` metadata value is authoritative,
     * otherwise the transaction's own amount is used.
     *
     * @param array<string, mixed> $transaction The transaction row.
     * @return string|null A numeric amount string, or null when unavailable.
     */
    private function resolveExpectedAmount(array $transaction): ?string
    {
        $amountVal = $transaction['amount'] ?? null;
        $amount = is_scalar($amountVal) ? (string) $amountVal : '';

        $metaRaw = $transaction['metadata'] ?? null;
        if (is_string($metaRaw) && $metaRaw !== '') {
            $meta = json_decode($metaRaw, true);
            if (is_array($meta)
                && isset($meta['converted_amount']) && is_scalar($meta['converted_amount'])
                && (string) $meta['converted_amount'] !== ''
            ) {
                $amount = (string) $meta['converted_amount'];
            }
        }

        if (!is_numeric($amount)) {
            return null;
        }

        return $amount;
    }

    /**
     * Confirms the SMS was captured within the same window the SMS verification cron accepts.
     *
     * This blocks replaying a much older unmatched notification against a freshly created
     * checkout for the same amount.
     *
     * @param string $transactionCreatedAt The transaction creation timestamp.
     * @param mixed  $smsReceivedAt        The candidate SMS received timestamp.
     * @return bool True when the SMS falls inside the accepted window.
     */
    private function isWithinMatchWindow(string $transactionCreatedAt, mixed $smsReceivedAt): bool
    {
        if (!is_string($smsReceivedAt) || $smsReceivedAt === '') {
            return false;
        }

        $txnTs = strtotime($transactionCreatedAt);
        $smsTs = strtotime($smsReceivedAt);
        if ($txnTs === false || $smsTs === false) {
            return false;
        }

        return $smsTs >= ($txnTs - self::SMS_LOOKBACK_SECONDS)
            && $smsTs <= ($txnTs + self::SMS_LOOKAHEAD_SECONDS);
    }
}
