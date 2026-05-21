<?php
declare(strict_types=1);

namespace OwnPay\Queue;

/**
 * Interface QueueInterface
 *
 * Defines the contract for queue drivers within the system. Supported drivers
 * include file-based and Redis-backed queuing architectures.
 *
 * @package OwnPay\Queue
 */
interface QueueInterface
{
    /**
     * Pushes a new job message payload onto the specified queue.
     *
     * @param string $queue Queue name (e.g., 'default', 'webhooks', 'sms').
     * @param string $handler The fully qualified class name of the job handler.
     * @param array<string, mixed> $payload The contextual data payload for the job.
     * @param int $delay The execution delay in seconds. Defaults to 0.
     * @return string The unique identifier generated for the job.
     */
    public function push(string $queue, string $handler, array $payload = [], int $delay = 0): string;

    /**
     * Extracts and retrieves the next available job message from the specified queue.
     *
     * @param string $queue The queue name. Defaults to 'default'.
     * @return array{id: string, queue: string, handler: string, payload: array<string, mixed>, attempts: int, available_at: int, created_at: int, error: string|null, _file?: string}|null The job data array, or null if no jobs are available.
     */
    public function pop(string $queue = 'default'): ?array;

    /**
     * Marks a job as completed and deletes its persistent job file or active registry record.
     *
     * @param string $jobId The unique identifier of the job.
     * @return void
     */
    public function complete(string $jobId): void;

    /**
     * Marks a job as failed, registering the error trace and archiving the record.
     *
     * @param string $jobId The unique identifier of the job.
     * @param string $error The failure reason or stack trace details.
     * @return void
     */
    public function fail(string $jobId, string $error): void;

    /**
     * Re-pushes a failed job back onto its original queue for reprocessing.
     *
     * @param string $jobId The unique identifier of the job.
     * @param int $delay The delay interval in seconds before the retried job is made active. Defaults to 60.
     * @return void
     */
    public function retry(string $jobId, int $delay = 60): void;

    /**
     * Retrieves the count of pending job messages currently in the specified queue.
     *
     * @param string $queue The target queue name. Defaults to 'default'.
     * @return int The number of pending jobs.
     */
    public function size(string $queue = 'default'): int;

    /**
     * Deletes all job messages (including pending and currently processing) from the specified queue.
     *
     * @param string $queue The target queue name. Defaults to 'default'.
     * @return void
     */
    public function clear(string $queue = 'default'): void;
}
