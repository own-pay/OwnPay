<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Security\SecurityHelpers;

/**
 * Plugin installer — handles ZIP upload, extraction, validation.
 *
 * Per security skill: validate ZIP contents, check for path traversal,
 * verify manifest before activation.
 */
final class PluginInstaller
{
    private string $modulesDir;

    public function __construct(string $modulesDir)
    {
        $this->modulesDir = rtrim($modulesDir, '/\\');
    }

    /**
     * Install plugin from uploaded ZIP file.
     *
     * @return array{success: bool, slug?: string, error?: string}
     */
    public function installFromZip(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            return ['success' => false, 'error' => 'ZIP file not found'];
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            return ['success' => false, 'error' => 'Invalid ZIP file'];
        }

        // Security: scan for path traversal
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                return ['success' => false, 'error' => 'ZIP contains path traversal attempt'];
            }
            // Block dangerous file types
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['phar', 'sh', 'bat', 'exe', 'dll'], true)) {
                $zip->close();
                return ['success' => false, 'error' => "Blocked file type: .{$ext}"];
            }
        }

        // Extract to temp dir first
        $tempDir = sys_get_temp_dir() . '/op_plugin_' . bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0755, true)) {
            $zip->close();
            return ['success' => false, 'error' => 'Failed to create temp directory'];
        }

        $zip->extractTo($tempDir);
        $zip->close();

        // Find plugin.json (may be in root or first subdirectory)
        $pluginDir = $this->findPluginRoot($tempDir);
        if ($pluginDir === null) {
            $this->removeDir($tempDir);
            return ['success' => false, 'error' => 'No plugin.json found in ZIP'];
        }

        $manifest = PluginManifest::fromDirectory($pluginDir);
        if ($manifest === null) {
            $this->removeDir($tempDir);
            return ['success' => false, 'error' => 'Invalid plugin.json'];
        }

        $errors = $manifest->validate();
        if (!empty($errors)) {
            $this->removeDir($tempDir);
            return ['success' => false, 'error' => 'Manifest errors: ' . implode(', ', $errors)];
        }

        // Determine target directory
        $typeDir = match ($manifest->type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };
        $targetDir = $this->modulesDir . '/' . $typeDir . '/' . $manifest->slug;

        // Check if already installed
        if (is_dir($targetDir)) {
            $this->removeDir($tempDir);
            return ['success' => false, 'error' => "Plugin '{$manifest->slug}' already installed. Uninstall first."];
        }

        // Move to target
        if (!rename($pluginDir, $targetDir)) {
            // Fallback: copy
            $this->copyDir($pluginDir, $targetDir);
        }

        $this->removeDir($tempDir);

        return ['success' => true, 'slug' => $manifest->slug];
    }

    /**
     * Uninstall plugin — remove files.
     */
    public function uninstall(string $slug, string $type = 'addon'): bool
    {
        $typeDir = match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };
        $dir = $this->modulesDir . '/' . $typeDir . '/' . SecurityHelpers::sanitizeSlug($slug);

        if (!is_dir($dir)) {
            return false;
        }

        return $this->removeDir($dir);
    }

    private function findPluginRoot(string $dir): ?string
    {
        if (file_exists($dir . '/plugin.json')) {
            return $dir;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $subDir = $dir . '/' . $entry;
            if (is_dir($subDir) && file_exists($subDir . '/plugin.json')) {
                return $subDir;
            }
        }

        return null;
    }

    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
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
