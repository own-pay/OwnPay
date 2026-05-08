<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Plugin sandbox â€” restricts what plugins can access.
 *
 * Per security skill: plugins cannot access raw DB, filesystem outside their dir,
 * or core internals directly. They interact through provided APIs only.
 */
final class PluginSandbox
{
    private string $pluginDir;
    private array $allowedCapabilities;

    public function __construct(string $pluginDir, array $capabilities)
    {
        $this->pluginDir = realpath($pluginDir) ?: $pluginDir;
        $this->allowedCapabilities = $capabilities;
    }

    /**
     * Validate file access is within plugin directory.
     */
    public function validateFilePath(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            // File doesn't exist yet â€” check parent
            $real = realpath(dirname($path));
            if ($real === false) {
                return false;
            }
        }
        return str_starts_with($real, $this->pluginDir);
    }

    /**
     * Check if plugin has required capability.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->allowedCapabilities, true);
    }

    /**
     * Validate SQL â€” plugins can only use whitelisted table prefixes.
     * Prevents direct access to core tables.
     */
    public function validateSql(string $sql): bool
    {
        $sql = strtolower(trim($sql));

        // Block dangerous operations
        $blocked = ['drop ', 'truncate ', 'alter ', 'create database', 'grant ', 'revoke '];
        foreach ($blocked as $pattern) {
            if (str_contains($sql, $pattern)) {
                return false;
            }
        }

        // Plugins can only access op_plugin_* tables or their own prefixed tables
        if (preg_match_all('/\bop_(?!plugin)[a-z_]+\b/', $sql, $matches)) {
            // Accessing core tables directly â€” blocked
            return false;
        }

        return true;
    }

    /**
     * Validate function call â€” block dangerous PHP functions.
     */
    public static function isDangerousFunction(string $function): bool
    {
        $dangerous = [
            'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open',
            'eval', 'assert', 'create_function',
            'file_put_contents', 'fwrite', 'fputs', // Use plugin API instead
            'unlink', 'rmdir', 'rename', 'chmod', 'chown',
            'ini_set', 'ini_alter', 'putenv',
            'dl', 'mail', // Use comm service instead
            'header', 'setcookie', // Use Response instead
        ];
        return in_array(strtolower($function), $dangerous, true);
    }

    /**
     * Get sandboxed storage path for plugin data.
     */
    public function storagePath(): string
    {
        $path = $this->pluginDir . '/storage';
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        return $path;
    }
}
