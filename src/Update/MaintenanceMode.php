<?php
declare(strict_types=1);

namespace OwnPay\Update;

use OwnPay\Support\DateHelper;

/**
 * Maintenance window state manager.
 *
 * Enforces file-based locking to place the application into maintenance mode.
 * Serializes reason, activation timestamp, and retry-after periods to block incoming
 * checkout and customer transactions during critical system updates.
 *
 * @category Update
 * @package  OwnPay\Update
 */
class MaintenanceMode
{
    /**
     * Absolute path to the maintenance lock file.
     *
     * @var string
     */
    private string $lockFile;

    /**
     * MaintenanceMode constructor.
     *
     * @param string|null $lockFile Path to the lock file.
     */
    public function __construct(?string $lockFile = null)
    {
        $this->lockFile = $lockFile ?? dirname(__DIR__, 2) . '/storage/.maintenance';
    }

    /**
     * Places the system into maintenance mode by generating the lock file.
     *
     * Writes details about the maintenance window so the framework can intercept
     * requests and return appropriate HTTP 503 Service Unavailable headers.
     *
     * @param string $reason Brief message explaining the maintenance state.
     * @return void
     */
    public function enter(string $reason = 'System maintenance'): void
    {
        $data = [
            'reason'     => $reason,
            'started_at' => DateHelper::now(),
            'retry_after' => 300,
        ];
        file_put_contents($this->lockFile, json_encode($data), LOCK_EX);
    }

    /**
     * Re-enables the system for traffic by purging the maintenance lock file.
     *
     * @return void
     */
    public function exit(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    /**
     * Inspects if the system is currently flagged for maintenance.
     *
     * @return bool True if the lock file exists.
     */
    public function isActive(): bool
    {
        return file_exists($this->lockFile);
    }

    /**
     * Retrieves details about the current maintenance state.
     *
     * @return array{reason: string, started_at: string, retry_after: int}|null Maintenance info array or null.
     */
    public function info(): ?array
    {
        if (!$this->isActive()) {
            return null;
        }
        $raw = file_get_contents($this->lockFile);
        $data = json_decode(is_string($raw) ? $raw : '{}', true);
        if (!is_array($data)) {
            return null;
        }
        $reason = $data['reason'] ?? 'System maintenance';
        $startedAt = $data['started_at'] ?? DateHelper::now();
        $retryAfter = $data['retry_after'] ?? 300;
        return [
            'reason'      => is_string($reason) ? $reason : 'System maintenance',
            'started_at'  => is_string($startedAt) ? $startedAt : DateHelper::now(),
            'retry_after' => is_numeric($retryAfter) ? (int) $retryAfter : 300,
        ];
    }
}
