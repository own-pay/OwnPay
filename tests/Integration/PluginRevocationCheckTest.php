<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Cron\PluginRevocationCheckJob;
use OwnPay\Plugin\PluginManager;
use OwnPay\Service\System\HttpClient;
use OwnPay\Service\System\Logger;

/**
 * Integration tests for the plugin registry revocation check.
 *
 * The job polls plugins.ownpay.org/revocations.json once per run. These tests prove the job
 * fails CLOSED (plugin stays active) when the feed cannot be reached or parsed, and deactivates
 * the plugin only when the feed explicitly lists its slug.
 */
final class PluginRevocationCheckTest extends IntegrationTestCase
{
    private Database $db;
    private Container $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance();
        $this->db->execute("DELETE FROM op_plugins WHERE slug = 'revocable-test-plugin'");
        $this->c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->c);
        $this->c->instance(Database::class, $this->db);
        $this->seedActivePlugin();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_plugins WHERE slug = 'revocable-test-plugin'");
        }
        HttpClient::$mockResponses = null;
        parent::tearDown();
    }

    private function seedActivePlugin(): void
    {
        $this->db->execute(
            "INSERT INTO op_plugins (slug, name, type, version, entrypoint, capabilities, manifest, status)
             VALUES ('revocable-test-plugin', 'Revocable Test Plugin', 'addon', '1.0.0', 'Plugin.php', '[]', '{}', 'active')"
        );
    }

    private function runJob(): array
    {
        $logger = new Logger('test', sys_get_temp_dir());
        /** @var PluginManager $plugins */
        $plugins = $this->c->get(PluginManager::class);
        $result = (new PluginRevocationCheckJob($this->db, $plugins, $logger))->run();
        return is_array($result) ? $result : ['status' => 'unknown'];
    }

    private function pluginStatus(): string
    {
        $row = $this->db->fetchOne(
            "SELECT status FROM op_plugins WHERE slug = 'revocable-test-plugin' LIMIT 1"
        );
        return is_array($row) && isset($row['status']) && is_string($row['status']) ? $row['status'] : 'missing';
    }

    public function testFailsClosedWhenFeedUnreachable(): void
    {
        HttpClient::$mockResponses = []; // every URL throws 'No mock response configured'
        $result = $this->runJob();
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('active', $this->pluginStatus(), 'plugin must stay ACTIVE when the feed is unreachable');
    }

    public function testFailsClosedWhenFeedIsMalformed(): void
    {
        HttpClient::$mockResponses = [
            'https://plugins.ownpay.org/revocations.json' => ['status' => 200, 'body' => '<html>not json</html>', 'headers' => []],
        ];
        $result = $this->runJob();
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('active', $this->pluginStatus(), 'plugin must stay ACTIVE when the feed JSON is unusable');
    }

    public function testDeactivatesPluginListedInRevocationFeed(): void
    {
        HttpClient::$mockResponses = [
            'https://plugins.ownpay.org/revocations.json' => [
                'status' => 200,
                'body' => '{"revoked":["revocable-test-plugin"]}',
                'headers' => [],
            ],
        ];
        $result = $this->runJob();
        $this->assertSame('ok', $result['status']);
        $this->assertContains('revocable-test-plugin', $result['deactivated']);
        $this->assertSame('inactive', $this->pluginStatus());
    }

    public function testDoesNotDeactivateWhenFeedListsOtherPlugins(): void
    {
        HttpClient::$mockResponses = [
            'https://plugins.ownpay.org/revocations.json' => [
                'status' => 200,
                'body' => '{"revoked":["someone-elses-plugin"]}',
                'headers' => [],
            ],
        ];
        $result = $this->runJob();
        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['deactivated']);
        $this->assertSame('active', $this->pluginStatus());
    }
}
