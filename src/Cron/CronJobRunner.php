<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Service\Payment\ReconciliationService;
use OwnPay\Service\Notification\AlertService;
use OwnPay\Service\Payment\SettlementService;
use OwnPay\Repository\RateLimitRepository;

/**
 * CronJobRunner — decomposed cron job scheduler.
 *
 * Each job is isolated, locked (no concurrent runs), and logged.
 *
 * Usage (crontab):
 *   * /5 * * * *  php cron.php webhook_retry
 *   0   * * * *   php cron.php rate_limit_cleanup
 *   0   2 * * *   php cron.php reconcile
 *   0   3 * * *   php cron.php settlement_batch
 *   0   4 * * *   php cron.php key_expiry
 */
final class CronJobRunner
{
    private const LOCK_TIMEOUT = 3600; // 1 hour max lock

    private Database $db;

    /** @var array<string, callable> */
    private array $jobs = [];

    /** @var array<string, array{plugin: string, name: string, full_name: string, schedule: string, description: string}> */
    private array $pluginJobs = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->registerDefaultJobs();
    }

    /**
     * Run a named job with locking and logging.
     *
     * @param string $jobName
     * @return array{job: string, status: string, duration_ms: int, result: mixed}
     */
    public function run(string $jobName): array
    {
        if (!isset($this->jobs[$jobName])) {
            return [
                'job' => $jobName,
                'status' => 'error',
                'duration_ms' => 0,
                'result' => "Unknown job: {$jobName}",
            ];
        }

        // Acquire lock
        if (!$this->acquireLock($jobName)) {
            return [
                'job' => $jobName,
                'status' => 'skipped',
                'duration_ms' => 0,
                'result' => 'Another instance is running.',
            ];
        }

        $startTime = hrtime(true);

        try {
            $result = ($this->jobs[$jobName])();
            $status = 'completed';
        } catch (\Throwable $e) {
            $result = $e->getMessage();
            $status = 'failed';
            error_log("[Cron] Job '{$jobName}' failed: {$result}");
        } finally {
            $this->releaseLock($jobName);
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Log execution
        $this->logExecution($jobName, $status, $durationMs, $result);

        return [
            'job' => $jobName,
            'status' => $status,
            'duration_ms' => $durationMs,
            'result' => $result,
        ];
    }

    /**
     * List all available job names.
     */
    public function listJobs(): array
    {
        return array_keys($this->jobs);
    }

    // ─── Plugin Job Registration ─────────────────────────────────────

    /**
     * Register a cron job from a plugin.
     *
     * Plugins call this during their register() phase via the EventManager.
     * Job names are automatically prefixed with "plugin_{slug}_" to avoid collisions.
     *
     * @param string   $pluginSlug  The plugin slug (owner)
     * @param string   $jobName     Job name (e.g. "retry_failed")
     * @param callable $handler     Job handler callable
     * @param string   $schedule    Cron schedule expression (for display/docs only)
     * @param string   $description Human-readable description
     */
    public function registerJob(
        string $pluginSlug,
        string $jobName,
        callable $handler,
        string $schedule = '',
        string $description = '',
    ): void {
        $fullName = "plugin_{$pluginSlug}_{$jobName}";
        $this->jobs[$fullName] = $handler;
        $this->pluginJobs[$fullName] = [
            'plugin'      => $pluginSlug,
            'name'        => $jobName,
            'full_name'   => $fullName,
            'schedule'    => $schedule,
            'description' => $description,
        ];
    }

    /**
     * Remove all jobs registered by a specific plugin.
     *
     * Called when a plugin is deactivated.
     */
    public function removePluginJobs(string $pluginSlug): void
    {
        foreach ($this->pluginJobs as $fullName => $meta) {
            if ($meta['plugin'] === $pluginSlug) {
                unset($this->jobs[$fullName], $this->pluginJobs[$fullName]);
            }
        }
    }

    /**
     * Get all jobs registered by plugins.
     *
     * @return array<string, array{plugin: string, name: string, full_name: string, schedule: string, description: string}>
     */
    public function getPluginJobs(): array
    {
        return $this->pluginJobs;
    }

    /**
     * Register default cron jobs.
     */
    private function registerDefaultJobs(): void
    {
        // 1. Ledger reconciliation
        $this->jobs['reconcile'] = function (): array {
            $recon = new ReconciliationService();
            $alert = new AlertService();
            $results = $recon->runAll();

            // Fire alerts for mismatches
            $alertCount = 0;
            foreach ($results['merchants'] ?? [] as $mid => $reports) {
                foreach ($reports as $report) {
                    $alertCount += $alert->fireFromReconciliation($report);
                }
            }

            return [
                'merchants_checked' => count($results['merchants']),
                'alerts_fired' => $alertCount,
                'bridge' => $results['bridge'],
            ];
        };

        // 2. Retry failed webhook deliveries
        $this->jobs['webhook_retry'] = function (): array {
            try {
                $count = $this->db->fetchColumn("
                    SELECT COUNT(*)
                    FROM op_webhook_events
                    WHERE status = 'failed'
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                return ['pending_retries' => (int) $count];
            } catch (\PDOException $e) {
                return ['error' => $e->getMessage()];
            }
        };

        // 3. Clean up expired rate limit entries
        $this->jobs['rate_limit_cleanup'] = function (): array {
            $repo = new RateLimitRepository();
            $purged = $repo->purgeExpired(300);
            return ['purged_entries' => $purged];
        };

        // 4. Auto-create settlement batches
        $this->jobs['settlement_batch'] = function (): array {
            $rows = $this->db->fetchAll("
                SELECT DISTINCT merchant_id FROM op_transactions
                WHERE status = 'completed' AND settlement_id IS NULL
            ");
            $merchants = array_column($rows, 'merchant_id');

            $batched = 0;
            $settlement = new SettlementService();
            foreach ($merchants as $mid) {
                try {
                    $settlement->createBatch((int) $mid);
                    $batched++;
                } catch (\Throwable $e) {
                    // Skip merchants with no unsettled txns
                }
            }

            return ['merchants_settled' => $batched];
        };

        // 5. Revoke expired API keys
        $this->jobs['key_expiry'] = function (): array {
            try {
                $stmt = $this->db->execute("
                    UPDATE op_api_keys
                    SET status = 'expired', updated_at = NOW(6)
                    WHERE status = 'active'
                      AND expires_at IS NOT NULL
                      AND expires_at <= NOW()
                ");
                return ['expired_keys' => $stmt->rowCount()];
            } catch (\PDOException $e) {
                return ['error' => $e->getMessage()];
            }
        };

        // 6. Auto system-update manifest check (extracted from index.php, Milestone 7)
        $this->jobs['system_update'] = static function (): array {
            /** @var array{version_code: string, version_name: string} $OwnPay_current_version */
            global $OwnPay_current_version;
            $version = is_array($OwnPay_current_version ?? null)
                ? $OwnPay_current_version
                : ['version_code' => '0.0.0', 'version_name' => 'unknown'];
            return (new SystemUpdateJob($version))->run();
        };

        // 7. Match pending transactions against approved SMS data (Milestone 7)
        $this->jobs['sms_verification'] = static fn (): array => (new SmsVerificationJob())->run();

        // 8. Refresh per-brand currency exchange rates (Milestone 7)
        $this->jobs['currency_update'] = static fn (): array => (new CurrencyUpdateJob())->run();

        // 9. Balance-verification chain reconciliation (Milestone 7)
        $this->jobs['balance_verification'] = static fn (): array => (new BalanceVerificationJob())->run();

        // 10. Pending webhook delivery retries (Milestone 7)
        $this->jobs['webhook_pending_retry'] = static fn (): array => (new WebhookRetryJob())->run();
    }

    /**
     * Acquire a job lock (prevents concurrent runs).
     */
    private function acquireLock(string $jobName): bool
    {
        try {
            // Use GET_LOCK for advisory locking
            $lockName = "op_cron_{$jobName}";
            $row = $this->db->fetchOne("SELECT GET_LOCK(:lock, 0) AS acquired", [':lock' => $lockName]);
            return (int) ($row['acquired'] ?? 0) === 1;
        } catch (\PDOException $e) {
            return true; // Degrade: allow run without lock
        }
    }

    /**
     * Release a job lock.
     */
    private function releaseLock(string $jobName): void
    {
        try {
            $lockName = "op_cron_{$jobName}";
            $this->db->execute("SELECT RELEASE_LOCK(:lock)", [':lock' => $lockName]);
        } catch (\PDOException $e) {
            // Ignore
        }
    }

    /**
     * Log job execution.
     */
    private function logExecution(string $jobName, string $status, int $durationMs, mixed $result): void
    {
        try {
            $this->db->execute("
                INSERT INTO op_cron_logs (job_name, status, duration_ms, result, created_at)
                VALUES (:job, :status, :dur, :result, NOW(6))
            ", [
                ':job' => $jobName,
                ':status' => $status,
                ':dur' => $durationMs,
                ':result' => is_string($result) ? $result : json_encode($result),
            ]);
        } catch (\PDOException $e) {
            error_log("[Cron] Failed to log execution: " . $e->getMessage());
        }
    }
}
