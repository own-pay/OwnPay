<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * DB-backed sliding window storage for rate limiting.
 *
 * Uses op_rate_limits table. If the table doesn't exist,
 * gracefully degrades (rate limiting disabled).
 *
 * NOTE: TenantScope is declared for interface consistency but is NOT
 * applied in queries because the op_rate_limits table is keyed by
 * rate_key (which already encodes the tenant context, e.g. "api_key:123")
 * and has no merchant_id column.
 */
final class RateLimitRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_rate_limits';

    /**
     * Record a request hit and return the current window count.
     */
    public function hit(string $key, int $windowSec = 60): int
    {
        $windowStart = DateHelper::ago($windowSec);

        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (rate_key, hit_at) VALUES (:rk, NOW(6))",
                ['rk' => $key]
            );

            $row = $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM {$this->table} WHERE rate_key = :rk AND hit_at >= :ws",
                ['rk' => $key, 'ws' => $windowStart]
            );

            return (int) ($row['cnt'] ?? 0);
        } catch (\PDOException $e) {
            // L-02 FIX: Use Logger for rotation + sanitization
            try {
                (new \OwnPay\Service\System\Logger('ratelimit'))->warning('DB error: ' . $e->getMessage());
            } catch (\Throwable) {
                error_log("[RateLimit] DB error: " . $e->getMessage());
            }
            return 0;
        }
    }

    /**
     * Get remaining hits in the current window.
     */
    public function remaining(string $key, int $limit, int $windowSec = 60): int
    {
        $windowStart = DateHelper::ago($windowSec);

        try {
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM {$this->table} WHERE rate_key = :rk AND hit_at >= :ws",
                ['rk' => $key, 'ws' => $windowStart]
            );
            $count = (int) ($row['cnt'] ?? 0);
            return max(0, $limit - $count);
        } catch (\PDOException $e) {
            return $limit;
        }
    }

    /**
     * Purge expired entries (housekeeping).
     */
    public function purgeExpired(int $olderThanSec = 300): int
    {
        $cutoff = DateHelper::ago($olderThanSec);

        try {
            $stmt = $this->db->execute(
                "DELETE FROM {$this->table} WHERE hit_at < :cutoff",
                ['cutoff' => $cutoff]
            );
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }
}

