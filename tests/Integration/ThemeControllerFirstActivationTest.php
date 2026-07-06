<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\ThemeController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\Admin\AdminSession;

/**
 * Regression coverage for ThemeController::activate()'s first-time-activation
 * false-negative: when a theme's plugin row doesn't exist yet, the discovery
 * branch creates it via PluginManager::activate() but never re-fetched its
 * own local $plugin variable, so the method's own null-check always fired
 * and flashed "Failed to activate theme" even though activation succeeded.
 */
final class ThemeControllerFirstActivationTest extends IntegrationTestCase
{
    private Database $db;
    private ThemeController $controller;
    private PluginRepository $pluginRepo;
    private AdminSession $adminSession;
    private SettingsRepository $settingsRepo;
    private ?string $originalActiveTheme = null;
    private string $slug = 'plain-php-demo';

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();

        $c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $this->db);

        $controller = $c->get(ThemeController::class);
        $this->assertInstanceOf(ThemeController::class, $controller);
        $this->controller = $controller;

        $pluginRepo = $c->get(PluginRepository::class);
        $this->assertInstanceOf(PluginRepository::class, $pluginRepo);
        $this->pluginRepo = $pluginRepo;

        $adminSession = $c->get(AdminSession::class);
        $this->assertInstanceOf(AdminSession::class, $adminSession);
        $this->adminSession = $adminSession;

        $settingsRepo = $c->get(SettingsRepository::class);
        $this->assertInstanceOf(SettingsRepository::class, $settingsRepo);
        $this->settingsRepo = $settingsRepo;
        // activate() sets the global 'appearance'/'active_theme' setting as a side
        // effect - capture the real current value so tearDown can restore it and
        // not leak this test's theme choice into every other test in the suite.
        $this->originalActiveTheme = $this->settingsRepo->get('appearance', 'active_theme');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Ensure a clean slate: no op_plugins row for this theme yet, matching
        // the real first-time-activation scenario this bug only reproduces under.
        $this->db->execute("DELETE FROM op_plugins WHERE slug = :slug", ['slug' => $this->slug]);
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            if ($this->originalActiveTheme !== null) {
                $this->settingsRepo->set('appearance', 'active_theme', $this->originalActiveTheme);
            } else {
                $this->db->execute(
                    "DELETE FROM op_system_settings WHERE group_name = 'appearance' AND key_name = 'active_theme' AND merchant_id IS NULL"
                );
            }
            $this->db->execute("DELETE FROM op_plugins WHERE slug = :slug", ['slug' => $this->slug]);
        }
        parent::tearDown();
    }

    public function testFirstTimeActivationSucceedsAndReportsSuccessNotFailure(): void
    {
        $this->assertNull($this->pluginRepo->findBySlug($this->slug), 'Precondition: no existing row');

        $req = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $req->setRouteParams(['slug' => $this->slug]);

        $response = $this->controller->activate($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/themes', $response->getHeaders()['Location']);

        $plugin = $this->pluginRepo->findBySlug($this->slug);
        $this->assertNotNull($plugin, 'Plugin row should have been created by first-time activation');
        $this->assertSame('active', $plugin['status']);

        $flash = $this->adminSession->consumeFlash();
        $this->assertArrayHasKey('success', $flash);
        $this->assertNotNull(
            $flash['success'],
            'First-time activation must flash success, not an error (got: ' . var_export($flash['error'] ?? null, true) . ')'
        );
        $this->assertStringContainsString('activated', (string) $flash['success']);
    }
}
