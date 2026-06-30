<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Security\SecurityHelpers;

/**
 * Orchestrates secure plugin installation, extraction, and validation.
 *
 * This installer processes uploaded plugin ZIP archives, performing safety checks
 * (such as path traversal prevention and blocked extension analysis) before
 * validating the plugin manifest and deploying the files to the respective
 * modules directory within the enterprise payment architecture.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
final class PluginInstaller
{
    /**
     * List of file extensions that are explicitly blocked for security purposes.
     *
     * @var array<int, string>
     */
    private const BLOCKED_EXTENSIONS = ['phar', 'sh', 'bat', 'exe', 'dll'];

    /**
     * Absolute path to the modules directory.
     *
     * @var string
     */
    private string $modulesDir;

    /**
     * PluginInstaller constructor.
     *
     * @param string $modulesDir Absolute path to the modules root directory.
     */
    public function __construct(string $modulesDir)
    {
        $this->modulesDir = rtrim($modulesDir, '/\\');
    }

    /**
     * Installs a plugin from an uploaded ZIP archive.
     *
     * Executes a multi-stage security and validation pipeline:
     * 1. Opens and verifies the ZIP archive format.
     * 2. Scans for directory traversal attempts and blocked executable files.
     * 3. Extracts contents to a sandboxed temporary directory.
     * 4. Loads and validates the JSON manifest schema.
     * 5. Deploys the verified plugin files into the merchant execution path.
     *
     * @param string $zipPath Absolute path to the uploaded ZIP file.
     * @param bool   $overwrite Whether to overwrite the plugin files if already installed.
     * @return array{success: bool, error?: string, slug?: string, code?: string, existing_version?: string, new_version?: string, has_migrations?: bool} Installation status and metadata.
     */
    public function installFromZip(string $zipPath, bool $overwrite = false): array
    {
        if (!file_exists($zipPath)) {
            return $this->fail('ZIP file not found');
        }

        $zip = $this->openZip($zipPath);
        if (is_array($zip)) return $zip;

        $scanResult = $this->scanZipSecurity($zip);
        if ($scanResult !== null) { $zip->close(); return $scanResult; }

        $tempDir = $this->extractToTemp($zip);
        if (is_array($tempDir)) return $tempDir;

        $manifest = $this->loadAndValidateManifest($tempDir);
        if (is_array($manifest)) { $this->removeDir($tempDir); return $manifest; }

        $deployResult = $this->deployPlugin($tempDir, $manifest, $overwrite);
        $this->removeDir($tempDir);
        return $deployResult;
    }

    /**
     * Uninstalls a plugin by removing its deployed directory and files.
     *
     * Enforces slug sanitation using the SecurityHelpers sanitization system
     * to prevent directory escape or command injection exploits during deletion.
     *
     * @param string $slug Unique plugin identifier.
     * @param string $type Plugin capability type (e.g., 'gateway', 'theme', 'addon').
     * @return bool True if directory was successfully removed, false otherwise.
     */
    public function uninstall(string $slug, string $type = 'addon'): bool
    {
        $typeDir = $this->resolveTypeDir($type);
        $dir = $this->modulesDir . '/' . $typeDir . '/' . SecurityHelpers::sanitizeSlug($slug);
        $deleted = is_dir($dir) ? $this->removeDir($dir) : false;

        $trashDir = dirname($this->modulesDir) . '/storage/trash/plugins/' . $typeDir . '/' . SecurityHelpers::sanitizeSlug($slug);
        if (is_dir($trashDir)) {
            $this->removeDir($trashDir);
            $deleted = true;
        }

        return $deleted;
    }

    /**
     * Opens and validates a ZIP archive.
     *
     * @param string $zipPath Absolute path to the ZIP archive.
     * @return \ZipArchive|array{success: false, error: string} The opened ZIP archive, or an error array.
     */
    private function openZip(string $zipPath): \ZipArchive|array
    {
        $zip = new \ZipArchive();
        return $zip->open($zipPath) === true ? $zip : $this->fail('Invalid ZIP file');
    }

    /**
     * Scans ZIP archive entries for path traversal and restricted file extensions.
     *
     * Mitigates Remote Code Execution (RCE) vectors by blocking phar, shell, and executable files.
     *
     * @param \ZipArchive $zip The active ZIP archive reference.
     * @return array{success: false, error: string}|null Null if clean, or an error array.
     */
    private function scanZipSecurity(\ZipArchive $zip): ?array
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;

            // Normalize path separators to forward slashes for unified safety check
            $normalizedName = str_replace('\\', '/', $name);
            if (str_contains($normalizedName, '..') || str_starts_with($normalizedName, '/') || str_contains($normalizedName, ':')) {
                return $this->fail('ZIP contains path traversal attempt');
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                return $this->fail("Blocked file type: .{$ext}");
            }
        }
        return null;
    }

    /**
     * Extracts ZIP archive contents into a randomized temporary folder.
     *
     * @param \ZipArchive $zip The active ZIP archive reference.
     * @return string|array{success: false, error: string} Path to the temporary directory, or an error array.
     * @throws \Exception If random byte generation fails.
     */
    private function extractToTemp(\ZipArchive $zip): string|array
    {
        $tempDir = sys_get_temp_dir() . '/op_plugin_' . bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0755, true)) {
            $zip->close();
            return $this->fail('Failed to create temp directory');
        }
        $zip->extractTo($tempDir);
        $zip->close();
        return $tempDir;
    }

    /**
     * Loads and validates the JSON manifest configuration inside the temporary directory.
     *
     * @param string $tempDir Path to the extracted temporary directory.
     * @return PluginManifest|array{success: false, error: string} The parsed manifest object, or an error array.
     */
    private function loadAndValidateManifest(string $tempDir): PluginManifest|array
    {
        $pluginDir = $this->findPluginRoot($tempDir);
        if ($pluginDir === null) {
            return $this->fail('No manifest.json found in ZIP');
        }

        $manifest = PluginManifest::fromDirectory($pluginDir);
        if ($manifest === null) {
            return $this->fail('Invalid manifest.json');
        }

        $errors = $manifest->validate();
        if (!empty($errors)) {
            return $this->fail('Manifest errors: ' . implode(', ', $errors));
        }

        return $manifest;
    }

    /**
     * Deploys validated plugin files from the sandbox to the live modules tree.
     *
     * Checks destination writability and protects existing plugin paths from
     * being overwritten without prior uninstallation.
     *
     * @param string         $tempDir   Path to the temporary directory.
     * @param PluginManifest $manifest  The validated plugin manifest structure.
     * @param bool           $overwrite Whether to overwrite the plugin files if already installed.
     * @return array{success: bool, error?: string, slug?: string, code?: string, existing_version?: string, new_version?: string, has_migrations?: bool} Deployment status.
     */
    private function deployPlugin(string $tempDir, PluginManifest $manifest, bool $overwrite = false): array
    {
        $typeDir = $this->resolveTypeDir($manifest->type);
        $modulesTypeDir = $this->modulesDir . '/' . $typeDir;
        $targetDir = $modulesTypeDir . '/' . $manifest->slug;

        if (!is_dir($modulesTypeDir)) @mkdir($modulesTypeDir, 0755, true);

        if (!is_writable($modulesTypeDir)) {
            return $this->fail("Target directory '{$typeDir}' is not writable. Check permissions.");
        }

        if (is_dir($targetDir)) {
            if (!$overwrite) {
                $existingVersion = '0.0.0';
                $existingManifest = PluginManifest::fromDirectory($targetDir);
                if ($existingManifest !== null) {
                    $existingVersion = $existingManifest->version;
                }
                $pluginDir = $manifest->path;
                $hasMigrations = !empty($manifest->migrations);
                $migrationsDir = $pluginDir . '/migrations';
                if (is_dir($migrationsDir)) {
                    $sqlFiles = glob($migrationsDir . '/*.sql');
                    if (!empty($sqlFiles)) {
                        $hasMigrations = true;
                    }
                }
                return [
                    'success'          => false,
                    'code'             => 'already_installed',
                    'slug'             => $manifest->slug,
                    'existing_version' => $existingVersion,
                    'new_version'      => $manifest->version,
                    'has_migrations'   => $hasMigrations,
                    'error'            => "Plugin '{$manifest->slug}' already installed."
                ];
            }
            $this->removeDir($targetDir);
        }

        $pluginDir = $manifest->path;
        if (!rename($pluginDir, $targetDir)) {
            $this->copyDir($pluginDir, $targetDir);
        }

        return ['success' => true, 'slug' => $manifest->slug];
    }

    /**
     * Standardizes standard error responses.
     *
     * @param string $error Message describing the failure.
     * @return array{success: false, error: string} Structured failure response.
     */
    private function fail(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    /**
     * Maps the capability type to its corresponding directory name.
     *
     * @param string $type The manifest declared plugin type.
     * @return string Deployed subfolder name.
     */
    private function resolveTypeDir(string $type): string
    {
        return match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };
    }

    /**
     * Traverses the extraction tree to locate the directory containing manifest.json.
     *
     * @param string $dir Path to search within.
     * @return string|null The root plugin path, or null if not found.
     */
    private function findPluginRoot(string $dir): ?string
    {
        if (file_exists($dir . '/manifest.json')) return $dir;
        $entries = scandir($dir);
        if ($entries === false) return null;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $subDir = $dir . '/' . $entry;
            if (is_dir($subDir) && file_exists($subDir . '/manifest.json')) return $subDir;
        }
        return null;
    }

    /**
     * Recursively removes a directory and its nested contents.
     *
     * @param string $dir Absolute directory path.
     * @return bool True if successfully deleted.
     */
    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }
            return @rmdir($dir);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Recursively copies a directory to a target location.
     *
     * Used as a fallback when system-level renaming is blocked by OS/filesystem boundaries.
     *
     * @param string $src Source directory path.
     * @param string $dst Destination directory path.
     * @return void
     */
    private function copyDir(string $src, string $dst): void
    {
        @mkdir($dst, 0755, true);
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo) {
                    $target = $dst . '/' . $items->getSubPathname();
                    $item->isDir() ? @mkdir($target, 0755) : @copy($item->getPathname(), $target);
                }
            }
        } catch (\Throwable $e) {
            // Ignore copying errors, fallback gracefully
        }
    }
}
