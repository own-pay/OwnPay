<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Event\EventManager;
use OwnPay\Service\System\Logger;

/**
 * Class CronJobRunner
 *
 * Central dispatcher for scheduled enterprise tasks within the OwnPay infrastructure.
 * Manages the registration, scheduling, execution, and execution status checking for all cron jobs,
 * utilizing file-based run locks to avoid race conditions.
 *
 * Fires system hooks:
 * - system.cron.before: Dispatched prior to running due scheduled tasks.
 * - system.cron.after: Dispatched after all scheduled tasks have completed execution.
 *
 * @package OwnPay\Cron
 */
final class CronJobRunner
{
    /**
     * @var EventManager The application hook and filter system manager.
     */
    private EventManager $events;

    /**
     * @var Logger Logger for auditing cron execution performance and logging failures.
     */
    private Logger $logger;

    /**
     * Cache array holding all registered cron tasks.
     *
     * @var array<string, array{job: \OwnPay\Cron\CronJobInterface, schedule: string, last_run?: int}>
     */
    private array $jobs = [];

    /**
     * CronJobRunner constructor.
     *
     * @param EventManager $events The event hook manager.
     * @param Logger|null  $logger Optional logger service; defaults to standard cron channel.
     */
    public function __construct(EventManager $events, ?Logger $logger = null)
    {
        $this->events = $events;
        $this->logger = $logger ?? new Logger('cron');
    }

    /**
     * Registers a scheduled task with the runner.
     *
     * @param string $name     Unique cron job identifier.
     * @param object $job      The job executor instance containing a public run() method.
     * @param string $schedule Interval string pattern (e.g. 'every_minute', 'every_5min', 'hourly', 'every_6h', 'daily', 'weekly').
     * @return void
     */
    public function register(string $name, object $job, string $schedule): void
    {
        /** @var \OwnPay\Cron\CronJobInterface $jobCast */
        $jobCast = $job;
        $this->jobs[$name] = [
            'job'      => $jobCast,
            'schedule' => $schedule,
        ];
    }

    /**
     * Retrieves all registered cron jobs.
     *
     * @return array<string, array{job: \OwnPay\Cron\CronJobInterface, schedule: string}>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * Retrieves the Unix timestamp of the job's last recorded successful run.
     *
     * @param string $name Unique job name identifier.
     * @return int|null Last execution timestamp, or null if it has never run.
     */
    public function getLastRunTime(string $name): ?int
    {
        return $this->getLastRun($name);
    }

    /**
     * Manually dispatches and executes a single registered job by name.
     *
     * @param string $name The name of the job to run.
     * @return array{status: string, duration: float, result?: mixed, error?: string} Result matrix of the job execution status.
     * @throws \InvalidArgumentException If the job name is not registered.
     */
    public function runJob(string $name): array
    {
        if (!isset($this->jobs[$name])) {
            throw new \InvalidArgumentException("Cron job not registered: {$name}");
        }

        $config = $this->jobs[$name];

        $result = $this->withLock($name, function () use ($name, $config) {
            $start = microtime(true);
            try {
                $jobResult = $config['job']->run();
                $duration = round(microtime(true) - $start, 4);

                $this->logger->info("Cron job manually completed: {$name}", [
                    'duration' => $duration,
                    'result'   => is_array($jobResult) ? $jobResult : null,
                ]);

                $this->recordLastRun($name);

                return [
                    'status'   => 'completed',
                    'duration' => $duration,
                    'result'   => $jobResult,
                ];
            } catch (\Throwable $e) {
                $duration = round(microtime(true) - $start, 4);
                $this->logger->error("Cron job manually failed: {$name}", [
                    'error'    => $e->getMessage(),
                    'duration' => $duration,
                ]);

                return [
                    'status'   => 'failed',
                    'duration' => $duration,
                    'error'    => $e->getMessage(),
                ];
            }
        });

        return is_array($result) ? $result : ['status' => 'locked', 'duration' => 0.0];
    }

    /**
     * Dispatches and executes all scheduled jobs that are currently due.
     *
     * @return array<string, array{status: string, duration?: float, result?: mixed, error?: string}> Result matrix of all tasks run in the current session.
     */
    public function run(): array
    {
        $this->events->doAction('system.cron.before');
        $results = [];

        foreach ($this->jobs as $name => $config) {
            if (!$this->isDue($name, $config['schedule'])) {
                $results[$name] = ['status' => 'skipped'];
                continue;
            }

            $jobResult = $this->withLock($name, function () use ($name, $config) {
                if (!$this->isDue($name, $config['schedule'])) {
                    return ['status' => 'skipped'];
                }

                $start = microtime(true);
                try {
                    $result = $config['job']->run();
                    $duration = round(microtime(true) - $start, 4);

                    $this->logger->info("Cron job completed: {$name}", [
                        'duration' => $duration,
                        'result'   => is_array($result) ? $result : null,
                    ]);

                    $this->recordLastRun($name);

                    return [
                        'status'   => 'completed',
                        'duration' => $duration,
                        'result'   => $result,
                    ];
                } catch (\Throwable $e) {
                    $duration = round(microtime(true) - $start, 4);
                    $this->logger->error("Cron job failed: {$name}", [
                        'error'    => $e->getMessage(),
                        'duration' => $duration,
                    ]);

                    return [
                        'status'   => 'failed',
                        'duration' => $duration,
                        'error'    => $e->getMessage(),
                    ];
                }
            });

            // Null result means the lock was held by a concurrent run.
            $results[$name] = is_array($jobResult) ? $jobResult : ['status' => 'locked'];
        }

        $this->events->doAction('system.cron.after', $results);
        return $results;
    }

    /**
     * Runs a callback while holding an exclusive advisory lock for the job.
     *
     * Uses flock(LOCK_EX | LOCK_NB) so concurrent invocations on the same host
     * serialize: the first acquires the lock and runs; a concurrent caller gets
     * null immediately rather than executing the job a second time.
     *
     * @template T
     * @param string $name Unique job name identifier.
     * @param callable():T $fn The work to perform under the lock.
     * @return T|null The callback result, or null if the lock could not be acquired.
     */
    private function withLock(string $name, callable $fn): mixed
    {
        $lockPath = $this->runLockFile($name);
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            $this->logger->warning("Cron run-lock unavailable for {$name}; executing without concurrency guard");
            return $fn();
        }

        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                return null;
            }
            try {
                return $fn();
            } finally {
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Computes the absolute path to the exclusive run-lock for a job.
     *
     * Distinct from lockFile() (which stores the last-run timestamp) so the
     * concurrency lock and the schedule marker never clobber each other.
     *
     * @param string $name Unique job name identifier.
     * @return string Absolute file path.
     */
    private function runLockFile(string $name): string
    {
        return dirname(__DIR__, 2) . '/storage/cron/' . md5($name) . '.running.lock';
    }

    /**
     * Determines whether the specified job is due for execution based on schedule and last run timestamp.
     *
     * @param string $name     Unique job name identifier.
     * @param string $schedule Schedule interval string format.
     * @return bool True if the job should run, false otherwise.
     */
    private function isDue(string $name, string $schedule): bool
    {
        $lastRun = $this->getLastRun($name);
        if ($lastRun === null) {
            return true;
        }

        $elapsed = time() - $lastRun;

        return match ($schedule) {
            'every_minute' => $elapsed >= 60,
            'every_5min'   => $elapsed >= 300,
            'hourly'       => $elapsed >= 3600,
            'every_6h'     => $elapsed >= 21600,
            'daily'        => $elapsed >= 86400,
            'weekly'       => $elapsed >= 604800,
            default        => $elapsed >= 3600,
        };
    }

    /**
     * Retrieves the Unix timestamp of the job's last recorded successful run.
     *
     * @param string $name Unique job name identifier.
     * @return int|null Last execution timestamp, or null if it has never run.
     */
    private function getLastRun(string $name): ?int
    {
        $file = $this->lockFile($name);
        if (file_exists($file)) {
            return (int) file_get_contents($file);
        }
        return null;
    }

    /**
     * Writes the current Unix timestamp to the job's lock file to record successful execution.
     *
     * @param string $name Unique job name identifier.
     * @return void
     */
    private function recordLastRun(string $name): void
    {
        $dir = dirname($this->lockFile($name));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($this->lockFile($name), (string) time());
    }

    /**
     * Computes the absolute file path to the cron lock file for the given job.
     *
     * @param string $name Unique job name identifier.
     * @return string Absolute file path.
     */
    private function lockFile(string $name): string
    {
        return dirname(__DIR__, 2) . '/storage/cron/' . md5($name) . '.lock';
    }
}
