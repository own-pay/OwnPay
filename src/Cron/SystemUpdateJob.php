<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Event\EventManager;
use OwnPay\Service\DateTimeService;
use OwnPay\Service\EnvironmentService;
use OwnPay\Service\HttpClient;

/**
 * SystemUpdateJob — periodic check for new OwnPay releases.
 *
 * Fetches the update manifest at most once every 10 hours, compares the
 * current version against the configured channel (stable|beta), and fires
 * the `system.update.available` event when a newer release exists.
 *
 * Previously embedded in index.php (~50 lines); extracted here as part of
 * Milestone 7 of the codebase audit.
 */
final class SystemUpdateJob
{
    private const MANIFEST_URL = 'https://updates.OwnPay.com/manifest.json';
    private const CHECK_INTERVAL_SECONDS = 10 * 3600;

    /** @var array{version_code: string, version_name: string} */
    private array $currentVersion;

    /**
     * @param array{version_code: string, version_name: string} $currentVersion
     */
    public function __construct(array $currentVersion)
    {
        $this->currentVersion = $currentVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $automaticUpdate = EnvironmentService::get('system-settings-automatic_update') ?? '';

        if ($automaticUpdate !== 'yes') {
            return ['skipped' => 'automatic_update disabled'];
        }

        $now = DateTimeService::getCurrentDatetime('Y-m-d H:i:s');
        $lastCheck = EnvironmentService::get('last-auto-update-check');

        if (empty($lastCheck)) {
            EnvironmentService::set('last-auto-update-check', $now);
            return ['initialized' => 'last-auto-update-check'];
        }

        if (strtotime($now) - strtotime($lastCheck) < self::CHECK_INTERVAL_SECONDS) {
            return ['skipped' => 'within check interval'];
        }

        EnvironmentService::set('last-auto-update-check', $now);

        $manifestRaw = HttpClient::get(self::MANIFEST_URL) ?? '';
        $manifest = json_decode($manifestRaw, true);

        if (!is_array($manifest)) {
            return ['error' => 'manifest fetch/parse failed'];
        }

        $currentCode = $this->currentVersion['version_code'];
        $currentName = $this->currentVersion['version_name'];

        $channelSetting = EnvironmentService::get('system-settings-update_channel');
        $updateChannel = (empty($channelSetting) || $channelSetting === 'stable') ? 'stable' : 'beta';

        $channelData = $manifest['channels'][$updateChannel] ?? null;

        $updateAvailable = false;
        $latestName = null;
        $latestCode = null;

        if ($channelData) {
            $latestName = $channelData['latest_version_name'] ?? null;
            $latestCode = $channelData['latest_version_code'] ?? null;

            if ($latestCode !== null && version_compare((string) $latestCode, (string) $currentCode, '>')) {
                $updateAvailable = true;
            }
        }

        if ($updateAvailable) {
            EventManager::getInstance()->doAction('system.update.available', [
                'current_version_name' => $currentName,
                'current_version_code' => $currentCode,
                'latest_version_name' => $latestName,
                'latest_version_code' => $latestCode,
            ]);

            EnvironmentService::set('last-update-version-name', (string) $latestName);
            EnvironmentService::set('last-update-version', (string) $latestCode);

            return [
                'update_available' => true,
                'channel' => $updateChannel,
                'latest_version' => $latestName,
            ];
        }

        EnvironmentService::set('last-update-version-name', $currentName);
        EnvironmentService::set('last-update-version', $currentCode);

        return [
            'update_available' => false,
            'channel' => $updateChannel,
            'current_version' => $currentName,
        ];
    }
}
