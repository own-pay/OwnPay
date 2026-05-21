<?php
declare(strict_types=1);

namespace OwnPay\Update;

use OwnPay\Event\EventManager;
use OwnPay\Repository\UpdateHistoryRepository;
use OwnPay\Service\System\EnvironmentService;
use OwnPay\Service\System\Logger;

/**
 * Orchestrates the application self-update lifecycle pipeline.
 *
 * Implements a strict, multi-step transaction-safe workflow: checking availability,
 * generating a full database/code rollback backup point, entering maintenance mode,
 * securely fetching update packages, verifying SHA-256 integrity, extracting
 * code archives with directory traversal validation, executing incremental database migrations,
 * clearing cache layers, executing verification diagnostic checks, and returning the platform to active service.
 * Handles automatic rollback on update failure to ensure minimal service interruption.
 *
 * Fires hooks: 'update.before', 'update.after', 'update.failed', 'update.rollback'.
 *
 * @category Update
 * @package  OwnPay\Update
 */
final class UpdateService
{
    /**
     * Backup and recovery service instance.
     *
     * @var \OwnPay\Update\BackupService
     */
    private BackupService $backup;

    /**
     * Post-update diagnostic health checker.
     *
     * @var \OwnPay\Update\HealthChecker
     */
    private HealthChecker $health;

    /**
     * Maintenance mode lock controller.
     *
     * @var \OwnPay\Update\MaintenanceMode
     */
    private MaintenanceMode $maintenance;

    /**
     * Database repository for persisting update logs.
     *
     * @var \OwnPay\Repository\UpdateHistoryRepository
     */
    private UpdateHistoryRepository $history;

    /**
     * Platform event manager for trigger registration.
     *
     * @var \OwnPay\Event\EventManager
     */
    private EventManager $events;

    /**
     * Logging handler instance.
     *
     * @var \OwnPay\Service\System\Logger|null
     */
    private ?Logger $logger;

    /**
     * UpdateService constructor.
     *
     * @param \OwnPay\Update\BackupService             $backup      System backup coordinator.
     * @param \OwnPay\Update\HealthChecker             $health      Diagnostic checker.
     * @param \OwnPay\Update\MaintenanceMode           $maintenance Maintenance state locker.
     * @param \OwnPay\Repository\UpdateHistoryRepository $history      Database history repository.
     * @param \OwnPay\Event\EventManager               $events       Hook trigger registry.
     * @param \OwnPay\Service\System\Logger|null       $logger       Logging utility.
     */
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
     * Queries remote servers for the latest stable platform releases.
     *
     * Checks the configured update manifest URL, verifies SSL certificates,
     * and processes release channel data structures.
     *
     * @return array{available: bool, version?: string, url?: string, changelog?: string, checksum?: string, error?: string, message?: string} Update status payload.
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
     * Executes the sequential application update workflow.
     *
     * Validates that no concurrent updates are in progress, creates system backup checkpoints,
     * locks incoming checkout traffic, downloads and validates packages using checksum matching,
     * extracts package assets, executes incremental SQL migration scripts, purges
     * caches, executes health check diagnostics, and releases maintenance locks.
     * Triggers a comprehensive rollback to restore the system state on failure.
     *
     * @param string      $version          Target version to upgrade the platform to.
     * @param string      $downloadUrl      Secure URL to download the update ZIP from.
     * @param string|null $expectedChecksum Optional SHA-256 hash to verify package integrity.
     * @return array{success: bool, error?: string, rollback?: bool} Completion result state.
     */
    public function execute(string $version, string $downloadUrl, ?string $expectedChecksum = null): array
    {
        if ($this->history->isUpdateInProgress()) {
            return ['success' => false, 'error' => 'Update already in progress'];
        }

        $updateId = $this->history->startUpdate($version);
        $backupPath = null;

        try {
            $this->events->doAction('update.before', $version);

            $this->log("Update to v{$version} — Step 1: Creating backup");
            $this->history->updateStep((int) $updateId, 'backup_created');
            $backupPath = $this->backup->createFullBackup();

            $this->history->updateStep((int) $updateId, 'backup_created');

            $this->log("Step 2: Entering maintenance mode");
            $this->maintenance->enter("Updating to v{$version}");

            $this->log("Step 3: Downloading update package");
            $this->history->updateStep((int) $updateId, 'downloaded');
            $packagePath = $this->downloadPackage($downloadUrl);

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

            $this->log("Step 5: Extracting update package");
            $this->history->updateStep((int) $updateId, 'applied');
            $this->extractPackage($packagePath);

            $this->log("Step 6: Running database migrations");
            $migrationCount = $this->runMigrations();
            $this->log("Migrations completed: {$migrationCount} executed");

            $this->log("Step 7: Clearing cache");
            $this->clearCache();

            $this->log("Step 8: Running health checks");
            $this->history->updateStep((int) $updateId, 'verified');
            $healthResult = $this->health->check();

            if (!$healthResult['healthy']) {
                throw new \RuntimeException('Health check failed: ' . ($healthResult['error'] ?? 'unknown'));
            }

            $this->log("Step 9: Exiting maintenance mode");
            $this->maintenance->exit();
            $this->history->completeUpdate((int) $updateId);

            $this->events->doAction('update.after', $version);
            $this->log("Update to v{$version} completed successfully");

            $this->backup->cleanup(5);

            return ['success' => true];

        } catch (\Throwable $e) {
            $this->log("Update failed: " . $e->getMessage(), 'error');
            $this->events->doAction('update.failed', $version, $e->getMessage());

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

    /**
     * Downloads the release package to a temporary file path.
     *
     * @param string $url Secure update download URL.
     * @return string Path to the temporary zip package.
     * @throws \RuntimeException If the file cannot be created or cURL execution fails.
     */
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

        $fileSize = filesize($tmpFile);
        if ($fileSize === false || $fileSize < 100) {
            @unlink($tmpFile);
            throw new \RuntimeException('Downloaded file is empty or too small');
        }

        return $tmpFile;
    }

    /**
     * Extracts files from the downloaded ZIP archive into the application root.
     *
     * Validates filenames to block directory traversal attacks before writing files.
     *
     * @param string $zipPath Path to the downloaded package.
     * @return void
     * @throws \RuntimeException If the file is invalid, contains unsafe paths, or extraction fails.
     */
    private function extractPackage(string $zipPath): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            @unlink($zipPath);
            throw new \RuntimeException('Invalid update package (ZIP error code: ' . $openResult . ')');
        }

        $appRoot = dirname(__DIR__, 2);

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

        sort($files);

        $db = \OwnPay\Core\Database::getInstance();
        $executed = 0;

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

            $statements = $this->splitSqlStatements($sql);

            try {
                foreach ($statements as $stmt) {
                    $stmt = rtrim($stmt, ';');
                    if (trim($stmt) !== '') {
                        $db->execute($stmt);
                    }
                }

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

    /**
     * Clears runtime memory, cache directories, and compiles Twig cache templates.
     *
     * @return void
     */
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

        $twigCache = dirname(__DIR__, 2) . '/storage/cache/twig';
        if (is_dir($twigCache)) {
            $this->removeDir($twigCache);
            @mkdir($twigCache, 0755, true);
        }
    }

    /**
     * Splits multi-statement SQL query blocks, accounting for literal values.
     *
     * Ensures semicolons inside single or double-quoted strings do not cause early truncation.
     *
     * @param string $sql Raw SQL multi-statement block.
     * @return array<int, string> Ordered list of valid SQL queries.
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($prev === '\\') {
                $current .= $char;
                continue;
            }

            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                $stmt = trim($current);
                if ($stmt !== '' && !str_starts_with($stmt, '--')) {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $stmt = trim($current);
        if ($stmt !== '' && !str_starts_with($stmt, '--')) {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Recursively purges a directory and all nested files.
     *
     * @param string $dir Target directory path.
     * @return void
     */
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

    /**
     * Dispatches informational or error messages to logs.
     *
     * @param string $message Log entry content.
     * @param string $level   Diagnostic level.
     * @return void
     */
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
