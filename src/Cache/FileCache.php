<?php
declare(strict_types=1);

namespace OwnPay\Cache;

/**
 * File-based cache driver - shared hosting compatible.
 *
 * Each key is stored as a serialized PHP file in storage/cache/.
 * Files contain: ['expires' => timestamp, 'data' => mixed]
 * Expired files are cleaned on read (lazy GC).
 */
final class FileCache implements CacheInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->path($key);

        if (!is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = @unserialize($content, ['allowed_classes' => false]);
        if ($data === false || !is_array($data)) {
            $this->deleteFile($file);
            return null;
        }

        // Check expiry
        if ($data['expires'] > 0 && $data['expires'] < time()) {
            $this->deleteFile($file);
            return null;
        }

        return $data['data'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $file = $this->path($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $expires = $ttl > 0 ? time() + $ttl : 0;
        $content = serialize(['expires' => $expires, 'data' => $value]);

        // Atomic write: write to temp, then rename
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) !== false) {
            rename($tmp, $file);
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): void
    {
        $file = $this->path($key);
        $this->deleteFile($file);
    }

    public function flush(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo) {
                $realPath = $item->getRealPath();
                if ($realPath === false) {
                    continue;
                }
                // Safety: only delete within cache directory
                if (!str_starts_with($realPath, realpath($this->directory) . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if ($item->isFile() && str_ends_with($item->getFilename(), '.cache')) {
                    @unlink($realPath);
                }
            }
        }
    }

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    // Private

    /**
     * Generate safe filesystem path from cache key.
     * Keys like "settings.general" - "settings/general.cache"
     */
    private function path(string $key): string
    {
        // Sanitize: only allow alphanumeric, dots, hyphens, underscores
        $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $key) ?? $key;
        // Replace dots with directory separator for namespacing
        $safe = str_replace('.', DIRECTORY_SEPARATOR, $safe);
        return $this->directory . DIRECTORY_SEPARATOR . $safe . '.cache';
    }

    /**
     * Safely delete a cache file.
     */
    private function deleteFile(string $file): void
    {
        if (is_file($file)) {
            $realFile = realpath($file);
            $realDir = realpath($this->directory);
            if ($realFile !== false && $realDir !== false
                && str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
                @unlink($realFile);
            }
        }
    }
}
