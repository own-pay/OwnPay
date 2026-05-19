<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Event\EventManager;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\System\EnvironmentService;
use OwnPay\Service\System\HttpClient;
use OwnPay\Support\DateHelper;

/**
 * SystemUpdateJob — periodic check for new OwnPay releases.
 *
 * Fetches the update manifest at most once every 10 hours, compares the
 * current version against the configured channel (stable|beta), and fires
 * the `system.update.available` event when a newer release exists.
 *
 * Uses SettingsRepository (op_system_settings) for persistent state.
 */
final class SystemUpdateJob
{
    private const MANIFEST_URL = 'https://update.ownpay.org/manifest.json';
    private const CHECK_INTERVAL_SECONDS = 10 * 3600;

    private string $currentVersion;
    private EventManager $events;
    private SettingsRepository $settings;

    public function __construct(
        string $currentVersion,
        EventManager $events,
        SettingsRepository $settings
    ) {
        $this->currentVersion = $currentVersion;
        $this->events = $events;
        $this->settings = $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $autoUpdate = $this->settings->get('general', 'auto_update', '0');

        if ($autoUpdate !== '1') {
            return ['skipped' => 'auto_update disabled'];
        }

        $now = DateHelper::now();
        $lastCheck = $this->settings->get('runtime', 'last_auto_update_check');

        if ($lastCheck !== null && DateHelper::secondsSince($lastCheck) < self::CHECK_INTERVAL_SECONDS) {
            return ['skipped' => 'within check interval'];
        }

        $this->settings->set('runtime', 'last_auto_update_check', $now);

        try {
            $manifestRaw = (new HttpClient(10, 5))->get(self::MANIFEST_URL)['body'];
            $manifest = json_decode($manifestRaw, true);
        } catch (\Throwable $e) {
            return ['error' => 'manifest fetch failed: ' . $e->getMessage()];
        }

        if (!is_array($manifest)) {
            return ['error' => 'manifest parse failed'];
        }

        $updateChannel = $this->settings->get('general', 'update_channel', 'stable');
        if ($updateChannel !== 'beta') {
            $updateChannel = 'stable';
        }

        $channelData = $manifest['channels'][$updateChannel] ?? null;

        $updateAvailable = false;
        $latestName = null;
        $latestCode = null;

        if ($channelData) {
            $latestName = $channelData['latest_version_name'] ?? null;
            $latestCode = $channelData['latest_version_code'] ?? null;

            if ($latestCode !== null && version_compare((string) $latestCode, $this->currentVersion, '>')) {
                $updateAvailable = true;
            }
        }

        if ($updateAvailable) {
            $this->events->doAction('system.update.available', [
                'current_version' => $this->currentVersion,
                'latest_version'  => $latestName,
                'channel'         => $updateChannel,
            ]);

            $this->settings->set('runtime', 'last_update_version', (string) $latestName);

            return [
                'update_available' => true,
                'channel' => $updateChannel,
                'latest_version' => $latestName,
            ];
        }

        return [
            'update_available' => false,
            'channel' => $updateChannel,
            'current_version' => $this->currentVersion,
        ];
    }
}
