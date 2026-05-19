<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Update\UpdateService;
use OwnPay\Support\DateHelper;

/**
 * Update check cron job — runs daily, triggers update during night window.
 */
final class UpdateCheckJob
{
    private UpdateService $updateService;

    public function __construct(UpdateService $updateService)
    {
        $this->updateService = $updateService;
    }

    /**
     * Check for updates and auto-apply during night window (2-5 AM).
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

        if ($autoUpdate && $inNightWindow && !empty($check['url'])) {
            $result = $this->updateService->execute($check['version'], $check['url']);
            return [
                'action'  => 'updated',
                'version' => $check['version'],
                'success' => $result['success'],
                'error'   => $result['error'] ?? null,
            ];
        }

        return [
            'action'  => 'available',
            'version' => $check['version'],
            'message' => $autoUpdate ? 'Update available, waiting for night window (2-5 AM)' : 'Auto-update disabled',
        ];
    }
}
