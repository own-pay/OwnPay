<?php
declare(strict_types=1);

namespace OwnPay\Service;

/**
 * Universal Plugin Manager
 *
 * Handles scanning, installing, uninstalling, activating, and deactivating
 * plugins (gateways, addons, themes) in a WordPress-style architecture.
 */
class PluginManager
{
    private const TYPE_DIRS = [
        'gateway' => 'app/modules/gateways',
        'addon' => 'app/modules/addons',
        'theme' => 'app/modules/themes',
    ];

    private static function getBasePath(): string
    {
        return realpath(__DIR__ . '/../../') . '/';
    }

    /**
     * Get the filesystem directory for a given plugin type.
     */
    public static function getTypeDir(string $type): string
    {
        if (!isset(self::TYPE_DIRS[$type])) {
            throw new \InvalidArgumentException("Unknown plugin type: $type");
        }
        return self::getBasePath() . self::TYPE_DIRS[$type];
    }

    /**
     * Scan all installed plugins of a given type.
     *
     * @return array List of plugin info from info.json
     */
    public static function scan(string $type): array
    {
        $dir = self::getTypeDir($type);
        if (!is_dir($dir)) {
            return [];
        }

        $plugins = [];
        $entries = scandir($dir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..')
                continue;
            $pluginDir = $dir . '/' . $entry;
            if (!is_dir($pluginDir))
                continue;

            $infoFile = $pluginDir . '/info.json';
            if (file_exists($infoFile)) {
                $info = json_decode(file_get_contents($infoFile), true);
                if ($info) {
                    $info['_dir'] = $entry;
                    $info['_path'] = $pluginDir;
                    $plugins[] = $info;
                }
            } else {
                // Fallback for gateways that have class.php but no info.json
                if ($type === 'gateway' && file_exists($pluginDir . '/class.php')) {
                    $plugins[] = [
                        'name' => ucwords(str_replace('-', ' ', $entry)),
                        'slug' => $entry,
                        'type' => 'gateway',
                        'version' => '1.0.0',
                        'author' => 'OwnPay',
                        'entrypoint' => 'class.php',
                        '_dir' => $entry,
                        '_path' => $pluginDir,
                        '_generated' => true,
                    ];
                }
            }
        }

        return $plugins;
    }

    /**
     * Get a single plugin's info.
     */
    public static function get(string $type, string $slug): ?array
    {
        $dir = self::getTypeDir($type) . '/' . $slug;
        if (!is_dir($dir))
            return null;

        $infoFile = $dir . '/info.json';
        if (file_exists($infoFile)) {
            $info = json_decode(file_get_contents($infoFile), true);
            if ($info) {
                $info['_dir'] = $slug;
                $info['_path'] = $dir;
                return $info;
            }
        }

        return null;
    }

    /**
     * Install a plugin from a ZIP file.
     *
     * @param string $type Plugin type (gateway, addon, theme)
     * @param array  $file $_FILES entry
     * @return array ['status' => bool, 'message' => string, 'slug' => string|null]
     */
    public static function install(string $type, array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['status' => false, 'message' => 'Upload failed.'];
        }

        // Validate file type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return ['status' => false, 'message' => 'Only ZIP files are allowed.'];
        }

        // Create temp extraction dir
        $tmpDir = sys_get_temp_dir() . '/op_plugin_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $zip = new \ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return ['status' => false, 'message' => 'Cannot open ZIP file.'];
        }

        // Security: check for path traversal
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $entry);
            if (str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
                $zip->close();
                self::deleteDir($tmpDir);
                return ['status' => false, 'message' => 'Security violation: path traversal detected in ZIP.'];
            }
        }

        $zip->extractTo($tmpDir);
        $zip->close();

        // Detect the plugin folder (may be nested under one root dir)
        $contents = array_diff(scandir($tmpDir), ['.', '..']);
        $extractDir = $tmpDir;

        if (count($contents) === 1) {
            $single = $tmpDir . '/' . reset($contents);
            if (is_dir($single)) {
                $extractDir = $single;
            }
        }

        // Validate: must have info.json or class.php (for gateways)
        $infoFile = $extractDir . '/info.json';
        if (!file_exists($infoFile)) {
            if ($type === 'gateway' && file_exists($extractDir . '/class.php')) {
                // Auto-generate info.json from directory name
                $slug = basename($extractDir);
                $info = [
                    'name' => ucwords(str_replace('-', ' ', $slug)),
                    'slug' => $slug,
                    'type' => 'gateway',
                    'version' => '1.0.0',
                    'author' => 'Unknown',
                    'entrypoint' => 'class.php',
                ];
                file_put_contents($infoFile, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                self::deleteDir($tmpDir);
                return ['status' => false, 'message' => 'Missing info.json in the plugin package.'];
            }
        }

        $info = json_decode(file_get_contents($infoFile), true);
        if (!$info || empty($info['slug'])) {
            self::deleteDir($tmpDir);
            return ['status' => false, 'message' => 'Invalid info.json: missing slug.'];
        }

        // Validate type matches
        if (isset($info['type']) && $info['type'] !== $type) {
            self::deleteDir($tmpDir);
            return ['status' => false, 'message' => "Plugin type mismatch: expected '$type', got '{$info['type']}'."];
        }

        $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($info['slug']));
        $targetDir = self::getTypeDir($type) . '/' . $slug;

        // If already exists, remove first (update)
        if (is_dir($targetDir)) {
            self::deleteDir($targetDir);
        }

        // Move extracted plugin to target directory
        rename($extractDir, $targetDir);
        self::deleteDir($tmpDir);

        return ['status' => true, 'message' => "Plugin '$slug' installed successfully.", 'slug' => $slug];
    }

    /**
     * Uninstall (delete) a plugin.
     */
    public static function uninstall(string $type, string $slug): array
    {
        $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($slug));
        $dir = self::getTypeDir($type) . '/' . $slug;

        if (!is_dir($dir)) {
            return ['status' => false, 'message' => "Plugin '$slug' not found."];
        }

        self::deleteDir($dir);

        return ['status' => true, 'message' => "Plugin '$slug' has been uninstalled."];
    }

    /**
     * Recursively delete a directory.
     */
    private static function deleteDir(string $dir): void
    {
        if (!is_dir($dir))
            return;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? self::deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
