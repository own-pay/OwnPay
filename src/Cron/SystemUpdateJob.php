<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Event\EventManager;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\System\EnvironmentService;
use OwnPay\Service\System\HttpClient;
use OwnPay\Support\DateHelper;

/**
 * Class SystemUpdateJob
 *
 * Enterprise cron job executing automatic system update checks against the official OwnPay release channels.
 * Fetches remote release manifests, evaluates semantic version thresholds, and records updates in the
 * `op_system_settings` persistent store, triggering hooks when updates are available.
 *
 * Fires system hooks:
 * - system.update.available: Triggered when a version greater than the active running release is detected.
 *
 * @package OwnPay\Cron
 */
final class SystemUpdateJob
{
    /**
     * Remote URL serving the latest JSON release manifest.
     */
    private const MANIFEST_URL = 'https://update.ownpay.org/manifest.json';

    /**
     * Minimum cooldown period between automatic update checks (10 hours).
     */
    private const CHECK_INTERVAL_SECONDS = 10 * 3600;

    /**
     * @var string The active semantic version string of the running application.
     */
    private string $currentVersion;

    /**
     * @var EventManager The enterprise event hook and action dispatcher.
     */
    private EventManager $events;

    /**
     * @var SettingsRepository Repository for system configuration settings.
     */
    private SettingsRepository $settings;

    /**
     * SystemUpdateJob constructor.
     *
     * @param string             $currentVersion The current system version.
     * @param EventManager       $events         The system event manager.
     * @param SettingsRepository $settings       The system settings repository.
     */
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
     * Runs the automatic system updates check cycle.
     *
     * Validates that auto-updates are enabled, checks rate-limiting, queries the remote manifest feed,
     * matches update channels, and triggers event hooks if an update is found.
     *
     * @return array<string, mixed> Execution status metrics indicating skipped status, errors, or version updates.
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

        $channels = $manifest['channels'] ?? null;
        if (!is_array($channels)) {
            return ['error' => 'manifest missing channels'];
        }
        $channelData = $channels[$updateChannel] ?? null;

        $updateAvailable = false;
        $latestName = null;
        $latestCode = null;

        if (is_array($channelData)) {
            $latestName = $channelData['latest_version_name'] ?? null;
            $latestCode = $channelData['latest_version_code'] ?? null;

            if ($latestCode !== null && is_scalar($latestCode) && version_compare((string) $latestCode, $this->currentVersion, '>')) {
                $updateAvailable = true;
            }
        }

        if ($updateAvailable && is_scalar($latestName)) {
            $latestNameStr = (string) $latestName;
            $this->events->doAction('system.update.available', [
                'current_version' => $this->currentVersion,
                'latest_version'  => $latestNameStr,
                'channel'         => $updateChannel,
            ]);

            $this->settings->set('runtime', 'last_update_version', $latestNameStr);

            return [
                'update_available' => true,
                'channel' => $updateChannel,
                'latest_version' => $latestNameStr,
            ];
        }

        return [
            'update_available' => false,
            'channel' => $updateChannel,
            'current_version' => $this->currentVersion,
        ];
    }
}
