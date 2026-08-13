<?php
declare(strict_types=1);

namespace OwnPay\Queue;

use Ramsey\Uuid\Uuid;

/**
 * Class RedisQueue
 *
 * Redis-based implementation of the QueueInterface, designed for high-performance VPS or
 * dedicated environments. Operates via Redis lists and sorted sets to manage pending, delayed,
 * active, and failed jobs.
 *
 * @package OwnPay\Queue
 */
final class RedisQueue implements QueueInterface
{
    /**
     * Maximum number of processing attempts allowed before a job is permanently
     * dead-lettered. Matches QueueWorkerJob::MAX_ATTEMPTS so the queue driver
     * and the database-backed worker agree on the retry ceiling.
     */
    private const int MAX_ATTEMPTS = 3;

    /**
     * @var \Redis The Redis client connection handler instance.
     */
    private \Redis $redis;

    /**
     * @var string The key namespace prefix.
     */
    private string $prefix;

    /**
     * RedisQueue constructor.
     *
     * Connects to the Redis daemon and selects database DB 1 for queue operation namespaces.
     *
     * @param string $host The Redis server host. Defaults to '127.0.0.1'.
     * @param int $port The Redis server port. Defaults to 6379.
     * @param string $prefix The global queue prefix namespace. Defaults to 'op:queue:'.
     * @throws \RuntimeException If connecting to the Redis host fails.
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6379, string $prefix = 'op:queue:')
    {
        $this->prefix = $prefix;
        $this->redis = new \Redis();

        if (!$this->redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException("Cannot connect to Redis at {$host}:{$port}");
        }

        $this->redis->select(1);
    }

    /**
     * Pushes a new job message payload onto the specified queue.
     *
     * @param string $queue The target queue name.
     * @param string $handler The fully qualified class name of the job handler.
     * @param array<string, mixed> $payload The contextual data payload for the job.
     * @param int $delay The execution delay in seconds. Defaults to 0.
     * @return string The generated UUID identifier for the job.
     */
    public function push(string $queue, string $handler, array $payload = [], int $delay = 0): string
    {
        $jobId = Uuid::uuid4()->toString();

        $job = json_encode([
            'id'           => $jobId,
            'queue'        => $queue,
            'handler'      => $handler,
            'payload'      => $payload,
            'attempts'     => 0,
            'created_at'   => time(),
            'error'        => null,
        ], JSON_UNESCAPED_UNICODE);

        if ($delay > 0) {
            $this->redis->zAdd(
                $this->prefix . $queue . ':delayed',
                time() + $delay,
                $job
            );
        } else {
            $this->redis->lPush($this->prefix . $queue, $job);
        }

        return $jobId;
    }

    /**
     * Extracts and retrieves the next available job message from the specified queue.
     *
     * Migrates available delayed jobs to the ready list, pops the first ready job,
     * and maps it to the processing registry hash to ensure worker concurrency safety.
     *
     * @param string $queue The queue name. Defaults to 'default'.
     * @return array<string, mixed>|null The job data array, or null if no jobs are available.
     */
    public function pop(string $queue = 'default'): ?array
    {
        $this->migrateDelayed($queue);

        $raw = $this->redis->rPop($this->prefix . $queue);

        if ($raw === false || $raw === null /** @phpstan-ignore identical.alwaysFalse */) {
            return null;
        }

        $job = json_decode((string) $raw, true);
        if (!is_array($job)) {
            return null;
        }

        $attempts = $job['attempts'] ?? 0;
        $job['attempts'] = (is_numeric($attempts) ? (int) $attempts : 0) + 1;

        $jobId = is_string($job['id'] ?? null) ? $job['id'] : '';

        $this->redis->hSet(
            $this->prefix . 'processing',
            $jobId,
            (string) json_encode($job, JSON_UNESCAPED_UNICODE)
        );

        return $job;
    }

    /**
     * Marks a job as completed and deletes its record from the active processing hash registry.
     *
     * @param string $jobId The UUID identifier of the job.
     * @return void
     */
    public function complete(string $jobId): void
    {
        $this->redis->hDel($this->prefix . 'processing', $jobId);
    }

    /**
     * Marks a job as failed, registering the error trace and moving the record to the failed list.
     *
     * @param string $jobId The UUID identifier of the job.
     * @param string $error The failure reason or stack trace details.
     * @return void
     */
    public function fail(string $jobId, string $error): void
    {
        $raw = $this->redis->hGet($this->prefix . 'processing', $jobId);

        if ($raw !== false) {
            $job = json_decode((string) $raw, true);
            if (is_array($job)) {
                $job['error'] = $error;
                $job['failed_at'] = time();
                $this->redis->lPush(
                    $this->prefix . 'failed',
                    json_encode($job, JSON_UNESCAPED_UNICODE)
                );
            }
        }

        $this->redis->hDel($this->prefix . 'processing', $jobId);
    }

    /**
     * Re-pushes a failed job back onto its original queue for reprocessing.
     *
     * Scans the failed list to find the matching job record, removes it from the list,
     * and either re-queues it (when under MAX_ATTEMPTS) or moves it to the dead-letter
     * list (when the attempt budget is exhausted). Constructing the retried job record
     * inline — rather than delegating to push() — preserves the attempt counter so
     * that handlers which always throw cannot trigger an infinite retry storm.
     *
     * @param string $jobId The UUID identifier of the job.
     * @param int $delay The delay interval in seconds before the retried job is made active. Defaults to 60.
     * @return void
     */
    public function retry(string $jobId, int $delay = 60): void
    {
        $failedKey = $this->prefix . 'failed';
        $deadKey = $this->prefix . 'dead';
        $length = $this->redis->lLen($failedKey);

        for ($i = 0; $i < $length; $i++) {
            $raw = $this->redis->lIndex($failedKey, $i);
            if ($raw === false) {
                continue;
            }

            $job = json_decode((string) $raw, true);
            if (is_array($job) && ($job['id'] ?? '') === $jobId) {
                $this->redis->lRem($failedKey, (string) $raw, 1);

                $attempts = is_numeric($job['attempts'] ?? null) ? (int) $job['attempts'] : 0;

                // Dead-letter jobs that have exhausted MAX_ATTEMPTS. We must
                // NOT re-queue them, otherwise a handler that always throws
                // causes an infinite retry storm via repeated retry() calls.
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $job['dead_at'] = time();
                    $job['dead_reason'] = 'exceeded max attempts (' . self::MAX_ATTEMPTS . ')';
                    $this->redis->lPush(
                        $deadKey,
                        (string) json_encode($job, JSON_UNESCAPED_UNICODE)
                    );
                    return;
                }

                // Construct the retried job record inline with attempts
                // incremented by 1. We deliberately do NOT call push() here —
                // push() resets attempts to 0, which would defeat the
                // MAX_ATTEMPTS cap and reintroduce the infinite-retry bug.
                $queueName = is_string($job['queue'] ?? null) ? $job['queue'] : 'default';
                $job['attempts'] = $attempts + 1;
                $job['error'] = null;
                $job['retried_at'] = time();
                $payload = (string) json_encode($job, JSON_UNESCAPED_UNICODE);

                if ($delay > 0) {
                    $this->redis->zAdd(
                        $this->prefix . $queueName . ':delayed',
                        time() + $delay,
                        $payload
                    );
                } else {
                    $this->redis->lPush($this->prefix . $queueName, $payload);
                }
                return;
            }
        }
    }

    /**
     * Lists dead-lettered jobs that have exhausted MAX_ATTEMPTS for admin inspection.
     *
     * @param int $limit Maximum records to return.
     * @return array<int, array<string, mixed>> List of dead-lettered job records.
     */
    public function deadList(int $limit = 50): array
    {
        $raw = $this->redis->lRange($this->prefix . 'dead', 0, max(0, $limit - 1));
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $job = json_decode($item, true);
            if (is_array($job)) {
                $out[] = $job;
            }
        }
        return $out;
    }

    /**
     * Retrieves the aggregate count of pending and delayed jobs currently in the specified queue.
     *
     * @param string $queue The target queue name. Defaults to 'default'.
     * @return int The total number of pending and delayed jobs.
     */
    public function size(string $queue = 'default'): int
    {
        $ready = $this->redis->lLen($this->prefix . $queue);
        $delayed = $this->redis->zCard($this->prefix . $queue . ':delayed');
        return (int) $ready + (int) $delayed;
    }

    /**
     * Deletes all job messages (including pending and delayed) from the specified queue.
     *
     * @param string $queue The target queue name. Defaults to 'default'.
     * @return void
     */
    public function clear(string $queue = 'default'): void
    {
        $this->redis->del($this->prefix . $queue);
        $this->redis->del($this->prefix . $queue . ':delayed');
    }

    /**
     * Identifies delayed jobs whose activation timestamp has passed and migrates them to the ready list.
     *
     * @param string $queue The queue name.
     * @return void
     */
    private function migrateDelayed(string $queue): void
    {
        $delayedKey = $this->prefix . $queue . ':delayed';
        $readyKey = $this->prefix . $queue;

        $jobs = $this->redis->zRangeByScore($delayedKey, '-inf', (string) time());

        if (!is_array($jobs) || count($jobs) === 0) {
            return;
        }

        foreach ($jobs as $job) {
            if ($this->redis->zRem($delayedKey, $job) > 0) {
                $this->redis->lPush($readyKey, $job);
            }
        }
    }

    /**
     * Retrieves the underlying Redis client handler instance.
     *
     * @return \Redis The active Redis connection handler.
     */
    public function redis(): \Redis
    {
        return $this->redis;
    }
}
