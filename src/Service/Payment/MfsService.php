<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Service\Sms\SmsParserService;
use OwnPay\Support\DateHelper;

/**
 * MFS (Mobile Financial Service) — orchestrates SMS─Transaction matching.
 */
final class MfsService
{
    private SmsParsedRepository $smsParsed;
    private TransactionService $transactions;
    private SmsParserService $parser;

    public function __construct(
        SmsParsedRepository $smsParsed,
        TransactionService $transactions,
        SmsParserService $parser
    ) {
        $this->smsParsed = $smsParsed;
        $this->transactions = $transactions;
        $this->parser = $parser;
    }

    /**
     * Process incoming SMS and attempt auto-match.
     */
    public function processIncomingSms(int $merchantId, string $sender, string $body, string $deviceId): array
    {
        $parsed = $this->parser->parse($sender, $body, $merchantId);

        $smsId = $this->smsParsed->forTenant($merchantId)->createScoped([
            'device_id'    => $deviceId,
            'sender'       => $sender,
            'body'         => $body,
            'amount'       => $parsed['amount'] ?? null,
            'trx_id'       => $parsed['trx_id'] ?? null,
            'gateway_slug' => $parsed['gateway_slug'] ?? null,
            'parser_type'  => $parsed['parser_type'] ?? 'none',
            'match_status' => 'pending',
            'raw_data'     => json_encode($parsed),
            'received_at'  => DateHelper::now(),
        ]);

        // Try auto-match to pending transaction
        if (!empty($parsed['trx_id'])) {
            $transaction = $this->transactions->findByTrxId($merchantId, $parsed['trx_id']);
            if ($transaction !== null && $transaction['status'] === 'pending') {
                $this->smsParsed->forTenant($merchantId)->linkToTransaction((int) $smsId, (int) $transaction['id']);
                return ['matched' => true, 'transaction_id' => $transaction['id'], 'sms_id' => $smsId];
            }
        }

        return ['matched' => false, 'sms_id' => $smsId, 'parsed' => $parsed];
    }
}
