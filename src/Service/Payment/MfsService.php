<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Service\Sms\SmsParserService;
use OwnPay\Support\DateHelper;

/**
 * Orchestrates Mobile Financial Services (MFS) SMS─Transaction matching workflows.
 *
 * Directs ingestion of incoming SMS text messages from carrier gateways or Android companion devices,
 * triggers parsing via the SmsParserService, logs parsed entries, and attempts auto-matching
 * against pending manual transactions.
 */
final class MfsService
{
    /**
     * @var SmsParsedRepository Repository for parsed SMS metadata storage.
     */
    private SmsParsedRepository $smsParsed;

    /**
     * @var TransactionService Service layer handling core payments and transaction updates.
     */
    private TransactionService $transactions;

    /**
     * @var SmsParserService Service parsing merchant carrier receipt messages.
     */
    private SmsParserService $parser;

    /**
     * MfsService constructor.
     *
     * @param SmsParsedRepository $smsParsed Repository logging parsed messages.
     * @param TransactionService $transactions Service managing transaction state.
     * @param SmsParserService $parser SMS parsing orchestrator.
     */
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
     * Processes an incoming MFS receipt SMS, parses its tokens, and attempts a transaction match.
     *
     * Persists the raw and parsed data in the database. If a transaction reference (TrxID)
     * matches an active pending transaction in the merchant's domain, automatically links them.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param string $sender The SMS sender source number or shortcode (e.g. "bKash", "Nagad").
     * @param string $body The raw textual content of the received SMS.
     * @param string $deviceId The unique device identifier of the receiving companion device.
     * @return array{matched: bool, transaction_id?: int|string, sms_id: int|string, parsed?: array<string, mixed>} matching outcome.
     */
    public function processIncomingSms(int $merchantId, string $sender, string $body, string $deviceId): array
    {
        $parsed = $this->parser->parse($sender, $body, $merchantId);

        $smsId = $this->smsParsed->forTenant($merchantId)->createScoped([
            'device_id'    => $deviceId,
            'sender'       => $sender,
            'body'         => $body,
            'amount'       => $parsed['parsed_amount'] ?? $parsed['amount'] ?? null,
            'trx_id'       => $parsed['parsed_trx_id'] ?? $parsed['trx_id'] ?? null,
            'gateway_slug' => $parsed['gateway_slug'] ?? null,
            'parser_type'  => $parsed['parse_method'] ?? $parsed['parser_type'] ?? 'none',
            'match_status' => 'pending',
            'raw_data'     => json_encode($parsed),
            'received_at'  => DateHelper::now(),
        ]);

        $trxId = $parsed['parsed_trx_id'] ?? $parsed['trx_id'] ?? null;

        // Try auto-match to pending transaction
        if (!empty($trxId)) {
            $transaction = $this->transactions->findByTrxId($merchantId, $trxId);
            if ($transaction !== null && $transaction['status'] === 'pending') {
                $this->smsParsed->forTenant($merchantId)->linkToTransaction((int) $smsId, (int) $transaction['id']);
                return ['matched' => true, 'transaction_id' => $transaction['id'], 'sms_id' => $smsId];
            }
        }

        $result = ['matched' => false, 'sms_id' => $smsId];
        if ($parsed !== null) {
            $result['parsed'] = $parsed;
        }

        return $result;
    }
}
