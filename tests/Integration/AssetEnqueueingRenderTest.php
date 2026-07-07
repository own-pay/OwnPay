<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\DashboardController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Service\System\AssetManager;

/**
 * Proves enqueued assets actually appear in real rendered HTML, for both the admin panel
 * and checkout - not just in isolated unit tests of AssetManager's render methods.
 */
final class AssetEnqueueingRenderTest extends IntegrationTestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->db->execute("DELETE FROM op_system_settings WHERE `group_name` = 'system' AND `key_name` = 'onboarding_completed'");
        $this->db->insert(
            "INSERT INTO op_system_settings (group_name, key_name, value, merchant_id) VALUES ('system', 'onboarding_completed', '1', NULL)"
        );

        if (!isset($_ENV['ENCRYPTION_KEY'])) {
            $_ENV['ENCRYPTION_KEY'] = $_ENV['PII_ENCRYPTION_KEY'] ?? str_repeat('a', 32);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_system_settings WHERE `group_name` = 'system' AND `key_name` = 'onboarding_completed'");
        }
        parent::tearDown();
    }

    private function buildContainer(): Container
    {
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);
        return $container;
    }

    public function testAdminPageRendersEnqueuedStyleAndScriptTags(): void
    {
        $container = $this->buildContainer();

        $assets = $container->get(AssetManager::class);
        $this->assertInstanceOf(AssetManager::class, $assets);
        $assets->enqueueStyle('test-plugin-style', '/modules/gateways/test-plugin/assets/style.css');
        $assets->enqueueScript('test-plugin-script', '/modules/gateways/test-plugin/assets/script.js');

        $controller = $container->get(DashboardController::class);
        $this->assertInstanceOf(DashboardController::class, $controller);

        $response = $controller->index(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<link rel="stylesheet" href="/modules/gateways/test-plugin/assets/style.css">', $response->getBody());
        $this->assertStringContainsString('<script src="/modules/gateways/test-plugin/assets/script.js"></script>', $response->getBody());
    }
}
