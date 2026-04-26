<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

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
final class RateLimitRepository
{
    use TenantScope;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Record a request hit and return the current window count.
     *
     * @param string $key       Rate limit key (e.g. "api_key:123")
     * @param int    $windowSec Window size in seconds
     * @return int Current hit count within the window
     */
    public function hit(string $key, int $windowSec = 60): int
    {
        $pdo = $this->db->getPdo();
        $windowStart = date('Y-m-d H:i:s', time() - $windowSec);

        try {
            // Insert the hit
            $stmt = $pdo->prepare("
                INSERT INTO op_rate_limits (rate_key, hit_at)
                VALUES (:rk, NOW(6))
            ");
            $stmt->execute([':rk' => $key]);

            // Count hits in current window
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM op_rate_limits
                WHERE rate_key = :rk AND hit_at >= :ws
            ");
            $countStmt->execute([':rk' => $key, ':ws' => $windowStart]);

            return (int) $countStmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table might not exist — degrade gracefully
            error_log("[RateLimit] DB error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get remaining hits in the current window.
     */
    public function remaining(string $key, int $limit, int $windowSec = 60): int
    {
        $pdo = $this->db->getPdo();
        $windowStart = date('Y-m-d H:i:s', time() - $windowSec);

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM op_rate_limits
                WHERE rate_key = :rk AND hit_at >= :ws
            ");
            $stmt->execute([':rk' => $key, ':ws' => $windowStart]);
            $count = (int) $stmt->fetchColumn();

            return max(0, $limit - $count);
        } catch (\PDOException $e) {
            return $limit; // Degrade: allow all
        }
    }

    /**
     * Purge expired entries (housekeeping).
     * Call this periodically (e.g. via cron).
     */
    public function purgeExpired(int $olderThanSec = 300): int
    {
        $pdo = $this->db->getPdo();
        $cutoff = date('Y-m-d H:i:s', time() - $olderThanSec);

        try {
            $stmt = $pdo->prepare("DELETE FROM op_rate_limits WHERE hit_at < :cutoff");
            $stmt->execute([':cutoff' => $cutoff]);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }
}
