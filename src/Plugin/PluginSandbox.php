<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Security scanner and capability enforcer for plugins.
 *
 * Scans all PHP files in a plugin directory for:
 *   - Banned function calls (shell commands, unsafe I/O, etc.)
 *   - Dangerous file types (.phar, .sh, .exe, .bat)
 *   - Undeclared capability usage
 *
 * This class is called during ZIP install (Step 4 + 5) before any
 * plugin code is loaded or executed.
 */
final class PluginSandbox
{
    /**
     * Functions that are always banned regardless of capabilities.
     *
     * @var list<string>
     */
    private const BANNED_FUNCTIONS = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
        'pcntl_exec',
        'pcntl_fork',
        'create_function',
        '__halt_compiler',
        'dl',
        'putenv',
    ];

    /**
     * Regex patterns for dangerous code constructs.
     * Each pattern => description.
     *
     * @var array<string, string>
     */
    private const DANGEROUS_PATTERNS = [
        '/\bpreg_replace\s*\(\s*[\'"][^\'\"]*\/e\b/'
            => 'preg_replace with /e modifier (code execution)',
        '/\$GLOBALS\s*\[\s*[\'"]__pdo[\'"]\s*\]/'
            => 'Direct access to $GLOBALS[\'__pdo\'] (use Database class)',
        '/\$_ENV\s*\[.*\]\s*=/'
            => 'Direct write to $_ENV (use set_env() instead)',
        '/new\s+\\\\?PDO\s*\(/'
            => 'Direct PDO instantiation (use Database::getInstance())',
        '/new\s+\\\\?ReflectionClass\s*\(\s*[\'"]\\\\?OwnPay\\\\/'
            => 'ReflectionClass on core OwnPay classes',
    ];

    /**
     * File extensions that are never allowed inside a plugin package.
     *
     * @var list<string>
     */
    private const BANNED_EXTENSIONS = [
        'phar',
        'sh',
        'bash',
        'exe',
        'bat',
        'cmd',
        'com',
        'msi',
        'dll',
        'so',
    ];

    /**
     * Mapping: capability => list of function/pattern signatures that require it.
     *
     * @var array<string, list<string>>
     */
    private const CAPABILITY_SIGNATURES = [
        'db_read'        => ['getData', 'fetchAll', 'fetchOne', 'fetchColumn'],
        'db_write'       => ['insertData', 'updateData', 'deleteData', '->execute('],
        'http_outbound'  => ['curl_init', 'curl_exec', 'HttpClient::', 'file_get_contents(\'http', 'file_get_contents("http'],
        'file_read'      => ['file_get_contents', 'fopen', 'fread', 'readfile', 'file('],
        'file_write'     => ['file_put_contents', 'fwrite', 'fputcsv', 'mkdir', 'rename', 'unlink', 'rmdir'],
    ];

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Run the full security scan on a plugin directory.
     *
     * Returns an array of violation descriptions. Empty = clean.
     *
     * @param string $directory Absolute path to the plugin root directory
     * @return list<string> Violation messages
     */
    public static function scan(string $directory): array
    {
        $violations = [];

        if (!is_dir($directory)) {
            $violations[] = "Plugin directory does not exist: {$directory}";
            return $violations;
        }

        // Scan for banned file extensions
        $violations = array_merge($violations, self::scanBannedFiles($directory));

        // Scan all PHP files for banned functions and dangerous patterns
        $phpFiles = self::findPhpFiles($directory);
        foreach ($phpFiles as $file) {
            $violations = array_merge($violations, self::scanFile($file, $directory));
        }

        return $violations;
    }

    /**
     * Validate that a plugin only uses capabilities it has declared.
     *
     * @param string           $directory    Absolute path to plugin root
     * @param PluginManifest   $manifest     The plugin's parsed manifest
     * @return list<string> Violation messages for undeclared capabilities
     */
    public static function validateCapabilities(string $directory, PluginManifest $manifest): array
    {
        $violations = [];
        $declaredCaps = $manifest->capabilities;

        $phpFiles = self::findPhpFiles($directory);

        foreach (self::CAPABILITY_SIGNATURES as $capability => $signatures) {
            // Skip if capability is declared
            if (in_array($capability, $declaredCaps, true)) {
                continue;
            }

            foreach ($phpFiles as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $relativePath = self::relativePath($file, $directory);

                foreach ($signatures as $signature) {
                    if (str_contains($content, $signature)) {
                        // Find the line number
                        $lineNumber = self::findLineNumber($content, $signature);
                        $violations[] = "Undeclared capability '{$capability}': "
                            . "signature '{$signature}' found in {$relativePath}:{$lineNumber}";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Run the complete security + capability validation pipeline.
     *
     * This is the single entry point used by PluginInstaller.
     *
     * @param string         $directory  Absolute path to plugin root
     * @param PluginManifest $manifest   The plugin's parsed manifest
     * @return array{clean: bool, violations: list<string>}
     */
    public static function fullScan(string $directory, PluginManifest $manifest): array
    {
        $violations = [];

        // Step 4: Code security scan
        $violations = array_merge($violations, self::scan($directory));

        // Step 5: Capability validation
        $violations = array_merge($violations, self::validateCapabilities($directory, $manifest));

        return [
            'clean'      => $violations === [],
            'violations' => $violations,
        ];
    }

    // ── Internal scanners ──────────────────────────────────────────

    /**
     * Scan a single PHP file for banned functions and dangerous patterns.
     *
     * @return list<string>
     */
    private static function scanFile(string $filePath, string $baseDir): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        if ($content === false) {
            return $violations;
        }

        $relativePath = self::relativePath($filePath, $baseDir);

        // Check banned functions using token analysis for accuracy
        $violations = array_merge(
            $violations,
            self::scanBannedFunctions($content, $relativePath),
        );

        // Check dangerous regex patterns
        foreach (self::DANGEROUS_PATTERNS as $pattern => $description) {
            if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $lineNumber = self::lineAtOffset($content, (int) $matches[0][1]);
                $violations[] = "Security violation: {$description} in {$relativePath}:{$lineNumber}";
            }
        }

        // Check for include/require with variable paths (non-literal)
        $violations = array_merge(
            $violations,
            self::scanDynamicIncludes($content, $relativePath),
        );

        return $violations;
    }

    /**
     * Use PHP tokenizer for accurate banned-function detection.
     *
     * This avoids false positives from string literals and comments.
     *
     * @return list<string>
     */
    private static function scanBannedFunctions(string $content, string $relativePath): array
    {
        $violations = [];
        $tokens = token_get_all($content);
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            // Only check T_STRING tokens (function names)
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $funcName = strtolower($token[1]);
            $line = $token[2];

            if (!in_array($funcName, self::BANNED_FUNCTIONS, true)) {
                continue;
            }

            // Look ahead for ( to confirm it's a function call
            for ($j = $i + 1; $j < $tokenCount; $j++) {
                $next = $tokens[$j];

                // Skip whitespace and comments
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                // If next non-whitespace token is '(', this is a function call
                if ($next === '(') {
                    // Check it's not a method call (preceded by -> or ::)
                    $isMethod = false;
                    for ($k = $i - 1; $k >= 0; $k--) {
                        $prev = $tokens[$k];
                        if (is_array($prev) && in_array($prev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                            $isMethod = true;
                        }
                        break;
                    }

                    if (!$isMethod) {
                        $violations[] = "Security violation: banned function '{$token[1]}()' in {$relativePath}:{$line}";
                    }
                }
                break;
            }
        }

        return $violations;
    }

    /**
     * Detect include/require statements with variable (non-literal) paths.
     *
     * @return list<string>
     */
    private static function scanDynamicIncludes(string $content, string $relativePath): array
    {
        $violations = [];
        $tokens = token_get_all($content);
        $tokenCount = count($tokens);
        $includeTypes = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], $includeTypes, true)) {
                continue;
            }

            $line = $token[2];
            $keyword = $token[1];

            // Look ahead — if the next meaningful token is a variable, it's dynamic
            for ($j = $i + 1; $j < $tokenCount; $j++) {
                $next = $tokens[$j];

                // Skip whitespace
                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }

                // If next token is a variable or a function call that returns a path, flag it
                if (is_array($next) && $next[0] === T_VARIABLE) {
                    $violations[] = "Security violation: {$keyword} with variable path in {$relativePath}:{$line}";
                }
                break;
            }
        }

        return $violations;
    }

    /**
     * Scan for files with banned extensions.
     *
     * @return list<string>
     */
    private static function scanBannedFiles(string $directory): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS,
            ),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Check for symlinks (security risk)
            if ($file->isLink()) {
                $relativePath = self::relativePath($file->getPathname(), $directory);
                $violations[] = "Security violation: symlink detected: {$relativePath}";
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (in_array($ext, self::BANNED_EXTENSIONS, true)) {
                $relativePath = self::relativePath($file->getPathname(), $directory);
                $violations[] = "Security violation: banned file type '.{$ext}' found: {$relativePath}";
            }
        }

        return $violations;
    }

    // ── Utility methods ────────────────────────────────────────────

    /**
     * Recursively find all .php files in a directory.
     *
     * @return list<string> Absolute file paths
     */
    private static function findPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get the line number where a string first occurs in content.
     */
    private static function findLineNumber(string $content, string $needle): int
    {
        $pos = strpos($content, $needle);
        if ($pos === false) {
            return 0;
        }
        return self::lineAtOffset($content, $pos);
    }

    /**
     * Convert a byte offset in content to a line number.
     */
    private static function lineAtOffset(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    /**
     * Make a path relative to a base directory for display.
     */
    private static function relativePath(string $filePath, string $baseDir): string
    {
        $normalized = str_replace('\\', '/', $filePath);
        $base = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
        if (str_starts_with($normalized, $base)) {
            return substr($normalized, strlen($base));
        }
        return $normalized;
    }
}
