<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Event\EventManager;
use OwnPay\Service\System\Logger;

/**
 * Cron job runner â€” central dispatcher for scheduled tasks.
 *
 * Fires: system.cron.before, system.cron.after
 */
final class CronJobRunner
{
    private EventManager $events;
    private Logger $logger;

    /** @var array<string, array{job: object, schedule: string, last_run?: int}> */
    private array $jobs = [];

    public function __construct(EventManager $events, ?Logger $logger = null)
    {
        $this->events = $events;
        $this->logger = $logger ?? new Logger('cron');
    }

    /**
     * Register a cron job.
     *
     * @param string $name     Unique job name
     * @param object $job      Job instance (must have run() method)
     * @param string $schedule Schedule: 'every_minute', 'every_5min', 'hourly', 'every_6h', 'daily', 'weekly'
     */
    public function register(string $name, object $job, string $schedule): void
    {
        $this->jobs[$name] = [
            'job'      => $job,
            'schedule' => $schedule,
        ];
    }

    /**
     * Run all due jobs.
     * @return array<string, array{status: string, duration: float, result?: mixed, error?: string}>
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

    private function getLastRun(string $name): ?int
    {
        $file = $this->lockFile($name);
        if (file_exists($file)) {
            return (int) file_get_contents($file);
        }
        return null;
    }

    private function recordLastRun(string $name): void
    {
        $dir = dirname($this->lockFile($name));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($this->lockFile($name), (string) time());
    }

    private function lockFile(string $name): string
    {
        return dirname(__DIR__, 2) . '/storage/cron/' . md5($name) . '.lock';
    }
}
