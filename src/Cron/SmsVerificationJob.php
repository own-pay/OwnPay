<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\Brand\BrandContext;
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
     * @var BrandContext Resolves the platform-owner id used to detect all-brands devices.
     */
    private BrandContext $brands;

    /**
     * SmsVerificationJob constructor.
     *
     * @param SmsParsedRepository   $smsParsed    Repository managing parsed SMS records from mobile companion devices.
     * @param TransactionRepository $transactions Repository managing gateway transactions.
     * @param EventManager          $events       The enterprise event hook and action dispatcher.
     * @param Database              $db           The database connection instance.
     * @param BrandContext          $brands       Resolves the platform-owner id used to detect all-brands devices.
     */
    public function __construct(
        SmsParsedRepository $smsParsed,
        TransactionRepository $transactions,
        TransactionService $transactionService,
        LedgerService $ledgerService,
        EventManager $events,
        Database $db,
        Logger $logger,
        BrandContext $brands
    ) {
        $this->smsParsed = $smsParsed;
        $this->transactions = $transactions;
        $this->transactionService = $transactionService;
        $this->ledgerService = $ledgerService;
        $this->events = $events;
        $this->db = $db;
        $this->logger = $logger;
        $this->brands = $brands;
    }

    /**
     * Runs the matching execution sequence for pending SMS records.
     *
     * For each brand with pending SMS: a brand-scoped device's SMS is matched strictly within
     * its own brand (no cross-brand leakage). An all-brands device's SMS (stored under the
     * platform-owner id) is matched globally across every brand and then re-attributed to the
     * brand that owns the matched transaction. Cross-brand amount matching is money-safe — it
     * relies on findPendingMatchGlobal, which refuses ambiguous matches.
     *
     * @return array{matched: int, failed: int, total: int} Matching execution results matrix.
     */
    public function run(): array
    {
        // The platform-owner id identifies "all-brands" devices: an SMS captured by a device
        // paired from the All-Brands view is stored under this id and is matched globally; a
        // device paired to a specific brand stays strictly scoped to that brand.
        $platformId = $this->brands->getPlatformId();

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

            // Only SMS from an all-brands device (stored under the platform id) may match across
            // brands. Everything else stays hard-scoped to its own brand.
            $isAllBrands = ($platformId > 0 && $mid === $platformId);

            foreach ($unmatched as $sms) {
                if (!isset($sms['merchant_id']) || !is_scalar($sms['merchant_id'])) {
                    $failed++;
                    continue;
                }
                $smsMerchantId = (int) $sms['merchant_id'];
                $trxId = isset($sms['trx_id']) && is_scalar($sms['trx_id']) ? (string) $sms['trx_id'] : null;
                $amount = isset($sms['amount']) && is_scalar($sms['amount']) ? (string) $sms['amount'] : null;

                if ($trxId === null && $amount === null) {
                    $failed++;
                    continue;
                }

                // Tier 1: resolve by the MFS provider transaction id. It is unique, so an
                // all-brands device may safely search across every brand.
                $transaction = null;
                if ($trxId !== null) {
                    $transaction = $isAllBrands
                        ? $this->transactions->forAllTenants()->findByProviderTrxId($trxId)
                        : $this->transactions->forTenant($smsMerchantId)->findByProviderTrxId($trxId);
                }

                // Tier 2: fall back to exact transacted amount + gateway provider.
                if ($transaction === null && $amount !== null) {
                    $gatewaySlug = isset($sms['gateway_slug']) && is_scalar($sms['gateway_slug']) ? (string) $sms['gateway_slug'] : null;
                    $receivedAt = isset($sms['received_at']) && is_scalar($sms['received_at']) ? (string) $sms['received_at'] : null;

                    if ($isAllBrands) {
                        // A global amount match is only sound with a time window: findPendingMatchGlobal
                        // then refuses ambiguous matches (the same amount pending in more than one brand).
                        // Without received_at it cannot enforce that single-brand guard, so we refuse the
                        // match rather than risk crediting the wrong brand.
                        if ($receivedAt !== null) {
                            $transaction = $this->transactions->findPendingMatchGlobal($amount, $gatewaySlug ?? '', $receivedAt);
                        }
                    } else {
                        $transaction = $this->transactions->findPendingMatch($smsMerchantId, $amount, $gatewaySlug ?? '', $receivedAt);
                    }
                }

                if ($transaction !== null && isset($transaction['status']) && $transaction['status'] === 'pending') {
                    if (!isset($sms['id']) || !is_scalar($sms['id']) ||
                        !isset($transaction['id']) || !is_scalar($transaction['id']) ||
                        !isset($transaction['amount']) || !is_scalar($transaction['amount']) ||
                        !isset($transaction['currency']) || !is_scalar($transaction['currency']) ||
                        !isset($transaction['merchant_id']) || !is_scalar($transaction['merchant_id'])) {
                        $failed++;
                        continue;
                    }
                    $smsId = (int) $sms['id'];
                    $transactionId = (int) $transaction['id'];
                    // The brand that actually owns the matched transaction. For a brand-scoped SMS this
                    // equals $smsMerchantId; for an all-brands SMS it is the resolved owning brand.
                    $resolvedBrand = (int) $transaction['merchant_id'];
                    $txAmount = (string) $transaction['amount'];
                    $txFee = isset($transaction['fee']) && is_scalar($transaction['fee']) ? (string) $transaction['fee'] : '0.00';
                    $txCurrency = (string) $transaction['currency'];
                    $needsRebind = ($resolvedBrand !== $smsMerchantId);

                    try {
                        $this->db->transaction(function () use ($smsId, $transactionId, $resolvedBrand, $needsRebind, $txAmount, $txFee, $txCurrency) {
                            if ($needsRebind) {
                                // All-brands device: re-attribute the SMS to the brand it matched.
                                $this->smsParsed->rebindToBrand($smsId, $transactionId, $resolvedBrand);
                            } else {
                                $this->smsParsed->forTenant($resolvedBrand)
                                    ->linkToTransaction($smsId, $transactionId);
                            }

                            // AUD-008: Complete transaction state + post ledger entries under the owning brand.
                            $this->transactionService->complete($transactionId, $resolvedBrand);
                            $this->ledgerService->recordPaymentReceived(
                                $resolvedBrand,
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
