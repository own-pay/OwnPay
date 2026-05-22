<?php
declare(strict_types=1);

namespace OwnPay\Update;

/**
 * System diagnostic and integrity verification service.
 *
 * Runs post-update checks to verify database connectivity, critical file existence,
 * required PHP extensions (e.g. BCMath, OpenSSL), environment configuration files,
 * and appropriate filesystem write permissions.
 *
 * @category Update
 * @package  OwnPay\Update
 */
class HealthChecker
{
    /**
     * Database connection wrapper instance.
     *
     * @var \OwnPay\Core\Database
     */
    private \OwnPay\Core\Database $db;

    /**
     * HealthChecker constructor.
     *
     * @param \OwnPay\Core\Database|null $db Optional database instance.
     */
    public function __construct(?\OwnPay\Core\Database $db = null)
    {
        $this->db = $db ?? \OwnPay\Core\Database::getInstance();
    }

    /**
     * Orchestrates and runs all registered system health checks.
     *
     * @return array{healthy: bool, checks: array<string, array{ok: bool, error: ?string}>, error: ?string} Diagnostics summary.
     */
    public function check(): array
    {
        $checks = [];

        $checks['database'] = $this->checkDatabase();

        $checks['files'] = $this->checkFiles();

        $checks['extensions'] = $this->checkExtensions();

        $checks['config'] = $this->checkConfig();

        $checks['writable'] = $this->checkWritable();

        $healthy = true;
        $firstError = null;
        foreach ($checks as $name => $result) {
            if (!$result['ok']) {
                $healthy = false;
                $firstError = $firstError ?? "{$name}: {$result['error']}";
            }
        }

        return [
            'healthy' => $healthy,
            'checks'  => $checks,
            'error'   => $firstError,
        ];
    }

    /**
     * Verifies connection integrity and basic query execution against the database.
     *
     * @return array{ok: bool, error: ?string} Diagnostic result.
     */
    private function checkDatabase(): array
    {
        try {
            $row = $this->db->fetchOne("SELECT 1 as ok");
            return ['ok' => ($row['ok'] ?? 0) == 1, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verifies that all mandatory source and framework files exist on the filesystem.
     *
     * @return array{ok: bool, error: ?string} Diagnostic result.
     */
    private function checkFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $required = [
            'src/Kernel.php',
            'config/hooks.php',
            'public/index.php',
        ];

        foreach ($required as $file) {
            if (!file_exists($root . '/' . $file)) {
                return ['ok' => false, 'error' => "Missing: {$file}"];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Validates that all required PHP core and utility extensions are loaded.
     *
     * Validates extensions such as 'bcmath' (required for double-entry bookkeeping precision)
     * and 'openssl' (required for payment credentials encryption).
     *
     * @return array{ok: bool, error: ?string} Diagnostic result.
     */
    private function checkExtensions(): array
    {
        $required = ['pdo', 'pdo_mysql', 'openssl', 'mbstring', 'json', 'bcmath'];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                return ['ok' => false, 'error' => "Missing extension: {$ext}"];
            }
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Confirms presence of the environment configuration file (.env).
     *
     * @return array{ok: bool, error: ?string} Diagnostic result.
     */
    private function checkConfig(): array
    {
        $configFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($configFile)) {
            return ['ok' => false, 'error' => '.env missing'];
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Verifies write access on local runtime cache and logging directories.
     *
     * @return array{ok: bool, error: ?string} Diagnostic result.
     */
    private function checkWritable(): array
    {
        $root = dirname(__DIR__, 2);
        $dirs = ['storage', 'storage/cache', 'storage/logs'];
        foreach ($dirs as $dir) {
            $path = $root . '/' . $dir;
            if (is_dir($path) && !is_writable($path)) {
                return ['ok' => false, 'error' => "Not writable: {$dir}"];
            }
        }
        return ['ok' => true, 'error' => null];
    }
}
