<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Core\Database;

/**
 * Handles the full ZIP-upload-to-installed pipeline for plugins.
 *
 * 8-step security pipeline:
 *   1. Basic validation (extension, size, MIME)
 *   2. Extract to temp directory (path traversal scan)
 *   3. Manifest validation (PluginManifest)
 *   4. Code security scan (PluginSandbox)
 *   5. Capability validation (PluginSandbox)
 *   6. Entrypoint validation (class + interface check)
 *   7. Install to target directory
 *   8. Audit log
 */
final class PluginInstaller
{
    /** Maximum allowed ZIP file size: 50 MB */
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    /** Allowed MIME types for uploaded ZIP files */
    private const ALLOWED_MIMES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-zip',
        'application/octet-stream',
    ];

    /** Maps plugin type → module directory name */
    private const TYPE_DIRS = [
        'plugin'  => 'app/modules/plugins',
        'gateway' => 'app/modules/gateways',
        'theme'   => 'app/modules/themes',
    ];

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Install a plugin from an uploaded ZIP file.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $uploadedFile
     *     The $_FILES entry for the uploaded ZIP
     * @return array{success: bool, message: string, slug?: string, manifest?: array}
     */
    public static function installFromUpload(array $uploadedFile): array
    {
        $root = dirname(__DIR__, 2);

        // ── Step 1: Basic validation ───────────────────────────────
        $basicResult = self::validateBasic($uploadedFile);
        if ($basicResult !== null) {
            return ['success' => false, 'message' => $basicResult];
        }

        // ── Step 2: Extract to temp ────────────────────────────────
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'op_plugin_' . uniqid('', true);
        $extractResult = self::extractToTemp($uploadedFile['tmp_name'], $tempDir);
        if ($extractResult !== null) {
            self::cleanupTemp($tempDir);
            return ['success' => false, 'message' => $extractResult];
        }

        // Find the actual plugin root (may be nested in a subdirectory)
        $pluginRoot = self::findPluginRoot($tempDir);
        if ($pluginRoot === null) {
            self::cleanupTemp($tempDir);
            return ['success' => false, 'message' => 'No manifest.json found in the uploaded archive.'];
        }

        // ── Step 3: Manifest validation ────────────────────────────
        try {
            $manifest = PluginManifest::fromFile($pluginRoot . '/manifest.json');
        } catch (\Throwable $e) {
            self::cleanupTemp($tempDir);
            return ['success' => false, 'message' => 'Invalid manifest.json: ' . $e->getMessage()];
        }

        $errors = $manifest->validate();
        if ($errors !== []) {
            self::cleanupTemp($tempDir);
            return ['success' => false, 'message' => 'Invalid manifest: ' . implode('; ', $errors)];
        }

        // Verify entrypoint file exists in the package
        if (!is_file($pluginRoot . '/' . $manifest->entrypoint)) {
            self::cleanupTemp($tempDir);
            return ['success' => false, 'message' => "Entrypoint file '{$manifest->entrypoint}' not found in package."];
        }

        // ── Steps 4 + 5: Security scan + capability validation ─────
        $scanResult = PluginSandbox::fullScan($pluginRoot, $manifest);
        if (!$scanResult['clean']) {
            self::cleanupTemp($tempDir);
            $violationList = implode("\n  - ", $scanResult['violations']);
            return ['success' => false, 'message' => "Security scan failed:\n  - {$violationList}"];
        }

        // ── Step 6: Entrypoint validation ──────────────────────────
        $entrypointResult = self::validateEntrypoint($pluginRoot, $manifest);
        if ($entrypointResult !== null) {
            self::cleanupTemp($tempDir);
            return ['success' => false, 'message' => $entrypointResult];
        }

        // ── Step 7: Install to target ──────────────────────────────
        $installResult = self::installToTarget($root, $pluginRoot, $manifest);
        if ($installResult !== null) {
            self::cleanupTemp($tempDir);
            return ['success' => false, 'message' => $installResult];
        }

        // ── Step 8: Write DB record ────────────────────────────────
        try {
            self::upsertPluginRecord($manifest);
        } catch (\Throwable $e) {
            error_log("[OwnPay][PluginInstaller] DB record failed: {$e->getMessage()}");
            // Non-fatal: files are installed, DB record can be created on activate
        }

        // Cleanup temp
        self::cleanupTemp($tempDir);

        // Audit log
        try {
            $adminId = $_SESSION['user_id'] ?? 0;
            error_log("[OwnPay][Audit] plugin.installed: slug={$manifest->slug}, version={$manifest->version}, admin_id={$adminId}");
        } catch (\Throwable) {
            // Non-fatal
        }

        return [
            'success'  => true,
            'message'  => "Plugin '{$manifest->name}' v{$manifest->version} installed successfully.",
            'slug'     => $manifest->slug,
            'manifest' => $manifest->toArray(),
        ];
    }

    /**
     * Upgrade an already-installed plugin from a new ZIP.
     *
     * Backs up existing files, then runs the normal install pipeline.
     *
     * @param array $uploadedFile The $_FILES entry
     * @return array{success: bool, message: string, slug?: string}
     */
    public static function upgradeFromUpload(array $uploadedFile): array
    {
        // The install pipeline handles upgrades automatically:
        // - If slug directory exists, it backs up before overwriting
        // - DB record is upserted
        return self::installFromUpload($uploadedFile);
    }

    /**
     * Remove a plugin's files from disk.
     *
     * @param string $slug The plugin slug
     * @return array{success: bool, message: string}
     */
    public static function removeFiles(string $slug): array
    {
        // Sanitize slug
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return ['success' => false, 'message' => 'Invalid plugin slug.'];
        }

        $root = dirname(__DIR__, 2);

        // Find which type dir it's in
        foreach (self::TYPE_DIRS as $type => $dir) {
            $target = $root . '/' . $dir . '/' . $slug;
            if (is_dir($target)) {
                // Containment check
                $realTarget = realpath($target);
                $realBase = realpath($root . '/' . $dir);
                if ($realTarget === false || $realBase === false || !str_starts_with($realTarget, $realBase . DIRECTORY_SEPARATOR)) {
                    return ['success' => false, 'message' => 'Path containment check failed.'];
                }

                self::deleteDirectory($realTarget);
                return ['success' => true, 'message' => "Plugin '{$slug}' files removed."];
            }
        }

        return ['success' => false, 'message' => "Plugin '{$slug}' not found on disk."];
    }

    // ── Step implementations ───────────────────────────────────────

    /**
     * Step 1: Validate the uploaded file basics.
     *
     * @return string|null Error message or null if valid
     */
    private static function validateBasic(array $file): ?string
    {
        // Upload error check
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'File upload failed with error code: ' . ($file['error'] ?? 'unknown');
        }

        // File exists
        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return 'No file was uploaded.';
        }

        // Extension check
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return 'Only .zip files are accepted.';
        }

        // Size check
        $size = $file['size'] ?? filesize($tmpPath);
        if ($size > self::MAX_FILE_SIZE) {
            return 'File exceeds maximum allowed size of 50MB.';
        }

        // MIME type check
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        if ($mime !== false && !in_array($mime, self::ALLOWED_MIMES, true)) {
            return "Invalid file type: {$mime}. Only ZIP archives are accepted.";
        }

        return null;
    }

    /**
     * Step 2: Extract ZIP to a temporary directory with path traversal scanning.
     *
     * @return string|null Error message or null if OK
     */
    private static function extractToTemp(string $zipPath, string $tempDir): ?string
    {
        if (!mkdir($tempDir, 0755, true)) {
            return 'Failed to create temporary directory.';
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            return 'Failed to open ZIP archive (error code: ' . $openResult . ').';
        }

        // Scan all entries for path traversal BEFORE extracting
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }

            // Path traversal check
            $normalized = str_replace('\\', '/', $entry);
            if (str_contains($normalized, '../') || str_starts_with($normalized, '/') || str_starts_with($normalized, '..')) {
                $zip->close();
                return "Security violation: path traversal detected in archive entry: {$entry}";
            }

            // Absolute path check (Windows)
            if (preg_match('/^[A-Za-z]:/', $normalized)) {
                $zip->close();
                return "Security violation: absolute path detected in archive entry: {$entry}";
            }
        }

        // Safe to extract
        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            return 'Failed to extract ZIP archive.';
        }

        $zip->close();

        // Post-extraction: scan for symlinks
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return 'Security violation: symlink detected in archive.';
            }
        }

        return null;
    }

    /**
     * Step 6: Validate the entrypoint file declares a class implementing PluginInterface.
     *
     * Uses token analysis instead of actually loading the file.
     *
     * @return string|null Error message or null if valid
     */
    private static function validateEntrypoint(string $pluginRoot, PluginManifest $manifest): ?string
    {
        $entrypointPath = $pluginRoot . '/' . $manifest->entrypoint;
        $content = file_get_contents($entrypointPath);
        if ($content === false) {
            return "Cannot read entrypoint file: {$manifest->entrypoint}";
        }

        // Check that it declares a class
        $tokens = token_get_all($content);
        $hasClass = false;
        $implementsInterface = false;

        $tokenCount = count($tokens);
        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_CLASS) {
                $hasClass = true;
            }

            // Look for "implements" keyword followed by PluginInterface
            if ($token[0] === T_IMPLEMENTS) {
                // Scan ahead for PluginInterface
                for ($j = $i + 1; $j < $tokenCount; $j++) {
                    $next = $tokens[$j];
                    if (is_array($next) && $next[0] === T_WHITESPACE) {
                        continue;
                    }

                    if ($next === '{') {
                        break;
                    }

                    if (is_array($next) && ($next[0] === T_STRING || $next[0] === T_NAME_QUALIFIED || $next[0] === T_NAME_FULLY_QUALIFIED)) {
                        if (str_contains($next[1], 'PluginInterface')) {
                            $implementsInterface = true;
                            break;
                        }
                    }
                }
            }
        }

        if (!$hasClass) {
            return "Entrypoint '{$manifest->entrypoint}' does not declare a class.";
        }

        if (!$implementsInterface) {
            return "Entrypoint class must implement PluginInterface.";
        }

        return null;
    }

    /**
     * Step 7: Copy files from temp to target directory.
     *
     * @return string|null Error message or null if success
     */
    private static function installToTarget(string $root, string $pluginRoot, PluginManifest $manifest): ?string
    {
        $typeDir = self::TYPE_DIRS[$manifest->type] ?? null;
        if ($typeDir === null) {
            return "Unknown plugin type: {$manifest->type}";
        }

        $slug = self::sanitizeSlug($manifest->slug);
        if ($slug === '') {
            return 'Invalid plugin slug.';
        }

        $targetDir = $root . '/' . $typeDir . '/' . $slug;

        // If upgrading, backup the existing directory
        if (is_dir($targetDir)) {
            $backupResult = self::backupExisting($root, $targetDir, $slug);
            if ($backupResult !== null) {
                return $backupResult;
            }
        }

        // Create target directory
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            return "Failed to create target directory: {$typeDir}/{$slug}/";
        }

        // Copy all files from plugin root to target
        $copyResult = self::copyDirectory($pluginRoot, $targetDir);
        if ($copyResult !== null) {
            return $copyResult;
        }

        return null;
    }

    // ── Database ───────────────────────────────────────────────────

    /**
     * Insert or update the plugin record in op_plugins.
     */
    private static function upsertPluginRecord(PluginManifest $manifest): void
    {
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';
        $db = Database::getInstance();

        $existing = $db->fetchOne(
            "SELECT id FROM `{$prefix}plugins` WHERE slug = :slug",
            ['slug' => $manifest->slug],
        );

        if ($existing !== null) {
            $db->execute(
                "UPDATE `{$prefix}plugins`
                    SET name = :name,
                        type = :type,
                        version = :version,
                        entrypoint = :entrypoint,
                        capabilities = :capabilities,
                        manifest_hash = :hash,
                        updated_at = NOW()
                  WHERE slug = :slug",
                [
                    'name'         => $manifest->name,
                    'type'         => $manifest->type,
                    'version'      => $manifest->version,
                    'entrypoint'   => $manifest->entrypoint,
                    'capabilities' => json_encode($manifest->capabilities),
                    'hash'         => $manifest->computeHash(),
                    'slug'         => $manifest->slug,
                ],
            );
        } else {
            $db->execute(
                "INSERT INTO `{$prefix}plugins`
                    (slug, name, type, version, status, entrypoint, capabilities, manifest_hash, load_order, installed_at)
                 VALUES
                    (:slug, :name, :type, :version, 'installed', :entrypoint, :capabilities, :hash, 100, NOW())",
                [
                    'slug'         => $manifest->slug,
                    'name'         => $manifest->name,
                    'type'         => $manifest->type,
                    'version'      => $manifest->version,
                    'entrypoint'   => $manifest->entrypoint,
                    'capabilities' => json_encode($manifest->capabilities),
                    'hash'         => $manifest->computeHash(),
                ],
            );
        }
    }

    // ── Filesystem helpers ─────────────────────────────────────────

    /**
     * Find the plugin root directory within an extracted temp directory.
     *
     * Handles both flat extraction (manifest.json at root) and nested
     * (manifest.json inside a single subdirectory).
     */
    private static function findPluginRoot(string $tempDir): ?string
    {
        // Check if manifest is directly in tempDir
        if (is_file($tempDir . '/manifest.json')) {
            return $tempDir;
        }

        // Check one level of nesting (e.g. plugin-slug/manifest.json)
        $entries = scandir($tempDir);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $subDir = $tempDir . '/' . $entry;
            if (is_dir($subDir) && is_file($subDir . '/manifest.json')) {
                return $subDir;
            }
        }

        return null;
    }

    /**
     * Backup an existing plugin directory before upgrade.
     *
     * @return string|null Error message or null if success
     */
    private static function backupExisting(string $root, string $targetDir, string $slug): ?string
    {
        $backupDir = $root . '/storage/plugins/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            return 'Failed to create backup directory.';
        }

        $backupPath = $backupDir . '/' . $slug . '_' . date('Ymd_His');
        if (!rename($targetDir, $backupPath)) {
            return "Failed to backup existing plugin to: {$backupPath}";
        }

        return null;
    }

    /**
     * Recursively copy a directory.
     *
     * @return string|null Error message or null if success
     */
    private static function copyDirectory(string $source, string $destination): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true)) {
                    return "Failed to create directory: {$iterator->getSubPathname()}";
                }
            } else {
                if (!copy($item->getPathname(), $targetPath)) {
                    return "Failed to copy file: {$iterator->getSubPathname()}";
                }
                chmod($targetPath, 0644);
            }
        }

        return null;
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Clean up a temporary extraction directory.
     */
    private static function cleanupTemp(string $tempDir): void
    {
        if (is_dir($tempDir)) {
            self::deleteDirectory($tempDir);
        }
    }

    /**
     * Sanitize a plugin slug to prevent path traversal.
     */
    private static function sanitizeSlug(string $slug): string
    {
        return preg_replace('/[^a-z0-9\-]/', '', strtolower($slug)) ?? '';
    }
}
