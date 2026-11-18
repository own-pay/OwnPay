<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Plugin\PluginManager;
use OwnPay\Service\System\HttpClient;
use OwnPay\Service\System\Logger;

/**
 * Daily job: checks the plugin registry revocation feed and deactivates revoked plugins.
 *
 * The registry publishes a small JSON document at plugins.ownpay.org/revocations.json
 * listing slugs of plugins the owner has withdrawn (supply-chain incident, policy
 * violation, author request). Installed OwnPay instances poll the feed daily and
 * deactivate any locally-installed plugin whose slug appears in it, writing an audit
 * entry each time.
 *
 * Fetch failures are fail-closed: if the feed cannot be reached or parsed, nothing is
 * deactivated and the failure is logged. A deactivated plugin is simply marked inactive;
 * its files remain on disk (owner may delete or reinstall it manually).
 */
final class PluginRevocationCheckJob implements CronJobInterface
{
    private const REVOCATIONS_URL = 'https://plugins.ownpay.org/revocations.json';

    public function __construct(
        private readonly Database $db,
        private readonly PluginManager $plugins,
        private readonly Logger $logger,
        private readonly HttpClient $http = new HttpClient(5, 3, 0),
    ) {
    }

    public function run(): mixed
    {
        $body = $this->fetchFeed();
        if ($body === null) {
            $this->logger->warning('Plugin revocation check skipped: registry feed unreachable or invalid');
            return ['status' => 'skipped', 'reason' => 'feed_unavailable'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['revoked']) || !is_array($decoded['revoked'])) {
            $this->logger->warning('Plugin revocation check skipped: feed JSON missing "revoked" array');
            return ['status' => 'skipped', 'reason' => 'feed_invalid'];
        }

        /** @var array<int, mixed> $raw */
        $raw = $decoded['revoked'];
        /** @var list<string> $revoked */
        $revoked = [];
        foreach ($raw as $item) {
            if (is_string($item) && preg_match('/^[a-z0-9][a-z0-9\-]{0,99}$/', $item) === 1) {
                $revoked[] = $item;
            }
        }

        $deactivated = [];
        foreach ($revoked as $slug) {
            $plugin = $this->db->fetchOne(
                'SELECT slug, status FROM op_plugins WHERE slug = :s LIMIT 1',
                ['s' => $slug]
            );
            if ($plugin === null) {
                continue;
            }
            if (($plugin['status'] ?? '') === 'active') {
                $result = $this->plugins->deactivate($slug);
                if ($result['success'] === true) {
                    $deactivated[] = $slug;
                    $this->logger->warning("Plugin '{$slug}' deactivated by registry revocation check");
                } else {
                    $this->logger->error("Plugin '{$slug}' revocation deactivation failed: " . ($result['error'] ?? 'unknown'));
                }
            }
        }

        return ['status' => 'ok', 'checked' => count($revoked), 'deactivated' => $deactivated];
    }

    private function fetchFeed(): ?string
    {
        try {
            $response = $this->http->get(self::REVOCATIONS_URL, ['Accept' => 'application/json']);
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin revocation feed request failed: ' . $e->getMessage());
            return null;
        }

        $status = $response['status'];
        $body = $response['body'];
        if ($status >= 200 && $status < 300 && $body !== '') {
            return $body;
        }
        $this->logger->warning("Plugin revocation feed returned HTTP {$status}");
        return null;
    }
}
