<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for the sliding window rate limiting system (`op_rate_limits` table).
 *
 * Utilizes a counter model structure (key_name, hits, window_start, expires_at) instead of individual hit logs.
 * Provides atomic hit registration and validation checks to protect endpoints from abusive requests.
 *
 * @package OwnPay\Repository
 */
final class RateLimitRepository extends BaseRepository
{
    /**
     * @var string Database table name.
     */
    protected string $table = 'op_rate_limits';

    /**
     * Records a hit against a rate limiting key and returns the updated hit count.
     *
     * Utilizes an atomic INSERT ON DUPLICATE KEY UPDATE query to handle concurrent requests safely.
     *
     * @param string $key Unique key name identifying the rate limit bucket (e.g. IP + endpoint).
     * @param int $windowSec The duration of the rate limit window in seconds (defaults to 60).
     * @return int The current count of hits within the valid window.
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
            $hitsVal = $row['hits'] ?? 0;
            return is_scalar($hitsVal) ? (int) $hitsVal : 0;
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Calculates the remaining hits available before reaching the rate limit threshold.
     *
     * @param string $key Unique rate limit key.
     * @param int $limit Total allowable hits per window.
     * @param int $windowSec Rate limit window in seconds.
     * @return int The remaining number of hits available (zero if exceeded).
     */
    public function remaining(string $key, int $limit, int $windowSec = 60): int
    {
        $now = time();
        try {
            $row = $this->db->fetchOne(
                "SELECT hits FROM {$this->table} WHERE key_name = :k AND expires_at > :now",
                ['k' => $key, 'now' => $now]
            );
            $hitsVal = $row['hits'] ?? 0;
            $count = is_scalar($hitsVal) ? (int) $hitsVal : 0;
            return max(0, $limit - $count);
        } catch (\PDOException $e) {
            return $limit;
        }
    }

    /**
     * Purges expired rate limit records from the database.
     *
     * @param int $olderThanSec Threshold age in seconds for expired entries (defaults to 300).
     * @return int Total number of purged rate limit rows.
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

