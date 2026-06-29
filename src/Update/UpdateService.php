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
class UpdateService
{
    /**
     * Hardcoded RSA Public Key PEM used to verify cryptographically signed updates.
     */
    private const UPDATE_PUBLIC_KEY = <<<EOT
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzBVsd2Yd/xqMD00Dts/e
OuSIjjYab3fRqEtRaPf9cAl0iFRR+o7RGloz6dh6M2trswiKx2s2mN4+JPL604Z/
K86OxhovINo8KT4kQ3Tisq9uQ7J7x5YieoLfj4YpWwSv643Vw4QYMDagsMntXgbo
ZnfuQ3Dk7EgeZ4/8psHN/SGb8E7/JyQRwQhNFpOOO++25kR/4fKm9kHiOH8URoYi
gbp/HC6oTH5ObtTMwaXFk7ZHMyh6iHmYv4cLZtJR+/Xpkb1d5gz7IcsTklJPXSja
a8U63KZm/fnwYBsV4JdX2qTPfZLSGhL7vEHA5U1y617RGdT3WaShURvv2o4eiyBb
FQIDAQAB
-----END PUBLIC KEY-----
EOT;

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

            if (!is_string($response) || $httpCode !== 200) {
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

            $channels = $data['channels'] ?? null;
            if (is_array($channels) && isset($channels['stable']) && is_array($channels['stable'])) {
                $stable = $channels['stable'];
                $latestVersion = is_string($stable['latest_version_name'] ?? null) ? $stable['latest_version_name'] : null;
                $downloadUrl = is_string($stable['download_url'] ?? null) ? $stable['download_url'] : null;
                $changelog = is_string($stable['changelog'] ?? null) ? $stable['changelog'] : null;
                $checksum = is_string($stable['checksum_sha256'] ?? null) ? $stable['checksum_sha256'] : null;
            } else {
                $latestVersion = is_string($data['version'] ?? null) ? $data['version'] : null;
                $downloadUrl = is_string($data['download_url'] ?? null) ? $data['download_url'] : null;
                $changelog = is_string($data['changelog'] ?? null) ? $data['changelog'] : null;
            }

            if ($latestVersion === null) {
                return ['available' => false, 'error' => 'invalid_response'];
            }

            $hasUpdate = version_compare($latestVersion, $currentVersion, '>');
            if ($hasUpdate) {
                $this->events->doAction('update.available', $latestVersion);
            }

            $result = [
                'available' => $hasUpdate,
                'version'   => $latestVersion,
            ];
            if ($downloadUrl !== null) {
                $result['url'] = $downloadUrl;
            }
            if ($changelog !== null) {
                $result['changelog'] = $changelog;
            }
            if ($checksum !== null) {
                $result['checksum'] = $checksum;
            }
            return $result;

        } catch (\Throwable $e) {
            return ['available' => false, 'error' => 'connection_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * Executes the sequential application update workflow.
     *
     * Validates that no concurrent updates are in progress, fetches the remote manifest
     * to resolve package parameters, creates system backup checkpoints,
     * locks incoming checkout traffic, downloads and validates packages using checksum matching,
     * verifies asymmetric cryptographic signatures, extracts package assets,
     * executes database migrations, purges caches, and releases maintenance locks.
     * Triggers a comprehensive rollback to restore the system state on failure.
     *
     * @param string $version Target version to upgrade the platform to.
     * @return array{success: bool, error?: string, rollback?: bool} Completion result state.
     */
    public function execute(string $version): array
    {
        // Acquire an exclusive on-disk lock BEFORE the isUpdateInProgress() DB
        // check. Without it, two concurrent /admin/system-update/apply requests
        // both read "no active update", both call startUpdate(), and run the
        // download/extract/migrate pipeline simultaneously - corrupting files
        // and double-running migrations. flock makes the check-and-claim atomic.
        $lockHandle = $this->acquireUpdateLock();
        if ($lockHandle === false) {
            return ['success' => false, 'error' => 'Update already in progress'];
        }

        try {
            if ($this->history->isUpdateInProgress()) {
                return ['success' => false, 'error' => 'Update already in progress'];
            }

            $updateId = $this->history->startUpdate($version);
            $backupPath = null;

            try {
            $this->log("Update to v{$version} - Step 1: Resolving update metadata");
            $manifest = $this->fetchManifest();
            
            $releaseEntry = null;
            if (isset($manifest['releases']) && is_array($manifest['releases'])) {
                foreach ($manifest['releases'] as $release) {
                    if (is_array($release) && isset($release['version']) && $release['version'] === $version) {
                        $releaseEntry = $release;
                        break;
                    }
                }
            }

            if ($releaseEntry === null) {
                throw new \RuntimeException("Target version v{$version} not found in official update server manifest.");
            }

            $downloadUrl = is_string($releaseEntry['download_url'] ?? null) ? $releaseEntry['download_url'] : '';
            $expectedChecksum = is_string($releaseEntry['checksum_sha256'] ?? null) ? $releaseEntry['checksum_sha256'] : '';
            $signatureBase64 = is_string($releaseEntry['signature'] ?? null) ? $releaseEntry['signature'] : '';

            if (empty($downloadUrl)) {
                throw new \RuntimeException("Download URL for v{$version} is empty.");
            }
            if (empty($expectedChecksum)) {
                throw new \RuntimeException("Checksum for v{$version} is empty.");
            }
            if (empty($signatureBase64)) {
                throw new \RuntimeException("Cryptographic signature for v{$version} is missing. Unsigned updates are blocked.");
            }

            // Strict domain verification
            $allowedHosts = [
                'update.ownpay.org',
                'github.com',
                'objects.githubusercontent.com'
            ];
            $host = parse_url($downloadUrl, PHP_URL_HOST);
            if (!in_array($host, $allowedHosts, true)) {
                throw new \RuntimeException("Security Exception: Download URL host '{$host}' is not in the allowed whitelisted domains.");
            }

            $this->events->doAction('update.before', $version);

            $this->log("Step 2: Creating backup");
            $backupPath = $this->backup->createFullBackup();
            $this->history->updateStep((int) $updateId, 'backup_created');

            $this->log("Step 3: Entering maintenance mode");
            $this->maintenance->enter("Updating to v{$version}");

            $this->log("Step 4: Downloading update package");
            $packagePath = $this->downloadPackage($downloadUrl);
            $this->history->updateStep((int) $updateId, 'downloaded');

            $this->log("Step 5: Verifying package integrity & cryptographic signature");
            
            // Checksum check
            $actualChecksum = hash_file('sha256', $packagePath);
            if (!is_string($actualChecksum)) {
                @unlink($packagePath);
                throw new \RuntimeException("Failed to calculate package checksum.");
            }
            if (!hash_equals($expectedChecksum, $actualChecksum)) {
                @unlink($packagePath);
                throw new \RuntimeException(
                    "Package integrity check failed. Expected: " . substr($expectedChecksum, 0, 16) .
                    "... Got: " . substr($actualChecksum, 0, 16) . "..."
                );
            }
            $this->log("Checksum verified: SHA-256 OK");

            // Cryptographic RSA signature check
            $signature = base64_decode($signatureBase64);
            if (empty($signature)) {
                @unlink($packagePath);
                throw new \RuntimeException("Invalid base64 signature format.");
            }

            $zipData = file_get_contents($packagePath);
            if (!is_string($zipData)) {
                @unlink($packagePath);
                throw new \RuntimeException("Failed to read downloaded package content.");
            }
            $pubKeyResource = openssl_pkey_get_public(self::UPDATE_PUBLIC_KEY);
            if ($pubKeyResource === false) {
                @unlink($packagePath);
                throw new \RuntimeException("Failed to load embedded public key for verification.");
            }

            $verifyResult = openssl_verify($zipData, $signature, $pubKeyResource, OPENSSL_ALGO_SHA256);
            if ($verifyResult !== 1) {
                @unlink($packagePath);
                $err = ($verifyResult === 0) ? "Signature mismatch" : "OpenSSL verification error (" . openssl_error_string() . ")";
                throw new \RuntimeException("Cryptographic signature verification failed: {$err}.");
            }
            $this->log("Cryptographic signature verified: RSA OK");

            $this->log("Step 6: Extracting update package");
            $this->extractPackage($packagePath);
            $this->history->updateStep((int) $updateId, 'applied');

            $this->log("Step 7: Running database migrations");
            $migrationCount = $this->runMigrations();
            $this->log("Migrations completed: {$migrationCount} executed");

            $this->log("Step 8: Clearing cache");
            $this->clearCache();

            $this->log("Step 9: Running health checks");
            $healthResult = $this->health->check();
            $this->history->updateStep((int) $updateId, 'verified');

            if (!$healthResult['healthy']) {
                throw new \RuntimeException('Health check failed: ' . ($healthResult['error'] ?? 'unknown'));
            }

            $this->log("Step 10: Exiting maintenance mode");
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
        } finally {
            $this->releaseUpdateLock($lockHandle);
        }
    }

    /**
     * Acquires the exclusive self-update lock.
     *
     * @return resource|false|null Held lock handle on success; false when another
     *                             update currently holds the lock (caller must
     *                             abort); null when the lock file is unavailable
     *                             (caller proceeds, guarded only by the DB check).
     */
    private function acquireUpdateLock()
    {
        $lockPath = dirname(__DIR__, 2) . '/storage/update.lock';
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            // Lock file unavailable: degrade to the DB in-progress check alone
            // rather than blocking updates outright.
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        return $fp;
    }

    /**
     * Releases the self-update lock acquired by acquireUpdateLock().
     *
     * @param resource|null $handle The lock handle (null when running in degraded, lockless mode).
     * @return void
     */
    private function releaseUpdateLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Queries the remote manifest directly on the server side.
     *
     * @return array<string, mixed> Loaded manifest array payload.
     * @throws \RuntimeException If the connection fails or JSON is corrupted.
     */
    protected function fetchManifest(): array
    {
        $updateUrl = getenv('UPDATE_CHECK_URL') ?: 'https://update.ownpay.org/manifest.json';
        $ch = curl_init($updateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            throw new \RuntimeException("Could not connect to update server to fetch manifest (HTTP {$httpCode}).");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Update server returned an invalid manifest format.");
        }

        $stringKeyedData = [];
        foreach ($data as $k => $v) {
            $stringKeyedData[(string) $k] = $v;
        }

        return $stringKeyedData;
    }

    /**
     * Downloads the release package to a temporary file path.
     *
     * @param string $url Secure update download URL.
     * @return string Path to the temporary zip package.
     * @throws \RuntimeException If the file cannot be created or cURL execution fails.
     */
    protected function downloadPackage(string $url): string
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
            throw new \RuntimeException("Download failed: HTTP {$httpCode}" . ($curlError ? " - {$curlError}" : ''));
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
    protected function extractPackage(string $zipPath): void
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
            if ($name === false || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
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
    protected function runMigrations(): int
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
                $this->log("Migration failed: {$filename} - " . $e->getMessage(), 'error');
                throw new \RuntimeException("Migration failed: {$filename} - " . $e->getMessage());
            }
        }

        return $executed;
    }

    /**
     * Clears runtime memory, cache directories, and compiles Twig cache templates.
     *
     * @return void
     */
    protected function clearCache(): void
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
                $stmt = $this->stripLeadingSqlComments(trim($current));
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $stmt = $this->stripLeadingSqlComments(trim($current));
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Strips leading comment-only and blank lines from an SQL statement.
     *
     * Statements are split on ';', so any '--' comment lines preceding a
     * statement become part of its text. Discarding a statement because it
     * merely *starts with* a comment silently skips real DDL while still
     * marking the migration as executed - schema drift with no error.
     *
     * @param string $stmt Raw statement text.
     * @return string The statement without leading comment lines ('' when it was comments only).
     */
    private function stripLeadingSqlComments(string $stmt): string
    {
        $lines = preg_split('/\R/', $stmt) ?: [];
        $offset = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                $offset++;
                continue;
            }
            break;
        }
        return trim(implode("\n", array_slice($lines, $offset)));
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
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
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
