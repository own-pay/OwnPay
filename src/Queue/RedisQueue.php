<?php
declare(strict_types=1);

namespace OwnPay\Queue;

use Ramsey\Uuid\Uuid;

/**
 * Redis-based queue driver â€” VPS/dedicated with Supervisor worker.
 *
 * Uses Redis lists for O(1) push/pop.
 * Delayed jobs stored in sorted sets, moved to ready queue by worker.
 */
final class RedisQueue implements QueueInterface
{
    private \Redis $redis;
    private string $prefix;

    /**
     * @throws \RuntimeException If connection fails
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6379, string $prefix = 'op:queue:')
    {
        $this->prefix = $prefix;
        $this->redis = new \Redis();

        if (!$this->redis->connect($host, $port, 2.0)) {
            throw new \RuntimeException("Cannot connect to Redis at {$host}:{$port}");
        }

        $this->redis->select(1); // Use DB 1 for queues (DB 0 = cache)
    }

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
            // Delayed: add to sorted set with score = available_at timestamp
            $this->redis->zAdd(
                $this->prefix . $queue . ':delayed',
                time() + $delay,
                $job
            );
        } else {
            // Immediate: push to list
            $this->redis->lPush($this->prefix . $queue, $job);
        }

        return $jobId;
    }

    public function pop(string $queue = 'default'): ?array
    {
        // First, move delayed jobs that are now ready
        $this->migrateDelayed($queue);

        // Pop from list (blocking pop with 1 second timeout)
        $raw = $this->redis->rPop($this->prefix . $queue);

        if ($raw === false || $raw === null) {
            return null;
        }

        $job = json_decode((string) $raw, true);
        if (!is_array($job)) {
            return null;
        }

        $job['attempts'] = ($job['attempts'] ?? 0) + 1;

        // Track in processing set
        $this->redis->hSet(
            $this->prefix . 'processing',
            $job['id'],
            json_encode($job, JSON_UNESCAPED_UNICODE)
        );

        return $job;
    }

    public function complete(string $jobId): void
    {
        $this->redis->hDel($this->prefix . 'processing', $jobId);
    }

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

    public function retry(string $jobId, int $delay = 60): void
    {
        // Search failed list for the job
        $failedKey = $this->prefix . 'failed';
        $length = $this->redis->lLen($failedKey);

        for ($i = 0; $i < $length; $i++) {
            $raw = $this->redis->lIndex($failedKey, $i);
            if ($raw === false) {
                continue;
            }

            $job = json_decode((string) $raw, true);
            if (is_array($job) && ($job['id'] ?? '') === $jobId) {
                // Remove from failed
                $this->redis->lRem($failedKey, (string) $raw, 1);

                // Re-push
                $this->push(
                    $job['queue'] ?? 'default',
                    $job['handler'] ?? '',
                    $job['payload'] ?? [],
                    $delay
                );
                return;
            }
        }
    }

    public function size(string $queue = 'default'): int
    {
        $ready = $this->redis->lLen($this->prefix . $queue);
        $delayed = $this->redis->zCard($this->prefix . $queue . ':delayed');
        return (int) $ready + (int) $delayed;
    }

    public function clear(string $queue = 'default'): void
    {
        $this->redis->del($this->prefix . $queue);
        $this->redis->del($this->prefix . $queue . ':delayed');
    }

    // â”€â”€â”€ Private â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Move delayed jobs whose availability time has passed to the ready queue.
     */
    private function migrateDelayed(string $queue): void
    {
        $delayedKey = $this->prefix . $queue . ':delayed';
        $readyKey = $this->prefix . $queue;

        // Get all delayed jobs with score <= now
        $jobs = $this->redis->zRangeByScore($delayedKey, '-inf', (string) time());

        if (!is_array($jobs) || count($jobs) === 0) {
            return;
        }

        foreach ($jobs as $job) {
            // Atomic: remove from sorted set + push to list
            if ($this->redis->zRem($delayedKey, $job) > 0) {
                $this->redis->lPush($readyKey, $job);
            }
        }
    }

    /**
     * Get underlying Redis instance.
     */
    public function redis(): \Redis
    {
        return $this->redis;
    }
}
