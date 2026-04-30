<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\System\Logger;

/**
 * Queue worker job — processes async job queue.
 *
 * Jobs are stored in op_job_queue with type, payload, status, attempts.
 */
final class QueueWorkerJob
{
    private \OwnPay\Core\Database $db;
    private Logger $logger;

    /** @var array<string, callable> Job handlers keyed by type */
    private array $handlers = [];

    private const MAX_ATTEMPTS = 3;

    public function __construct(\OwnPay\Core\Database $db, ?Logger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? new Logger('queue');
    }

    /**
     * Register job handler.
     */
    public function registerHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Process queued jobs (batch).
     */
    public function run(int $batchSize = 20): array
    {
        $jobs = $this->db->fetchAll(
            "SELECT * FROM op_job_queue
             WHERE status = 'pending'
               AND attempts < :max
               AND (available_at IS NULL OR available_at <= NOW())
             ORDER BY priority DESC, created_at ASC
             LIMIT {$batchSize}",
            ['max' => self::MAX_ATTEMPTS]
        );

        $processed = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            // Lock job
            $this->db->update(
                "UPDATE op_job_queue SET status = 'processing', started_at = NOW() WHERE id = :id AND status = 'pending'",
                ['id' => $job['id']]
            );

            $type = $job['type'] ?? '';
            $payload = json_decode($job['payload'] ?? '{}', true) ?: [];

            if (!isset($this->handlers[$type])) {
                $this->logger->warning("No handler for job type: {$type}");
                $this->db->update(
                    "UPDATE op_job_queue SET status = 'failed', error = 'No handler registered' WHERE id = :id",
                    ['id' => $job['id']]
                );
                $failed++;
                continue;
            }

            try {
                ($this->handlers[$type])($payload);

                $this->db->update(
                    "UPDATE op_job_queue SET status = 'completed', completed_at = NOW() WHERE id = :id",
                    ['id' => $job['id']]
                );
                $processed++;

            } catch (\Throwable $e) {
                $attempts = (int) ($job['attempts'] ?? 0) + 1;
                $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

                // Exponential backoff for retry
                $backoffSeconds = $attempts * $attempts * 60; // 1m, 4m, 9m
                $this->db->update(
                    "UPDATE op_job_queue SET status = :status, attempts = :att, error = :err,
                     available_at = DATE_ADD(NOW(), INTERVAL :back SECOND)
                     WHERE id = :id",
                    [
                        'status' => $status,
                        'att'    => $attempts,
                        'err'    => substr($e->getMessage(), 0, 500),
                        'back'   => $backoffSeconds,
                        'id'     => $job['id'],
                    ]
                );

                $this->logger->error("Job failed: {$type}", ['error' => $e->getMessage(), 'attempts' => $attempts]);
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'total' => count($jobs)];
    }
}
