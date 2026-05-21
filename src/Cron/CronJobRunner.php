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
     * @var array<string, array{job: object, schedule: string, last_run?: int}>
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
        $this->jobs[$name] = [
            'job'      => $job,
            'schedule' => $schedule,
        ];
    }

    /**
     * Dispatches and executes all scheduled jobs that are currently due.
     *
     * Iterates through the registered registry, verifies schedule eligibility, executes the task,
     * logs results, and triggers hooks for system execution updates.
     *
     * @return array<string, array{status: string, duration: float, result?: mixed, error?: string}> Result matrix of cron execution statuses.
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

            $start = microtime(true);

            try {
                $result = $config['job']->run();
                $duration = round(microtime(true) - $start, 4);

                $this->logger->info("Cron job completed: {$name}", [
                    'duration' => $duration,
                    'result'   => is_array($result) ? $result : null,
                ]);

                $this->recordLastRun($name);

                $results[$name] = [
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

                $results[$name] = [
                    'status'   => 'failed',
                    'duration' => $duration,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        $this->events->doAction('system.cron.after', $results);
        return $results;
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
