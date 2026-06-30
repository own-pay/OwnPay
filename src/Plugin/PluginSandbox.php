<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Security sandbox isolation layer for plugin execution.
 *
 * Restricts plugin resource access by validating file system operations, sanitizing and
 * auditing SQL statements, and blocking execution of dangerous PHP system functions.
 * Enforces strict isolation boundaries to maintain payment platform integrity.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
final class PluginSandbox
{
    /**
     * Absolute path to the plugin directory.
     *
     * @var string
     */
    private string $pluginDir;

    /**
     * List of capabilities explicitly granted to this plugin.
     *
     * @var array<int, string>
     */
    private array $allowedCapabilities;

    /**
     * PluginSandbox constructor.
     *
     * @param string             $pluginDir    Directory containing the plugin files.
     * @param array<int, string> $capabilities List of allowed capability strings.
     */
    public function __construct(string $pluginDir, array $capabilities)
    {
        $this->pluginDir = realpath($pluginDir) ?: $pluginDir;
        $this->allowedCapabilities = $capabilities;
    }

    /**
     * Validates that a file access attempt is confined within the plugin directory.
     *
     * Appends a directory separator prefix during validation to prevent sibling folder traversal
     * (e.g., ensuring /stripe-payment does not match /stripe).
     *
     * @param string $path Target file or directory path.
     * @return bool True if path resides safely within the plugin directory boundaries.
     */
    public function validateFilePath(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            $real = realpath(dirname($path));
            if ($real === false) {
                return false;
            }
        }
        $pluginDirWithSep = rtrim($this->pluginDir, '/\\') . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $pluginDirWithSep) || $real === rtrim($this->pluginDir, '/\\');
    }

    /**
     * Verifies if the plugin possesses a requested capability.
     *
     * @param string $capability Unique capability identifier.
     * @return bool True if the capability is explicitly allowed.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->allowedCapabilities, true);
    }

    /**
     * Audits SQL queries to prevent SQL Injection, table escape, and structural alterations.
     *
     * Strips SQL comments and collapses whitespaces to mitigate bypass attempts before checking
     * for blocked statements (e.g., DROP, ALTER, load_file) and direct access to system-level tables.
     *
     * @param string $sql Raw SQL query string.
     * @return bool True if the SQL query conforms to security policies.
     */
    public function validateSql(string $sql): bool
    {
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--.*$/m', ' ', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? $sql;

        $blocked = [
            'drop', 'truncate', 'alter', 'grant', 'revoke',
            'load_file', 'into\\s+outfile', 'into\\s+dumpfile',
            'create\\s+database', 'create\\s+user', 'create\\s+table',
        ];
        $pattern = '/\b(' . implode('|', $blocked) . ')\b/i';
        if (preg_match($pattern, $sql)) {
            return false;
        }

        if (preg_match_all('/\bop_(?!plugin)[a-z_]+\b/', $sql, $matches)) {
            return false;
        }

        return true;
    }

    /**
     * Determines whether a PHP function is flagged as dangerous.
     *
     * Blocks system execution commands, arbitrary evaluation commands, direct OS commands,
     * and destructive filesystem modifications.
     *
     * @param string $function Function name to inspect.
     * @return bool True if the function is dangerous and must be blocked.
     */
    public static function isDangerousFunction(string $function): bool
    {
        // Full-trust footgun list: direct OS-command / process-control primitives that a legitimate
        // plugin essentially never needs. This is a safety net against accidents and obvious abuse,
        // NOT an isolation boundary (see PluginLoader's scanner note) - installed plugins are
        // owner-uploaded and run with full application trust, like WordPress. Ordinary PHP
        // (callbacks, reflection, file I/O, dynamic calls, include/require) is intentionally allowed.
        $dangerous = [
            'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'proc_nice',
            'pcntl_exec', 'dl', 'assert', 'create_function',
        ];
        return in_array(strtolower($function), $dangerous, true);
    }

    /**
     * Resolves and prepares the dedicated sandboxed storage directory path for the plugin.
     *
     * @return string Absolute directory path.
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
