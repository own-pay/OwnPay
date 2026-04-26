<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Event\EventManager;
use OwnPay\Service\CrudService;
use OwnPay\Service\CurrencyService;
use OwnPay\Service\DateTimeService;

/**
 * SmsVerificationJob — match pending transactions against approved SMS data.
 *
 * Iterates over every pending transaction with a sender_key, looks for a
 * matching approved SMS record, validates payment tolerance against the
 * brand's configured threshold, and on success:
 *   - Marks the SMS record as 'used'
 *   - Marks the transaction as 'completed' with the SMS sender number
 *   - Enqueues a webhook delivery (if webhook_url is configured)
 *
 * Fires `transactions.updated` once per run with the batch of completed
 * transactions. Previously embedded in index.php (~100 lines).
 */
final class SmsVerificationJob
{
    private string $dbPrefix;

    public function __construct(?string $dbPrefix = null)
    {
        $this->dbPrefix = $dbPrefix ?? ($_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_');
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $pending = CrudService::select(
            $this->dbPrefix . 'transaction',
            'WHERE status = :status AND sender_key NOT IN (:null_dash, :null_empty) ORDER BY 1 DESC',
            '* FROM',
            [':status' => 'pending', ':null_dash' => '--', ':null_empty' => '']
        );

        $allTransactions = [];
        $completed = 0;
        $checked = 0;

        if ($pending['status'] !== true || empty($pending['response'])) {
            return ['pending_checked' => 0, 'completed' => 0];
        }

        foreach ($pending['response'] as $row) {
            $checked++;

            $smsRes = CrudService::select(
                $this->dbPrefix . 'sms_data',
                'WHERE sender_key = :sender_key AND type = :type AND trx_id = :trx_id AND status = :status',
                '* FROM',
                [
                    ':sender_key' => $row['sender_key'],
                    ':type' => $row['sender_type'],
                    ':trx_id' => $row['trx_id'],
                    ':status' => 'approved',
                ]
            );

            if ($smsRes['status'] !== true || empty($smsRes['response'])) {
                continue;
            }

            $brandRes = CrudService::select(
                $this->dbPrefix . 'brands',
                'WHERE brand_id = :brand_id',
                '* FROM',
                [':brand_id' => $row['brand_id']]
            );

            if ($brandRes['status'] !== true || empty($brandRes['response'])) {
                continue;
            }

            $smsRow = $smsRes['response'][0];
            $brandRow = $brandRes['response'][0];

            $toleranceOk = CurrencyService::verifyPaymentTolerance(
                (string) $row['local_net_amount'],
                (string) $smsRow['amount'],
                (string) $brandRow['payment_tolerance']
            );

            if (!$toleranceOk) {
                continue;
            }

            $now = DateTimeService::getCurrentDatetime('Y-m-d H:i:s');

            // Mark SMS as used
            CrudService::update(
                $this->dbPrefix . 'sms_data',
                ['status', 'updated_date'],
                ['used', $now],
                'id = :where_id',
                [':where_id' => $smsRow['id']]
            );

            // Mark transaction as completed
            CrudService::update(
                $this->dbPrefix . 'transaction',
                ['status', 'sender', 'trx_id', 'updated_date'],
                ['completed', $smsRow['number'], $row['trx_id'], $now],
                'id = :where_id',
                [':where_id' => $row['id']]
            );

            $completed++;

            $metadata = json_decode($row['metadata'], true) ?: [];

            $gatewayRes = CrudService::select(
                $this->dbPrefix . 'gateways',
                'WHERE brand_id = :brand_id AND gateway_id = :gateway_id',
                '* FROM',
                [':brand_id' => $brandRow['brand_id'], ':gateway_id' => $row['gateway_id']]
            );
            $gateway = $gatewayRes['response'][0]['display'] ?? '';

            $customerInfo = json_decode($row['customer_info'], true) ?: [];
            $net = money_sub(money_add($row['amount'], $row['processing_fee']), $row['discount_amount']);
            $timezone = empty($brandRow['timezone']) ? 'Asia/Dhaka' : $brandRow['timezone'];

            $txnPayload = [
                'op_id' => $row['ref'],
                'full_name' => $customerInfo['name'] ?? 'N/A',
                'email_address' => $customerInfo['email'] ?? 'N/A',
                'mobile_number' => $customerInfo['mobile'] ?? 'N/A',
                'gateway' => $gateway,
                'amount' => money_round($row['amount']),
                'fee' => money_round($row['processing_fee']),
                'discount_amount' => money_round($row['discount_amount']),
                'total' => money_round($net),
                'local_net_amount' => money_round($row['local_net_amount']),
                'currency' => $row['currency'],
                'local_currency' => $row['local_currency'],
                'metadata' => $metadata,
                'sender' => $smsRow['number'],
                'transaction_id' => $row['trx_id'],
                'status' => $row['status'],
                'date' => convertUTCtoUserTZ($row['created_date'], $timezone, 'M d, Y h:i A'),
            ];

            $allTransactions[] = $txnPayload;

            if (!empty($row['webhook_url'])) {
                $payload = json_encode($txnPayload, JSON_UNESCAPED_UNICODE);

                CrudService::insert(
                    $this->dbPrefix . 'webhook_log',
                    ['ref', 'brand_id', 'payload', 'url', 'created_date', 'updated_date'],
                    [$row['ref'], $row['brand_id'], $payload, $row['webhook_url'], $now, $now]
                );
            }
        }

        if (!empty($allTransactions)) {
            EventManager::getInstance()->doAction('transactions.updated', $allTransactions);
        }

        return [
            'pending_checked' => $checked,
            'completed' => $completed,
        ];
    }
}
