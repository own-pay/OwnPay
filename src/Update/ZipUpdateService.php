<?php
declare(strict_types=1);

namespace OwnPay\Update;

use OwnPay\Event\EventManager;
use OwnPay\Repository\UpdateHistoryRepository;
use OwnPay\Service\System\Logger;

/**
 * Handles manual application updates via admin-uploaded ZIP packages.
 *
 * Validates uploaded ZIPs (file type, size, structure, version metadata, checksums),
 * creates backups, manages maintenance mode, extracts files, runs migrations,
 * performs health checks, and rolls back on failure.
 *
 * Fires hooks: 'update.zip.before', 'update.zip.after', 'update.zip.failed'.
 *
 * @category Update
 * @package  OwnPay\Update
 */
class ZipUpdateService
{
    /**
     * Embedded RSA public key used to verify optional package signatures.
     *
     * Administrators may place a base64-encoded RSA SHA-256 signature in a
     * `signature.sig` file at the root of the ZIP. When present, it must verify
     * against this key; when absent, the superadmin upload itself is treated as
     * the trust gate.
     */
    private const UPDATE_PUBLIC_KEY = <<<'EOT'
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
     * Maximum allowed upload size in bytes (50 MB).
     */
    private const MAX_UPLOAD_SIZE = 50 * 1024 * 1024;

    /**
     * Minimum acceptable ZIP size in bytes (1 KB).
     */
    private const MIN_UPLOAD_SIZE = 1024;

    /**
     * Allowed directories that update packages may write to.
     *
     * @var array<int, string>
     */
    private const ALLOWED_DIRECTORIES = [
        'src',
        'config',
        'templates',
        'public',
        'database',
        'modules',
    ];

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
        $this->backup      = $backup;
        $this->health      = $health;
        $this->maintenance = $maintenance;
        $this->history     = $history;
        $this->events      = $events;
        $this->logger      = $logger;
    }

    /**
     * Validates an uploaded ZIP file and extracts metadata for preview display.
     *
     * @param array<string, mixed> $file PHP uploaded file array.
     * @return array{valid: bool, version?: string, filename?: string, size?: string, signature?: string, error?: string} Preview payload.
     */
    public function validate(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'] ?? 'unknown';
            return ['valid' => false, 'error' => 'Upload failed (error code: ' . (is_scalar($errorCode) ? (string) $errorCode : 'unknown') . ').'];
        }

        $tmpPath = $file['tmp_name'] ?? '';
        if (!is_string($tmpPath) || $tmpPath === '' || !is_file($tmpPath)) {
            return ['valid' => false, 'error' => 'Uploaded file not found on server.'];
        }

        $fileName = $file['name'] ?? '';
        if (!is_string($fileName) || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'zip') {
            return ['valid' => false, 'error' => 'Only .zip files are accepted.'];
        }

        $sizeRaw = $file['size'] ?? 0;
        if (!is_int($sizeRaw) && !is_numeric($sizeRaw)) {
            return ['valid' => false, 'error' => 'Invalid file size.'];
        }
        $fileSize = (int) $sizeRaw;

        if ($fileSize < self::MIN_UPLOAD_SIZE) {
            return ['valid' => false, 'error' => 'File is too small to be a valid update package.'];
        }
        if ($fileSize > self::MAX_UPLOAD_SIZE) {
            $maxMB = (int) (self::MAX_UPLOAD_SIZE / 1048576);
            return ['valid' => false, 'error' => "File exceeds the {$maxMB} MB upload limit."];
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($tmpPath);
        if ($openResult !== true) {
            return ['valid' => false, 'error' => 'File is not a valid ZIP archive (error code: ' . $openResult . ').'];
        }

        $structureResult = $this->validateStructure($zip);
        $zip->close();

        if (!$structureResult['valid']) {
            return $structureResult;
        }

        try {
            $signatureState = $this->verifySignature($tmpPath) ? 'verified' : 'absent';
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }

        return [
            'valid'     => true,
            'version'   => $structureResult['version'] ?? 'unknown',
            'filename'  => $fileName,
            'size'      => $this->formatBytes($fileSize),
            'signature' => $signatureState,
        ];
    }

    /**
     * Executes the manual ZIP update pipeline.
     *
     * The package is re-validated and its version.json manifest re-parsed here
     * (never trusting the client-supplied version alone) before any state change.
     *
     * @param array<string, mixed> $file PHP uploaded file array.
     * @param string $adminEmail Email of the admin performing the update.
     * @param string $version    Expected target version declared in the ZIP manifest.
     * @return array{success: bool, error?: string, rollback?: bool} Completion state.
     */
    public function execute(array $file, string $adminEmail, string $version): array
    {
        $lockHandle = $this->acquireUpdateLock();
        if ($lockHandle === false) {
            return ['success' => false, 'error' => 'An update is already in progress.'];
        }

        try {
            if ($this->history->isUpdateInProgress()) {
                return ['success' => false, 'error' => 'An update is already in progress.'];
            }

            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Upload failed. Please re-submit the update package.'];
            }

            $tmpRaw = $file['tmp_name'] ?? '';
            $tmpPath = is_string($tmpRaw) ? $tmpRaw : '';
            if ($tmpPath === '' || !is_file($tmpPath)) {
                return ['success' => false, 'error' => 'Uploaded file not found on server.'];
            }

            $sizeRaw = $file['size'] ?? 0;
            if ((!is_int($sizeRaw) && !is_numeric($sizeRaw)) || (int) $sizeRaw < self::MIN_UPLOAD_SIZE || (int) $sizeRaw > self::MAX_UPLOAD_SIZE) {
                return ['success' => false, 'error' => 'File size is outside the allowed range.'];
            }

            $nameRaw = $file['name'] ?? '';
            $fileName = is_string($nameRaw) && $nameRaw !== '' ? $nameRaw : 'unknown.zip';

            $updateId = null;
            $backupPath = null;
            $maintenanceEntered = false;

            try {
                $this->log("Manual update from ZIP ({$fileName}) - Step 1: Validating package");
                $this->validatePackageIntegrity($tmpPath);

                $this->log("Step 2: Verifying package signature (if present)");
                $signatureState = $this->verifySignature($tmpPath) ? 'verified' : 'absent';
                $this->log("Signature status: {$signatureState}");

                $manifestVersion = $this->readManifestVersion($tmpPath);
                if ($manifestVersion === null || $manifestVersion === '') {
                    throw new \RuntimeException('Package is missing a valid version.json manifest.');
                }
                if ($version !== '' && $version !== $manifestVersion) {
                    throw new \RuntimeException(
                        "Version mismatch: the package manifest declares v{$manifestVersion}, but the form submitted v{$version}."
                    );
                }

                $updateId = $this->history->startManualUpdate($manifestVersion, $adminEmail, $fileName);

                $actualChecksum = hash_file('sha256', $tmpPath);
                $this->log("Package checksum: {$actualChecksum}");

                $this->events->doAction('update.zip.before', $manifestVersion, $adminEmail);

                $this->log("Step 3: Creating backup");
                $backupPath = $this->backup->createFullBackup();
                $this->history->updateStep((int) $updateId, 'backup_created');

                $this->log("Step 4: Entering maintenance mode");
                $this->maintenance->enter("Manual update to v{$manifestVersion} (by {$adminEmail})");
                $maintenanceEntered = true;

                $this->log("Step 5: Extracting update package");
                $this->extractPackage($tmpPath);
                $this->history->updateStep((int) $updateId, 'applied');

                $this->log("Step 6: Running database migrations");
                $migrationCount = $this->runMigrations();
                $this->log("Migrations executed: {$migrationCount}");

                $this->log("Step 7: Clearing cache");
                $this->clearCache();

                $this->log("Step 8: Running health checks");
                $healthResult = $this->health->check();

                if (!$healthResult['healthy']) {
                    throw new \RuntimeException('Health check failed: ' . ($healthResult['error'] ?? 'unknown'));
                }
                $this->history->updateStep((int) $updateId, 'verified');

                $this->log("Step 9: Exiting maintenance mode");
                $this->maintenance->exit();
                $maintenanceEntered = false;
                $this->history->completeUpdate((int) $updateId);

                $this->events->doAction('update.zip.after', $manifestVersion, $adminEmail);
                $this->log("Manual update to v{$manifestVersion} completed successfully");

                try {
                    $this->backup->cleanup(5);
                } catch (\Throwable $cleanupError) {
                    $this->log("Old backup cleanup skipped: " . $cleanupError->getMessage());
                }

                return ['success' => true];

            } catch (\Throwable $e) {
                $this->log("Manual update failed: " . $e->getMessage(), 'error');
                $this->events->doAction('update.zip.failed', $version, $e->getMessage());

                if ($updateId !== null && $backupPath !== null) {
                    try {
                        $this->log("Rolling back from backup: {$backupPath}");
                        $this->backup->restore($backupPath);
                        $this->history->markRolledBack((int) $updateId, $e->getMessage());
                        $this->log("Rollback completed");
                    } catch (\Throwable $rollbackError) {
                        $this->log("CRITICAL: Rollback failed: " . $rollbackError->getMessage(), 'error');
                        $this->history->markFailed((int) $updateId, 'Rollback failed: ' . $rollbackError->getMessage());
                    }
                } elseif ($updateId !== null) {
                    $this->history->markFailed((int) $updateId, $e->getMessage());
                }

                if ($maintenanceEntered) {
                    $this->maintenance->exit();
                }

                return ['success' => false, 'error' => $e->getMessage(), 'rollback' => $backupPath !== null];
            }
        } finally {
            $this->releaseUpdateLock($lockHandle);
        }
    }

    /**
     * Validates internal ZIP structure: required directories, no path traversal.
     *
     * @param \ZipArchive $zip Opened ZIP archive.
     * @return array{valid: bool, version?: string, error?: string}
     */
    private function validateStructure(\ZipArchive $zip): array
    {
        $hasVersionFile = false;
        $version = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                return ['valid' => false, 'error' => 'Package contains unsafe paths (directory traversal attempt blocked).'];
            }

            if ($name === 'version.json') {
                $hasVersionFile = true;
                $content = $zip->getFromIndex($i);
                if (is_string($content)) {
                    $meta = json_decode($content, true);
                    if (is_array($meta) && isset($meta['version']) && is_string($meta['version'])) {
                        $version = $meta['version'];
                    }
                }
            }
        }

        if (!$hasVersionFile) {
            return ['valid' => false, 'error' => 'Package is missing version.json manifest. Please ensure the ZIP contains a version.json file at its root.'];
        }

        if ($version === null || $version === '') {
            return ['valid' => false, 'error' => 'version.json does not contain a valid "version" field.'];
        }

        return ['valid' => true, 'version' => $version];
    }

    /**
     * Reads the version declared in version.json at the ZIP root.
     *
     * @param string $tmpPath Path to the uploaded temp file.
     * @return string|null The declared version, or null when missing/invalid.
     */
    private function readManifestVersion(string $tmpPath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return null;
        }
        $manifestIndex = $zip->locateName('version.json');
        if ($manifestIndex === false) {
            $zip->close();
            return null;
        }
        $content = $zip->getFromIndex($manifestIndex);
        $zip->close();

        if (!is_string($content)) {
            return null;
        }
        $meta = json_decode($content, true);
        if (!is_array($meta) || !isset($meta['version']) || !is_string($meta['version']) || $meta['version'] === '') {
            return null;
        }
        return $meta['version'];
    }

    /**
     * Validates package integrity before extraction.
     *
     * @param string $tmpPath Path to the uploaded temp file.
     * @return void
     * @throws \RuntimeException If the package is corrupt or unsafe.
     */
    private function validatePackageIntegrity(string $tmpPath): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($tmpPath);
        if ($openResult !== true) {
            throw new \RuntimeException('Cannot open update package (error code: ' . $openResult . ').');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                $zip->close();
                @unlink($tmpPath);
                throw new \RuntimeException('Update package contains unsafe paths.');
            }

            /** @var array<string, int|string>|false $stat */
            $stat = $zip->statIndex($i);
            $entryMode = is_array($stat) ? ($stat['mode'] ?? 0) : 0;
            if (is_int($entryMode) && ($entryMode & 0xA000) === 0xA000) {
                $zip->close();
                @unlink($tmpPath);
                throw new \RuntimeException('Update package contains symbolic links, which are not allowed.');
            }

            $parts = explode('/', $name);
            $firstSegment = $parts[0];
            $isRootMetadata = in_array($name, ['version.json', 'signature.sig'], true);
            if (!$isRootMetadata && $firstSegment !== '' && !in_array($firstSegment, self::ALLOWED_DIRECTORIES, true)) {
                $zip->close();
                @unlink($tmpPath);
                throw new \RuntimeException(
                    "Package contains files outside approved directories: '{$firstSegment}'. " .
                    'Allowed: ' . implode(', ', self::ALLOWED_DIRECTORIES)
                );
            }
        }

        $zip->close();
    }

    /**
     * Verifies an optional RSA signature embedded as signature.sig inside the ZIP.
     *
     * The package is considered signed when a `signature.sig` (base64 RSA-SHA256)
     * entry exists at the ZIP root. It must verify over the raw SHA-256 digest of
     * the `version.json` manifest content, signed against the embedded public key.
     * A present-but-invalid signature aborts the update; an absent signature is
     * allowed (the superadmin upload itself is the trust gate).
     *
     * @param string $tmpPath Path to the uploaded temp file.
     * @return bool True when a valid signature is present; false when absent.
     * @throws \RuntimeException When a signature is present but cannot be verified.
     */
    private function verifySignature(string $tmpPath): bool
    {
        if (!function_exists('openssl_verify')) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return false;
        }
        $signatureIndex = $zip->locateName('signature.sig');
        if ($signatureIndex === false) {
            $zip->close();
            return false;
        }
        $signatureBase64 = $zip->getFromIndex($signatureIndex);
        $manifestIndex = $zip->locateName('version.json');
        $manifestContent = $manifestIndex === false ? null : $zip->getFromIndex($manifestIndex);
        $zip->close();

        if (!is_string($signatureBase64) || trim($signatureBase64) === '') {
            throw new \RuntimeException('Package signature.sig is empty. Invalid signature format.');
        }
        if (!is_string($manifestContent) || $manifestContent === '') {
            throw new \RuntimeException('Signed packages must contain a version.json manifest.');
        }

        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || $signature === '') {
            throw new \RuntimeException('Package signature.sig is not valid base64.');
        }

        $signedPayload = hash('sha256', $manifestContent);

        $pubKeyResource = openssl_pkey_get_public(self::UPDATE_PUBLIC_KEY);
        if ($pubKeyResource === false) {
            throw new \RuntimeException('Failed to load embedded public key for signature verification.');
        }

        $verifyResult = openssl_verify($signedPayload, $signature, $pubKeyResource, OPENSSL_ALGO_SHA256);
        if ($verifyResult !== 1) {
            $err = ($verifyResult === 0) ? 'Signature mismatch' : 'OpenSSL verification error (' . openssl_error_string() . ')';
            throw new \RuntimeException("Signature verification failed: {$err}.");
        }

        return true;
    }

    /**
     * Extracts code files from the uploaded ZIP archive into the application root.
     *
     * Root metadata entries (version.json, signature.sig) are skipped to avoid
     * leaving transient artifacts in the application root.
     *
     * @param string $tmpPath Path to the uploaded temp file.
     * @return void
     * @throws \RuntimeException If extraction fails.
     */
    protected function extractPackage(string $tmpPath): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($tmpPath);
        if ($openResult !== true) {
            throw new \RuntimeException('Invalid update package (ZIP error code: ' . $openResult . ').');
        }

        $appRoot = dirname(__DIR__, 2);
        $skipped = ['version.json', 'signature.sig'];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || in_array($name, $skipped, true)) {
                continue;
            }
            $result = $zip->extractTo($appRoot, $name);
            if ($result !== true) {
                $zip->close();
                throw new \RuntimeException("Failed to extract entry: {$name}");
            }
        }
        $zip->close();
    }

    /**
     * Runs pending database migrations from database/migrations/.
     *
     * @return int Number of migrations executed.
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
     * Splits multi-statement SQL blocks, respecting quoted strings.
     *
     * @param string $sql Raw SQL.
     * @return array<int, string> Ordered statements.
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
     * @param string $stmt Raw statement text.
     * @return string Statement without leading comments.
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
     * Clears runtime cache directories and compiled Twig cache.
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
     * Recursively purges a directory and its nested file contents.
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
     * Acquires the exclusive self-update lock.
     *
     * @return resource|false|null Held lock handle on success; false when locked; null when unavailable.
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
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        return $fp;
    }

    /**
     * Releases the self-update lock.
     *
     * @param resource|null $handle The lock handle.
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
     * Formats a byte count into a human-readable string.
     *
     * @param int $bytes Byte count.
     * @return string Formatted size.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' bytes';
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
                $this->logger->error('[ZipUpdate] ' . $message);
            } else {
                $this->logger->info('[ZipUpdate] ' . $message);
            }
        }
    }
}
