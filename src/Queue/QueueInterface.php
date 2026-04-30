<?php
declare(strict_types=1);

namespace OwnPay\Queue;

/**
 * Queue driver contract.
 *
 * Implementations: FileQueue (shared hosting), RedisQueue (VPS).
 * Jobs are serialized payloads processed by CronJobRunner or Supervisor worker.
 */
interface QueueInterface
{
    /**
     * Push a job onto the queue.
     *
     * @param string $queue   Queue name (e.g., 'default', 'webhooks', 'sms')
     * @param string $handler Fully-qualified class name of the job handler
     * @param array  $payload Job data
     * @param int    $delay   Delay in seconds before job becomes available
     * @return string Job ID
     */
    public function push(string $queue, string $handler, array $payload = [], int $delay = 0): string;

    /**
     * Pop the next available job from a queue.
     *
     * @param string $queue Queue name
     * @return array{id: string, handler: string, payload: array, attempts: int}|null
     */
    public function pop(string $queue = 'default'): ?array;

    /**
     * Mark a job as completed (remove from queue).
     */
    public function complete(string $jobId): void;

    /**
     * Mark a job as failed.
     */
    public function fail(string $jobId, string $error): void;

    /**
     * Re-queue a failed job for retry.
     */
    public function retry(string $jobId, int $delay = 60): void;

    /**
     * Get count of pending jobs in a queue.
     */
    public function size(string $queue = 'default'): int;

    /**
     * Clear all jobs in a queue.
     */
    public function clear(string $queue = 'default'): void;
}
