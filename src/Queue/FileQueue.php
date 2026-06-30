<?php
declare(strict_types=1);

namespace OwnPay\Queue;

use Ramsey\Uuid\Uuid;

/**
 * Class FileQueue
 *
 * File-based implementation of the QueueInterface, providing basic job queuing compatibility
 * for shared hosting environments. Job data is stored as serialized JSON files with built-in
 * concurrency controls using standard PHP file locks (flock).
 *
 * @package OwnPay\Queue
 */
final class FileQueue implements QueueInterface
{
    /**
     * @var string The base directory path where queue folders are structured.
     */
    private string $baseDir;

    /**
     * FileQueue constructor.
     *
     * @param string $directory The path to the root queue directory.
     */
    public function __construct(string $directory)
    {
        $this->baseDir = rtrim($directory, '/\\');
    }

    /**
     * Pushes a new job message payload onto the specified queue.
     *
     * @param string $queue The target queue name.
     * @param string $handler The fully qualified class name of the job handler.
     * @param array<string, mixed> $payload The contextual data payload for the job.
     * @param int $delay The execution delay in seconds. Defaults to 0.
     * @return string The generated UUID identifier for the job.
     */
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

    /**
     * Extracts and retrieves the next available job message from the specified queue.
     *
     * Applies non-blocking exclusive file locking (flock) to prevent race conditions
     * and double-processing by multiple background workers.
     *
     * @param string $queue The queue name. Defaults to 'default'.
     * @return array<string, mixed>|null The job data array, or null if no jobs are available.
     * @phpstan-ignore-next-line
     */
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

        sort($files); // Process oldest pending jobs first.

        $now = time();

        foreach ($files as $file) {
            // Attempt an exclusive read lock to ensure single worker processing execution.
            $fp = @fopen($file, 'r');
            if ($fp === false) {
                continue;
            }

            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                continue;
            }

            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($content === false) {
                continue;
            }

            $job = json_decode($content, true);
            if (!is_array($job)) {
                @unlink($file); // Remove corrupted job definition files.
                continue;
            }

            // Verify if the job delay interval has lapsed.
            if (($job['available_at'] ?? 0) > $now) {
                continue;
            }

            // Flag the job file status to processing.
            $processingFile = $file . '.processing';
            if (!@rename($file, $processingFile)) {
                continue;
            }

            // Increment execution count metadata.
            $attempts = $job['attempts'] ?? 0;
            $job['attempts'] = (is_numeric($attempts) ? (int) $attempts : 0) + 1;
            $job['_file'] = $processingFile;

            return $job;
        }

        return null;
    }

    /**
     * Marks a job as completed and deletes its persistent job file from the active directory.
     *
     * @param string $jobId The UUID identifier of the job.
     * @return void
     */
    public function complete(string $jobId): void
    {
        $this->removeJobFiles($jobId);
    }

    /**
     * Marks a job as failed, registering the error trace and moving the record to the failed archive.
     *
     * @param string $jobId The UUID identifier of the job.
     * @param string $error The failure reason or stack trace details.
     * @return void
     */
    public function fail(string $jobId, string $error): void
    {
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

    /**
     * Re-pushes a failed job back onto its original queue for reprocessing.
     *
     * @param string $jobId The UUID identifier of the job.
     * @param int $delay The delay interval in seconds before the retried job is made active. Defaults to 60.
     * @return void
     */
    public function retry(string $jobId, int $delay = 60): void
    {
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

            $queueName = is_string($job['queue'] ?? null) ? $job['queue'] : 'default';
            $handlerClass = is_string($job['handler'] ?? null) ? $job['handler'] : '';
            $payloadData = [];
            if (isset($job['payload']) && is_array($job['payload'])) {
                foreach ($job['payload'] as $k => $v) {
                    $payloadData[(string) $k] = $v;
                }
            }

            $this->push(
                $queueName,
                $handlerClass,
                $payloadData,
                $delay
            );

            @unlink($file);
        }
    }

    /**
     * Retrieves the count of pending job messages currently in the specified queue.
     *
     * @param string $queue The target queue name. Defaults to 'default'.
     * @return int The number of pending jobs.
     */
    public function size(string $queue = 'default'): int
    {
        $dir = $this->queueDir($queue);
        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        return $files !== false ? count($files) : 0;
    }

    /**
     * Deletes all job messages (including pending and currently processing) from the specified queue.
     *
     * @param string $queue The target queue name. Defaults to 'default'.
     * @return void
     */
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

        $procFiles = glob($dir . DIRECTORY_SEPARATOR . '*.processing');
        if ($procFiles !== false) {
            foreach ($procFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Resolves and prepares the directory path for the specified queue name.
     *
     * @param string $queue The queue name.
     * @return string The resolved absolute queue directory path.
     */
    private function queueDir(string $queue): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $queue) ?? $queue;
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $safe;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Deletes all files matching the given job ID from queue directories.
     *
     * @param string $jobId The UUID identifier of the job.
     * @return void
     */
    private function removeJobFiles(string $jobId): void
    {
        $files = $this->findJobFiles($jobId);
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Scans queue directories to retrieve absolute file paths related to the specified job ID.
     *
     * @param string $jobId The UUID identifier of the job.
     * @return array<int, string> List of matching absolute file paths.
     */
    private function findJobFiles(string $jobId): array
    {
        $result = [];

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
