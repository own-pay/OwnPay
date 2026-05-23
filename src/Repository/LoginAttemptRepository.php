<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * Repository layer for login attempt logs (`op_login_attempts` table).
 *
 * Tracks administrative and merchant login attempts for brute-force protection 
 * and audit trails.
 *
 * @package OwnPay\Repository
 */
final class LoginAttemptRepository extends BaseRepository
{
    /**
     * @var string Database table name.
     */
    protected string $table = 'op_login_attempts';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = ['email', 'ip_address', 'user_agent', 'success'];

    /**
     * Counts recent failed login attempts for brute-force rate-limiting.
     *
     * Queries within a rolling time window by either email address or source IP address.
     *
     * @param string $email The email address targeted in the login attempt.
     * @param string $ip The source IP address of the request.
     * @param int $windowSeconds The rolling time window in seconds (defaults to 300 / 5 minutes).
     * @return int Total number of failed attempts detected in the window.
     */
    public function recentFailedCount(string $email, string $ip, int $windowSeconds = 300): int
    {
        $since = DateHelper::ago($windowSeconds);
        $countVal = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE (email = :email OR ip_address = :ip)
             AND success = 0 AND created_at > :since",
            ['email' => $email, 'ip' => $ip, 'since' => $since]
        );
        return is_scalar($countVal) ? (int) $countVal : 0;
    }

    /**
     * Cleans up old login attempt logs.
     *
     * Intended to be invoked via cron/scheduled maintenance jobs.
     *
     * @param int $days Cutoff age in days (defaults to 30).
     * @return int Total number of deleted log records.
     */
    public function cleanOlderThan(int $days = 30): int
    {
        $cutoff = DateHelper::ago($days * 86400);
        return $this->db->delete(
            "DELETE FROM {$this->table} WHERE created_at < :cutoff",
            ['cutoff' => $cutoff]
        );
    }
}
