<?php
declare(strict_types=1);

namespace OwnPay\Queue;

use Ramsey\Uuid\Uuid;

/**
 * File-based queue driver â€” shared hosting compatible.
 *
 * Each job is a JSON file in storage/queue/{queue_name}/.
 * Files named: {timestamp}_{jobId}.json
 * Jobs with delay are timestamped into the future.
 *
 * Concurrency: file locking prevents double-processing.
 */
final class FileQueue implements QueueInterface
{
    private string $baseDir;

    public function __construct(string $directory)
    {
        $this->baseDir = rtrim($directory, '/\\');
    }

    public function push(string $queue, string $handler, array $payload = [], int $delay = 0): string
    {
        $dir = $this->queueDir($queue);
        $jobId = Uuid::uuid4()->toString();
        $availableAt = time() + $delay;

        $job = [
            'id'           => $jobId,
            'queue'        => $queue,
            'handler'      => $handler,
            'payload'      => $payload,
            'attempts'     => 0,
            'available_at' => $availableAt,
            'created_at'   => time(),
            'error'        => null,
        ];

        $filename = sprintf('%010d_%s.json', $availableAt, $jobId);
        $filepath = $dir . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($filepath, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        return $jobId;
    }

    public function pop(string $queue = 'default'): ?array
    {
        $dir = $this->queueDir($queue);

        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false || count($files) === 0) {
            return null;
        }

        sort($files); // Oldest first (sorted by timestamp prefix)

        $now = time();

        foreach ($files as $file) {
            // Try exclusive lock â€” prevents double-processing
            $fp = @fopen($file, 'r');
            if ($fp === false) {
                continue;
            }

            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                continue; // Another worker has it
            }

            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($content === false) {
                continue;
            }

            $job = json_decode($content, true);
            if (!is_array($job)) {
                @unlink($file); // Corrupt file
                continue;
            }

            // Check availability time
            if (($job['available_at'] ?? 0) > $now) {
                continue; // Not yet available
            }

            // Move to processing: rename with .processing suffix
            $processingFile = $file . '.processing';
            if (!@rename($file, $processingFile)) {
                continue; // Another worker got it
            }

            // Increment attempts
            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
            $job['_file'] = $processingFile;

            return $job;
        }

        return null;
    }

    public function complete(string $jobId): void
    {
        $this->removeJobFiles($jobId);
    }

    public function fail(string $jobId, string $error): void
    {
        // Move to failed directory
        $files = $this->findJobFiles($jobId);
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $job = json_decode($content, true);
            if (!is_array($job)) {
                continue;
            }

            $job['error'] = $error;
            $job['failed_at'] = time();

            $failedDir = $this->baseDir . DIRECTORY_SEPARATOR . 'failed';
            if (!is_dir($failedDir)) {
                mkdir($failedDir, 0755, true);
            }

            $failedFile = $failedDir . DIRECTORY_SEPARATOR . basename($file, '.processing') . '.failed.json';
            file_put_contents($failedFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            @unlink($file);
        }
    }

    public function retry(string $jobId, int $delay = 60): void
    {
        // Find in failed directory
        $failedDir = $this->baseDir . DIRECTORY_SEPARATOR . 'failed';
        if (!is_dir($failedDir)) {
            return;
        }

        $files = glob($failedDir . DIRECTORY_SEPARATOR . "*{$jobId}*.json");
        if ($files === false || count($files) === 0) {
            return;
        }

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $job = json_decode($content, true);
            if (!is_array($job)) {
                continue;
            }

            // Re-push with delay
            $this->push(
                $job['queue'] ?? 'default',
                $job['handler'] ?? '',
                $job['payload'] ?? [],
                $delay
            );

            @unlink($file);
        }
    }

    public function size(string $queue = 'default'): int
    {
        $dir = $this->queueDir($queue);
        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        return $files !== false ? count($files) : 0;
    }

    public function clear(string $queue = 'default'): void
    {
        $dir = $this->queueDir($queue);
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            @unlink($file);
        }

        // Also clear processing files
        $procFiles = glob($dir . DIRECTORY_SEPARATOR . '*.processing');
        if ($procFiles !== false) {
            foreach ($procFiles as $file) {
                @unlink($file);
            }
        }
    }

    // â”€â”€â”€ Private â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function queueDir(string $queue): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $queue) ?? $queue;
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $safe;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function removeJobFiles(string $jobId): void
    {
        $files = $this->findJobFiles($jobId);
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * @return string[]
     */
    private function findJobFiles(string $jobId): array
    {
        $result = [];

        // Search all queue directories
        $dirs = glob($this->baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return $result;
        }

        foreach ($dirs as $dir) {
            $files = glob($dir . DIRECTORY_SEPARATOR . "*{$jobId}*");
            if ($files !== false) {
                $result = array_merge($result, $files);
            }
        }

        return $result;
    }
}
