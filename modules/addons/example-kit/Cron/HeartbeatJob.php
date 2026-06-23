<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\ExampleKit\Cron;

use OwnPay\Cron\CronJobInterface;

/**
 * Manifest-declared cron job (see manifest.json "cron"). When the plugin is active, the CronJobRunner
 * factory schedules it under the name "plugin:example-kit:heartbeat" on the declared interval.
 */
final class HeartbeatJob implements CronJobInterface
{
    /**
     * @return array{ok: bool, job: string}
     */
    public function run(): mixed
    {
        // A real job would do periodic work here; this example simply reports a heartbeat result.
        return ['ok' => true, 'job' => 'example-kit:heartbeat'];
    }
}
