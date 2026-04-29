<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\DateTimeService;
use OwnPay\Service\System\EnvironmentService;
use OwnPay\Service\Notification\NotificationService;

/**
 * WebhookRetryJob — retry pending outbound webhook deliveries.
 *
 * Processes up to {@see self::BATCH_SIZE} pending webhook_log rows whose
 * attempt count is still below the configured limit. Each job is sent via
 * {@see NotificationService::sendIPNMulti()} and the row is marked as
 * completed (HTTP 200), canceled (attempts exhausted), or pending (retry
 * next cycle).
 *
 * Previously embedded in index.php (~35 lines).
 */
final class WebhookRetryJob
{
    private const BATCH_SIZE = 15;
    private const SUCCESS_HTTP_CODE = 200;

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
        $limitRaw = EnvironmentService::get('geneal-application-settings-webhook_attempts_limit');
        $limit = empty($limitRaw) ? 1 : (int) $limitRaw;

        $pending = CrudService::select(
            $this->dbPrefix . 'webhook_log',
            'WHERE status = :status AND attempts < :limit ORDER BY id ASC LIMIT ' . self::BATCH_SIZE,
            '* FROM',
            [':status' => 'pending', ':limit' => $limit]
        );

        if ($pending['status'] !== true || empty($pending['response'])) {
            return ['retried' => 0, 'limit' => $limit];
        }

        $now = DateTimeService::getCurrentDatetime('Y-m-d H:i:s');
        $jobs = [];

        foreach ($pending['response'] as $row) {
            CrudService::update(
                $this->dbPrefix . 'webhook_log',
                ['attempts', 'updated_date'],
                [(int) $row['attempts'] + 1, $now],
                'id = :where_id',
                [':where_id' => $row['id']]
            );

            $jobs[] = [
                'id' => $row['id'],
                'url' => $row['url'],
                'payload' => json_decode($row['payload'], true),
                'attempts' => (int) $row['attempts'] + 1,
            ];
        }

        $results = NotificationService::sendIPNMulti($jobs);

        $completed = 0;
        $canceled = 0;
        $stillPending = 0;

        foreach ($jobs as $job) {
            $code = (int) ($results[$job['id']] ?? 0);
            $status = ($code === self::SUCCESS_HTTP_CODE) ? 'completed' : 'pending';

            if ($job['attempts'] >= $limit && $code !== self::SUCCESS_HTTP_CODE) {
                $status = 'canceled';
            }

            CrudService::update(
                $this->dbPrefix . 'webhook_log',
                ['status', 'http_code', 'updated_date'],
                [$status, $code, $now],
                'id = :where_id',
                [':where_id' => $job['id']]
            );

            match ($status) {
                'completed' => $completed++,
                'canceled' => $canceled++,
                default => $stillPending++,
            };
        }

        return [
            'retried' => count($jobs),
            'completed' => $completed,
            'canceled' => $canceled,
            'still_pending' => $stillPending,
            'limit' => $limit,
        ];
    }
}
