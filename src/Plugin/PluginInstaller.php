<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Security\SecurityHelpers;

/**
 * Plugin installer — handles ZIP upload, extraction, validation.
 *
 * Per security: validate ZIP contents, check for path traversal,
 * verify manifest before activation.
 */
final class PluginInstaller
{
    private const BLOCKED_EXTENSIONS = ['phar', 'sh', 'bat', 'exe', 'dll'];

    private string $modulesDir;

    public function __construct(string $modulesDir)
    {
        $this->modulesDir = rtrim($modulesDir, '/\\');
    }

    /**
     * Install plugin from uploaded ZIP file.
     * Decomposed: openZip → scanSecurity → extract → validateManifest → deploy.
     */
    public function installFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            return $this->fail('ZIP file not found');
        }

        // Step 1: Open + scan
        $zip = $this->openZip($zipPath);
        if (is_array($zip)) return $zip; // error result

        $scanResult = $this->scanZipSecurity($zip);
        if ($scanResult !== null) { $zip->close(); return $scanResult; }

        // Step 2: Extract to temp
        $tempDir = $this->extractToTemp($zip);
        if (is_array($tempDir)) return $tempDir; // error result

        // Step 3: Validate manifest
        $manifest = $this->loadAndValidateManifest($tempDir);
        if (is_array($manifest)) { $this->removeDir($tempDir); return $manifest; }

        // Step 4: Deploy
        $deployResult = $this->deployPlugin($tempDir, $manifest);
        $this->removeDir($tempDir);
        return $deployResult;
    }

    /**
     * Uninstall plugin — remove files.
     */
    public function uninstall(string $slug, string $type = 'addon'): bool
    {
        $typeDir = $this->resolveTypeDir($type);
        $dir = $this->modulesDir . '/' . $typeDir . '/' . SecurityHelpers::sanitizeSlug($slug);
        return is_dir($dir) ? $this->removeDir($dir) : false;
    }

    // ── Extracted Steps ───────────────────────────────────────────

    /**
     * Open and validate ZIP archive.
     * @return \ZipArchive|array Error result if invalid
     */
    private function openZip(string $zipPath): \ZipArchive|array
    {
        $zip = new \ZipArchive();
        return $zip->open($zipPath) === true ? $zip : $this->fail('Invalid ZIP file');
    }

    /**
     * Security scan: path traversal + blocked extensions.
     * @return array|null null if safe, error result otherwise
     */
    private function scanZipSecurity(\ZipArchive $zip): ?array
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;

            if (str_contains($name, '..') || str_starts_with($name, '/')) {
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
     * Extract ZIP to temporary directory.
     * @return string|array Temp dir path or error result
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
     * Load and validate plugin manifest.
     * @return PluginManifest|array Manifest or error result
     */
    private function loadAndValidateManifest(string $tempDir): PluginManifest|array
    {
        $pluginDir = $this->findPluginRoot($tempDir);
        if ($pluginDir === null) {
            return $this->fail('No plugin.json found in ZIP');
        }

        $manifest = PluginManifest::fromDirectory($pluginDir);
        if ($manifest === null) {
            return $this->fail('Invalid plugin.json');
        }

        $errors = $manifest->validate();
        if (!empty($errors)) {
            return $this->fail('Manifest errors: ' . implode(', ', $errors));
        }

        // Stash the resolved plugin dir on the manifest for deploy step
        $manifest->_resolvedDir = $pluginDir;
        return $manifest;
    }

    /**
     * Deploy plugin files to modules directory.
     */
    private function deployPlugin(string $tempDir, PluginManifest $manifest): array
    {
        $typeDir = $this->resolveTypeDir($manifest->type);
        $modulesTypeDir = $this->modulesDir . '/' . $typeDir;
        $targetDir = $modulesTypeDir . '/' . $manifest->slug;

        if (!is_dir($modulesTypeDir)) @mkdir($modulesTypeDir, 0755, true);

        if (!is_writable($modulesTypeDir)) {
            return $this->fail("Target directory '{$typeDir}' is not writable. Check permissions.");
        }

        if (is_dir($targetDir)) {
            return $this->fail("Plugin '{$manifest->slug}' already installed. Uninstall first.");
        }

        $pluginDir = $manifest->_resolvedDir ?? $tempDir;
        if (!rename($pluginDir, $targetDir)) {
            $this->copyDir($pluginDir, $targetDir);
        }

        return ['success' => true, 'slug' => $manifest->slug];
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function fail(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    private function resolveTypeDir(string $type): string
    {
        return match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };
    }

    private function findPluginRoot(string $dir): ?string
    {
        if (file_exists($dir . '/plugin.json')) return $dir;
        $entries = scandir($dir);
        if ($entries === false) return null;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $subDir = $dir . '/' . $entry;
            if (is_dir($subDir) && file_exists($subDir . '/plugin.json')) return $subDir;
        }
        return null;
    }

    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        return @rmdir($dir);
    }

    private function copyDir(string $src, string $dst): void
    {
        @mkdir($dst, 0755, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $dst . '/' . $items->getSubPathname();
            $item->isDir() ? @mkdir($target, 0755) : @copy($item->getPathname(), $target);
        }
    }
}
