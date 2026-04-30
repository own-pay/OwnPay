<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class LoginAttemptRepository extends BaseRepository
{
    protected string $table = 'op_login_attempts';
    protected array $fillable = ['email', 'ip_address', 'user_agent', 'success'];

    /**
     * Count recent failed attempts (for rate limiting).
     * Per security skill: brute-force protection.
     */
    public function recentFailedCount(string $email, string $ip, int $windowSeconds = 300): int
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE (email = :email OR ip_address = :ip)
             AND success = 0 AND created_at > :since",
            ['email' => $email, 'ip' => $ip, 'since' => $since]
        );
    }

    /**
     * Cleanup old entries (cron job).
     */
    public function cleanOlderThan(int $days = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
        return $this->db->delete(
            "DELETE FROM {$this->table} WHERE created_at < :cutoff",
            ['cutoff' => $cutoff]
        );
    }
}
