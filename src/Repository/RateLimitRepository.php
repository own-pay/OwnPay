<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_rate_limits — sliding window rate limiting.
 *
 * BUG-19 FIX: Completely rewritten to match the database schema.
 * Schema uses a counter model: key_name, hits, window_start, expires_at
 * (NOT the per-row hit model the old code used with rate_key + hit_at).
 */
final class RateLimitRepository extends BaseRepository
{
    protected string $table = 'op_rate_limits';

    /**
     * Record a hit for the given key and return the current hit count.
     * Uses atomic INSERT ON DUPLICATE KEY UPDATE for concurrency safety.
     */
    public function hit(string $key, int $windowSec = 60): int
    {
        $now = time();
        $expires = $now + $windowSec;
        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (key_name, hits, window_start, expires_at)
                 VALUES (:k, 1, :ws, :exp)
                 ON DUPLICATE KEY UPDATE
                    hits = IF(expires_at > :now2, hits + 1, 1),
                    window_start = IF(expires_at > :now3, window_start, :ws2),
                    expires_at = IF(expires_at > :now4, expires_at, :exp2)",
                [
                    'k' => $key, 'ws' => $now, 'exp' => $expires,
                    'now2' => $now, 'now3' => $now, 'ws2' => $now,
                    'now4' => $now, 'exp2' => $expires,
                ]
            );
            $row = $this->db->fetchOne(
                "SELECT hits FROM {$this->table} WHERE key_name = :k AND expires_at > :now",
                ['k' => $key, 'now' => $now]
            );
            return (int) ($row['hits'] ?? 0);
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Get remaining hits before rate limit is reached.
     */
    public function remaining(string $key, int $limit, int $windowSec = 60): int
    {
        $now = time();
        try {
            $row = $this->db->fetchOne(
                "SELECT hits FROM {$this->table} WHERE key_name = :k AND expires_at > :now",
                ['k' => $key, 'now' => $now]
            );
            $count = (int) ($row['hits'] ?? 0);
            return max(0, $limit - $count);
        } catch (\PDOException $e) {
            return $limit;
        }
    }

    /**
     * Purge expired rate limit entries.
     */
    public function purgeExpired(int $olderThanSec = 300): int
    {
        $cutoff = time() - $olderThanSec;
        try {
            $stmt = $this->db->execute(
                "DELETE FROM {$this->table} WHERE expires_at < :cutoff",
                ['cutoff' => $cutoff]
            );
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }
}
