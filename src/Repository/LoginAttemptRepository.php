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
     * Computes the remaining lockout time using exponential backoff.
     *
     * Once failures reach the threshold, each additional full batch of failures doubles
     * the lockout window (base, 2×, 4×, …) up to a hard cap, measured from the most recent
     * failed attempt. Elapsed time since the last failure is computed on the database clock
     * (via TIMESTAMPDIFF) so it is immune to PHP/MySQL timezone drift.
     *
     * @param string $email The email address targeted in the login attempt.
     * @param string $ip The source IP address of the request.
     * @param int $baseWindow The base lockout window in seconds (defaults to 300 / 5 minutes).
     * @param int $maxAttempts The failure threshold before lockout engages (defaults to 5).
     * @param int $maxLockout Hard cap for the escalated lockout window in seconds (defaults to 1800 / 30 minutes).
     * @return int Seconds remaining in the active lockout, or 0 if not currently locked.
     */
    public function lockoutSecondsRemaining(
        string $email,
        string $ip,
        int $baseWindow = 300,
        int $maxAttempts = 5,
        int $maxLockout = 1800
    ): int {
        // Look back far enough that sustained abuse keeps escalating the window.
        $lookback = max($baseWindow * 12, 3600);
        $since = DateHelper::ago($lookback);
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS fails, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS since_last
             FROM {$this->table}
             WHERE (email = :email OR ip_address = :ip)
             AND success = 0 AND created_at > :since",
            ['email' => $email, 'ip' => $ip, 'since' => $since]
        );

        $fails = is_array($row) && isset($row['fails']) && is_numeric($row['fails']) ? (int) $row['fails'] : 0;
        if ($fails < $maxAttempts) {
            return 0;
        }

        $sinceLast = is_array($row) && isset($row['since_last']) && is_numeric($row['since_last'])
            ? (int) $row['since_last']
            : null;
        if ($sinceLast === null) {
            return 0;
        }

        // Exponential backoff: tier 1 at the threshold, doubling per additional batch.
        $tier = intdiv($fails, $maxAttempts);
        $duration = (int) ($baseWindow * (2 ** ($tier - 1)));
        if ($duration > $maxLockout) {
            $duration = $maxLockout;
        }

        $remaining = $duration - $sinceLast;
        return $remaining > 0 ? $remaining : 0;
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
