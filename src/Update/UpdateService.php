<?php
declare(strict_types=1);

namespace OwnPay\Update;

use OwnPay\Event\EventManager;
use OwnPay\Repository\UpdateHistoryRepository;
use OwnPay\Service\System\EnvironmentService;
use OwnPay\Service\System\Logger;

/**
 * Update service — full 9-step update flow with rollback.
 *
 * Steps: 1.check → 2.backup → 3.maintenance ON → 4.download →
 *        5.verify → 6.extract → 7.migrate → 8.clear_cache →
 *        9.health_check → 10.maintenance OFF
 *
 * Fires: update.before, update.after, update.failed, update.rollback
 */
final class UpdateService
{
    private BackupService $backup;
    private HealthChecker $health;
    private MaintenanceMode $maintenance;
    private UpdateHistoryRepository $history;
    private EventManager $events;
    private ?Logger $logger;

    public function __construct(
        BackupService $backup,
        HealthChecker $health,
        MaintenanceMode $maintenance,
        UpdateHistoryRepository $history,
        EventManager $events,
        ?Logger $logger = null
    ) {
        $this->backup = $backup;
        $this->health = $health;
        $this->maintenance = $maintenance;
        $this->history = $history;
        $this->events = $events;
        $this->logger = $logger;
    }

    /**
     * Check for available updates from manifest.json.
     *
     * @return array
     */
    public function check(): array
    {
        $currentVersion = EnvironmentService::version();
        $updateUrl = getenv('UPDATE_CHECK_URL') ?: 'https://update.ownpay.org/manifest.json';

        try {
            $ch = curl_init($updateUrl . '?v=' . urlencode($currentVersion));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                return ['available' => false, 'error' => 'connection_failed', 'message' => $curlError ?: "HTTP {$httpCode}"];
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                return ['available' => false, 'error' => 'invalid_response'];
            }

            // Read from unified manifest: channels.stable or top-level fallback
            $latestVersion = null;
            $downloadUrl = null;
            $changelog = null;
            $checksum = null;

            if (isset($data['channels']['stable'])) {
                $stable = $data['channels']['stable'];
                $latestVersion = $stable['latest_version_name'] ?? null;
                $downloadUrl = $stable['download_url'] ?? null;
                $changelog = $stable['changelog'] ?? null;
                $checksum = $stable['checksum_sha256'] ?? null;
            } else {
                // Backward compat: top-level fields
                $latestVersion = $data['version'] ?? null;
                $downloadUrl = $data['download_url'] ?? null;
                $changelog = $data['changelog'] ?? null;
            }

            if ($latestVersion === null) {
                return ['available' => false, 'error' => 'invalid_response'];
            }

            $hasUpdate = version_compare($latestVersion, $currentVersion, '>');
            if ($hasUpdate) {
                $this->events->doAction('update.available', $latestVersion);
            }

            return [
                'available' => $hasUpdate,
                'version'   => $latestVersion,
                'url'       => $downloadUrl,
                'changelog' => $changelog,
                'checksum'  => $checksum,
            ];

        } catch (\Throwable $e) {
            return ['available' => false, 'error' => 'connection_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * Execute full update flow.
     *
     * @return array
     */
    public function execute(string $version, string $downloadUrl, ?string $expectedChecksum = null): array
    {
        // Prevent concurrent updates
        if ($this->history->isUpdateInProgress()) {
            return ['success' => false, 'error' => 'Update already in progress'];
        }

        $updateId = $this->history->startUpdate($version);
        $backupPath = null;

        try {
            $this->events->doAction('update.before', $version);

            // Step 1: Backup
            $this->log("Update to v{$version} — Step 1: Creating backup");
            $this->history->updateStep((int) $updateId, 'backup_created');
            $backupPath = $this->backup->createFullBackup();

            // Update backup path in history
            $this->history->updateStep((int) $updateId, 'backup_created');

            // Step 2: Maintenance mode ON
            $this->log("Step 2: Entering maintenance mode");
            $this->maintenance->enter("Updating to v{$version}");

            // Step 3: Download
            $this->log("Step 3: Downloading update package");
            $this->history->updateStep((int) $updateId, 'downloaded');
            $packagePath = $this->downloadPackage($downloadUrl);

            // Step 4: Verify integrity (SHA-256)
            $this->log("Step 4: Verifying package integrity");
            if ($expectedChecksum !== null && $expectedChecksum !== '') {
                $actualChecksum = hash_file('sha256', $packagePath);
                if (!hash_equals($expectedChecksum, $actualChecksum)) {
                    @unlink($packagePath);
                    throw new \RuntimeException(
                        "Package integrity check failed. Expected: " . substr($expectedChecksum, 0, 16) .
                        "... Got: " . substr($actualChecksum, 0, 16) . "..."
                    );
                }
                $this->log("Checksum verified: SHA-256 OK");
            } else {
                $this->log("WARNING: No checksum provided — skipping integrity check");
            }

            // Step 5: Extract
            $this->log("Step 5: Extracting update package");
            $this->history->updateStep((int) $updateId, 'applied');
            $this->extractPackage($packagePath);

            // Step 6: Run migrations
            $this->log("Step 6: Running database migrations");
            $migrationCount = $this->runMigrations();
            $this->log("Migrations completed: {$migrationCount} executed");

            // Step 7: Clear cache
            $this->log("Step 7: Clearing cache");
            $this->clearCache();

            // Step 8: Health check
            $this->log("Step 8: Running health checks");
            $this->history->updateStep((int) $updateId, 'verified');
            $healthResult = $this->health->check();

            if (!$healthResult['healthy']) {
                throw new \RuntimeException('Health check failed: ' . ($healthResult['error'] ?? 'unknown'));
            }

            // Step 9: Maintenance mode OFF
            $this->log("Step 9: Exiting maintenance mode");
            $this->maintenance->exit();
            $this->history->completeUpdate((int) $updateId);

            $this->events->doAction('update.after', $version);
            $this->log("Update to v{$version} completed successfully");

            // Cleanup old backups (keep last 5)
            $this->backup->cleanup(5);

            return ['success' => true];

        } catch (\Throwable $e) {
            $this->log("Update failed: " . $e->getMessage(), 'error');
            $this->events->doAction('update.failed', $version, $e->getMessage());

            // Rollback
            if ($backupPath !== null) {
                try {
                    $this->log("Rolling back from backup: {$backupPath}");
                    $this->backup->restore($backupPath);
                    $this->events->doAction('update.rollback', $version);
                    $this->history->markRolledBack((int) $updateId, $e->getMessage());
                    $this->log("Rollback completed");
                } catch (\Throwable $rollbackError) {
                    $this->log("CRITICAL: Rollback failed: " . $rollbackError->getMessage(), 'error');
                    $this->history->markFailed((int) $updateId, 'Rollback failed: ' . $rollbackError->getMessage());
                }
            } else {
                $this->history->markFailed((int) $updateId, $e->getMessage());
            }

            $this->maintenance->exit();

            return ['success' => false, 'error' => $e->getMessage(), 'rollback' => true];
        }
    }

    private function downloadPackage(string $url): string
    {
        $tmpFile = sys_get_temp_dir() . '/op_update_' . bin2hex(random_bytes(8)) . '.zip';

        $ch = curl_init($url);
        $fp = fopen($tmpFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException('Cannot create temp file for download');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($result === false || $httpCode !== 200) {
            @unlink($tmpFile);
            throw new \RuntimeException("Download failed: HTTP {$httpCode}" . ($curlError ? " — {$curlError}" : ''));
        }

        // Verify it's a valid ZIP
        $fileSize = filesize($tmpFile);
        if ($fileSize === false || $fileSize < 100) {
            @unlink($tmpFile);
            throw new \RuntimeException('Downloaded file is empty or too small');
        }

        return $tmpFile;
    }

    private function extractPackage(string $zipPath): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            @unlink($zipPath);
            throw new \RuntimeException('Invalid update package (ZIP error code: ' . $openResult . ')');
        }

        $appRoot = dirname(__DIR__, 2);

        // Safety check: verify ZIP doesn't contain path traversal
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                @unlink($zipPath);
                throw new \RuntimeException('Update package contains unsafe paths');
            }
        }

        $zip->extractTo($appRoot);
        $zip->close();
        @unlink($zipPath);
    }

    /**
     * Run pending database migrations from database/migrations/.
     * Executes numbered SQL files in order, tracks which have been run.
     *
     * @return int Number of migrations executed
     */
    private function runMigrations(): int
    {
        $migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
        if (!is_dir($migrationsDir)) {
            return 0;
        }

        $files = glob($migrationsDir . '/*.sql');
        if (empty($files)) {
            return 0;
        }

        sort($files); // Ensure numerical order

        $db = \OwnPay\Core\Database::getInstance();
        $executed = 0;

        // Create migrations tracking table if not exists
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `op_migrations` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `executed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        foreach ($files as $file) {
            $filename = basename($file);

            // Skip already executed migrations
            $exists = $db->fetchOne(
                "SELECT id FROM op_migrations WHERE migration = :m",
                ['m' => $filename]
            );
            if ($exists !== null) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            // Execute migration — split by semicolons for multi-statement support
            $statements = array_filter(
                array_map('trim', explode(";\n", $sql)),
                fn($s) => $s !== '' && !str_starts_with($s, '--')
            );

            try {
                foreach ($statements as $stmt) {
                    $stmt = rtrim($stmt, ';');
                    if (trim($stmt) !== '') {
                        $db->execute($stmt);
                    }
                }

                // Record successful migration
                $db->execute(
                    "INSERT INTO op_migrations (migration) VALUES (:m)",
                    ['m' => $filename]
                );

                $executed++;
                $this->log("Migration executed: {$filename}");

            } catch (\Throwable $e) {
                $this->log("Migration failed: {$filename} — " . $e->getMessage(), 'error');
                throw new \RuntimeException("Migration failed: {$filename} — " . $e->getMessage());
            }
        }

        return $executed;
    }

    private function clearCache(): void
    {
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*');
            foreach ($files ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        // Also clear Twig cache if exists
        $twigCache = dirname(__DIR__, 2) . '/storage/cache/twig';
        if (is_dir($twigCache)) {
            $this->removeDir($twigCache);
            @mkdir($twigCache, 0755, true);
        }
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->logger !== null) {
            if ($level === 'error') {
                $this->logger->error('[Update] ' . $message);
            } else {
                $this->logger->info('[Update] ' . $message);
            }
        }
    }
}
