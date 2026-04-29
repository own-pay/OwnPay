<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\System\CrudService;
use OwnPay\Service\Payment\MfsService;

/**
 * BalanceVerificationJob — reconcile active balance-verification records.
 *
 * Iterates over every active `balance_verification` row and calls
 * {@see MfsService::reconcileByLongestChain()} to match the SMS chain
 * against recorded transactions.
 *
 * Previously embedded in index.php (~6 lines plus surrounding glue).
 */
final class BalanceVerificationJob
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
        $active = CrudService::select(
            $this->dbPrefix . 'balance_verification',
            'WHERE status = :status',
            '* FROM',
            [':status' => 'active']
        );

        if ($active['status'] !== true || empty($active['response'])) {
            return ['reconciled' => 0];
        }

        $reconciled = 0;

        foreach ($active['response'] as $row) {
            MfsService::reconcileByLongestChain(
                (string) $row['device_id'],
                (string) $row['sender_key'],
                (string) $row['type']
            );
            $reconciled++;
        }

        return ['reconciled' => $reconciled];
    }
}
