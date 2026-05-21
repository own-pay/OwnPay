<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\TransactionRepository;

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

    /**
     * @var EventManager The enterprise event hook and action dispatcher.
     */
    private EventManager $events;

    /**
     * @var Database The database connection instance.
     */
    private Database $db;

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
        EventManager $events,
        Database $db
    ) {
        $this->smsParsed = $smsParsed;
        $this->transactions = $transactions;
        $this->events = $events;
        $this->db = $db;
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
            $mid = (int) $row['merchant_id'];
            $unmatched = $this->smsParsed->forTenant($mid)->getUnmatched(100);
            $total += count($unmatched);

            foreach ($unmatched as $sms) {
                $merchantId = (int) $sms['merchant_id'];
                $trxId = $sms['trx_id'] ?? null;       // Extract the raw gateway transaction identifier from the SMS record.
                $amount = $sms['amount'] ?? null;       // Extract the raw transacted amount from the SMS record.

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
                    $gatewaySlug = $sms['gateway_slug'] ?? null;
                    // Lookup pending transaction matching merchant context parameters without specific tenant repository scoping.
                    $transaction = $this->transactions->findPendingMatch($merchantId, (string) $amount, (string) ($gatewaySlug ?? ''));
                }

                if ($transaction !== null && $transaction['status'] === 'pending') {
                    $this->smsParsed->forTenant($merchantId)
                        ->linkToTransaction((int) $sms['id'], (int) $transaction['id']);

                    $this->events->doAction('mobile.sms.matched', $sms, $transaction);
                    $matched++;
                } else {
                    $failed++;
                }
            }
        }

        return ['matched' => $matched, 'failed' => $failed, 'total' => $total];
    }
}
