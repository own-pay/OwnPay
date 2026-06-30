<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\System\Logger;

/**
 * Class QueueWorkerJob
 *
 * Enterprise queue processor running as a cron worker.
 * Dequeues and dispatches background tasks registered in the `op_job_queue` table using
 * configurable type-handlers, transaction concurrency management, and retry backoff mechanics.
 *
 * @package OwnPay\Cron
 */
final class QueueWorkerJob
{
    /**
     * @var \OwnPay\Core\Database The database connection instance.
     */
    private \OwnPay\Core\Database $db;

    /**
     * @var Logger Logger for queue activity, errors, and backoff warnings.
     */
    private Logger $logger;

    /**
     * Set of handler closures mapped by their respective job types.
     *
     * @var array<string, callable>
     */
    private array $handlers = [];

    /**
     * The maximum number of retry attempts allowed before marking a job permanently failed.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * QueueWorkerJob constructor.
     *
     * @param \OwnPay\Core\Database $db     The database connection instance.
     * @param Logger|null          $logger Optional logger service instance.
     */
    public function __construct(\OwnPay\Core\Database $db, ?Logger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? new Logger('queue');
    }

    /**
     * Registers a callback handler for a specific job type.
     *
     * @param string   $type    The string key naming the job type.
     * @param callable $handler Callable executing the target task logic.
     * @return void
     */
    public function registerHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Processes a batch of eligible queued jobs from the database.
     *
     * Selects pending tasks, marks them as processing with atomic validation,
     * routes them to their registered type-handlers, and updates execution metrics or schedules retries.
     *
     * @param int $batchSize Maximum number of jobs to fetch in this batch.
     * @return array{processed: int, failed: int, total: int} Status counts of processed, failed, and total checked jobs.
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
            if (!isset($job['id']) || !is_scalar($job['id']) ||
                !isset($job['type']) || !is_string($job['type']) ||
                !isset($job['payload']) || !is_string($job['payload'])) {
                continue;
            }
            $jobId = (int) $job['id'];
            $type = $job['type'];
            $payloadRaw = $job['payload'];

            $affected = $this->db->update(
                "UPDATE op_job_queue SET status = 'processing', started_at = NOW() WHERE id = :id AND status = 'pending'",
                ['id' => $jobId]
            );
            if ($affected === 0) {
                continue; // Another worker already claimed this job
            }

            $payload = json_decode($payloadRaw, true);
            $payload = is_array($payload) ? $payload : [];

            if (!isset($this->handlers[$type])) {
                $this->logger->warning("No handler for job type: {$type}");
                $this->db->update(
                    "UPDATE op_job_queue SET status = 'failed', error = 'No handler registered' WHERE id = :id",
                    ['id' => $jobId]
                );
                $failed++;
                continue;
            }

            try {
                ($this->handlers[$type])($payload);

                $this->db->update(
                    "UPDATE op_job_queue SET status = 'completed', completed_at = NOW() WHERE id = :id",
                    ['id' => $jobId]
                );
                $processed++;

            } catch (\Throwable $e) {
                $attemptsVal = $job['attempts'] ?? null;
                $attempts = (is_scalar($attemptsVal) ? (int) $attemptsVal : 0) + 1;
                $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

                // Compute exponential backoff time interval to delay retrying this failed queue execution.
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
                        'id'     => $jobId,
                    ]
                );

                $this->logger->error("Job failed: {$type}", ['error' => $e->getMessage(), 'attempts' => $attempts]);
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'total' => count($jobs)];
    }
}
