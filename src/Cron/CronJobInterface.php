<?php
declare(strict_types=1);

namespace OwnPay\Cron;

/**
 * Interface CronJobInterface
 *
 * Defines the standard contract for scheduled background cron tasks.
 *
 * @package OwnPay\Cron
 */
interface CronJobInterface
{
    /**
     * Executes the scheduled background process.
     *
     * @return mixed The execution results matrix or summary.
     */
    public function run(): mixed;
}
