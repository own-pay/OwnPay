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
            mkdir($this->directory, 0700, true);
        } else {
            // Defence-in-depth (CACHE-1): if the directory was created
            // by a previous version with broader perms (e.g. 0755 on a
            // shared host where PHP-FPM runs as www-data / nobody for all
            // tenants), tighten it now so neighbouring users cannot
            // readfile() the cached serialised settings/PII.
            @chmod($this->directory, 0700);
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
            mkdir($dir, 0700, true);
        } else {
            // (CACHE-1) tighten existing per-key subdirectories too.
            @chmod($dir, 0700);
        }

        $expires = $ttl > 0 ? time() + $ttl : 0;
        $content = serialize(['expires' => $expires, 'data' => $value]);

        // Atomic write: write to temp, then rename
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) !== false) {
            rename($tmp, $file);
            // (CACHE-1) file_put_contents respects the process umask,
            // typically producing 0644 - readable by other tenants on
            // shared hosting. Force 0600 so only the owner can read.
            @chmod($file, 0600);
        }
    }

    public function add(string $key, mixed $value, int $ttl = 3600): bool
    {
        // If a non-expired entry already exists, fail. get() has the side
        // effect of deleting expired files, so a successful return here
        // guarantees the file is either absent or has just been cleaned up.
        if ($this->get($key) !== null) {
            return false;
        }

        $file = $this->path($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $expires = $ttl > 0 ? time() + $ttl : 0;
        $content = serialize(['expires' => $expires, 'data' => $value]);

        // Atomic create-or-fail: fopen with mode "x" creates the file only
        // if it does not already exist; otherwise it returns false and emits
        // an E_WARNING (suppressed via @). This closes the TOCTOU window
        // between the get() check above and the file creation - if another
        // process created the file in between, fopen("x") will fail and we
        // correctly report that the add() did not succeed.
        $handle = @fopen($file, "x");
        if ($handle === false) {
            return false;
        }

        fwrite($handle, $content);
        fclose($handle);
        return true;
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
                if ($item->isFile()) {
                    $name = $item->getFilename();
                    // Final cache files end in `.cache`. Orphaned temp
                    // files from interrupted writes (CACHE-3) are named
                    // `<key>.cache.tmp.<hex>`; reclaim them here too so
                    // they don't accumulate and exhaust the disk quota.
                    if (str_ends_with($name, '.cache')
                        || str_contains($name, '.tmp.')
                    ) {
                        @unlink($realPath);
                    }
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
