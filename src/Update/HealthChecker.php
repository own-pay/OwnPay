<?php
declare(strict_types=1);

namespace OwnPay\Update;

/**
 * Health checker â€” post-update verification.
 */
final class HealthChecker
{
    /**
     * Run health checks after update.
     *
     * @return array{healthy: bool, checks: array, error?: string}
     */
    public function check(): array
    {
        $checks = [];

        // 1. Database connectivity
        $checks['database'] = $this->checkDatabase();

        // 2. Required files exist
        $checks['files'] = $this->checkFiles();

        // 3. PHP extensions
        $checks['extensions'] = $this->checkExtensions();

        // 4. Config loaded
        $checks['config'] = $this->checkConfig();

        // 5. Writable directories
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

    private function checkDatabase(): array
    {
        try {
            $db = \OwnPay\Core\Database::getInstance();
            $row = $db->fetchOne("SELECT 1 as ok");
            return ['ok' => ($row['ok'] ?? 0) == 1, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $required = [
            'src/Bootstrap.php',
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

    private function checkConfig(): array
    {
        $configFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($configFile)) {
            return ['ok' => false, 'error' => '.env missing'];
        }
        return ['ok' => true, 'error' => null];
    }

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
