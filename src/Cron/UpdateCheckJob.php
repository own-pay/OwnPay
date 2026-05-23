<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Update\UpdateService;
use OwnPay\Support\DateHelper;

/**
 * Class UpdateCheckJob
 *
 * Enterprise cron job executing system update validations and executing automated deployments.
 * Queries the core update manager for release packages and executes downloads/installs under
 * safe operational schedules, specifically targeting a low-traffic nighttime maintenance window (2-5 AM).
 *
 * @package OwnPay\Cron
 */
final class UpdateCheckJob
{
    /**
     * @var UpdateService Service managing system self-update downloads and script executions.
     */
    private UpdateService $updateService;

    /**
     * UpdateCheckJob constructor.
     *
     * @param UpdateService $updateService Service managing system self-update downloads and script executions.
     */
    public function __construct(UpdateService $updateService)
    {
        $this->updateService = $updateService;
    }

    /**
     * Runs the daily system update validation and maintenance cycle.
     *
     * Inspects current updates availability, checks configuration flags and active local server hour,
     * and performs unattended updates deployment if inside the scheduled night window.
     *
     * @return array{action: string, message?: string, version?: string, success?: bool, error?: string|null} The update execution summary.
     */
    public function run(): array
    {
        $check = $this->updateService->check();

        if (!$check['available']) {
            return ['action' => 'none', 'message' => 'No updates available'];
        }

        $autoUpdate = (getenv('AUTO_UPDATE') ?: 'false') === 'true';
        $hour = DateHelper::currentHour();
        $inNightWindow = ($hour >= 2 && $hour <= 5);
        $version = $check['version'] ?? '0.1.0';

        if ($autoUpdate && $inNightWindow && !empty($check['url'])) {
            $result = $this->updateService->execute($version);
            return [
                'action'  => 'updated',
                'version' => $version,
                'success' => $result['success'],
                'error'   => $result['error'] ?? null,
            ];
        }

        return [
            'action'  => 'available',
            'version' => $version,
            'message' => $autoUpdate ? 'Update available, waiting for night window (2-5 AM)' : 'Auto-update disabled',
        ];
    }
}
