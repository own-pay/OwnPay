<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Event\EventManager;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\TransactionRepository;

/**
 * SMS verification job — matches parsed SMS to pending transactions.
 *
 * Fires: mobile.sms.matched
 */
final class SmsVerificationJob
{
    private SmsParsedRepository $smsParsed;
    private TransactionRepository $transactions;
    private EventManager $events;

    public function __construct(
        SmsParsedRepository $smsParsed,
        TransactionRepository $transactions,
        EventManager $events
    ) {
        $this->smsParsed = $smsParsed;
        $this->transactions = $transactions;
        $this->events = $events;
    }

    /**
     * Run matching cycle — find unmatched SMS and try to link to transactions.
     */
    public function run(): array
    {
        $unmatched = $this->smsParsed->getUnmatched(100);
        $matched = 0;
        $failed = 0;

        foreach ($unmatched as $sms) {
            $merchantId = (int) $sms['merchant_id'];
            $trxId = $sms['parsed_trx_id'] ?? null;
            $amount = $sms['parsed_amount'] ?? null;

            if ($trxId === null && $amount === null) {
                $failed++;
                continue;
            }

            // Try match by TRX ID first
            $transaction = null;
            if ($trxId !== null) {
                $transaction = $this->transactions->forTenant($merchantId)->findByTrxId($trxId);
            }

            // Fallback: match by amount + gateway + time window
            if ($transaction === null && $amount !== null) {
                $gatewaySlug = $sms['gateway_slug'] ?? null;
                $receivedAt = $sms['received_at'];
                $transaction = $this->transactions->forTenant($merchantId)
                    ->findPendingMatch($amount, $gatewaySlug, $receivedAt, 300); // 5-min window
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

        return ['matched' => $matched, 'failed' => $failed, 'total' => count($unmatched)];
    }
}
