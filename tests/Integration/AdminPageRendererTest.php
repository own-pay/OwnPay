<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\DashboardController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\System\Logger;

/**
 * Regression coverage for the admin rendering-engine abstraction: with no
 * admin_engine setting configured, an existing admin controller must render
 * exactly as before (Twig, unchanged). With an invalid/unregistered engine
 * name configured, the same controller must still render successfully by
 * falling back to Twig, logging a warning instead of throwing and breaking
 * the whole admin panel.
 */
final class AdminPageRendererTest extends IntegrationTestCase
{
    private Database $db;
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $_ENV['ENCRYPTION_KEY'] = $_ENV['PII_ENCRYPTION_KEY'] ?? 'cd4c6edf857c4ad19cb41784e849adf79ec3fc20319c28e735bd3fbd801eca33';

        $this->db = Database::getInstance();
        $this->logDir = sys_get_temp_dir() . '/admin-renderer-test-' . bin2hex(random_bytes(6));
        mkdir($this->logDir, 0777, true);

        $this->db->execute("DELETE FROM op_system_settings WHERE group_name = 'appearance' AND key_name = 'admin_engine'");

        $settingsRepo = new SettingsRepository($this->db);
        $settingsRepo->set('system', 'onboarding_completed', '1');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_system_settings WHERE group_name = 'appearance' AND key_name = 'admin_engine'");
        }
        $this->removeDir($this->logDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function buildContainer(): Container
    {
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);
        $container->instance(Logger::class, new Logger('test', $this->logDir));
        return $container;
    }

    private function readLogContents(): string
    {
        $contents = '';
        foreach (glob($this->logDir . '/*.log') ?: [] as $file) {
            $contents .= (string) file_get_contents($file);
        }
        return $contents;
    }

    public function testDashboardRendersViaTwigWhenNoEngineConfigured(): void
    {
        $container = $this->buildContainer();
        $controller = $container->get(DashboardController::class);
        $this->assertInstanceOf(DashboardController::class, $controller);

        $response = $controller->index(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Dashboard', $response->getBody());
        $this->assertStringNotContainsString('not registered', $this->readLogContents());
    }

    public function testDashboardFallsBackToTwigWhenConfiguredEngineIsNotRegistered(): void
    {
        $settings = new SettingsRepository($this->db);
        $settings->set('appearance', 'admin_engine', 'nonexistent-engine');

        $container = $this->buildContainer();
        $controller = $container->get(DashboardController::class);
        $this->assertInstanceOf(DashboardController::class, $controller);

        $response = $controller->index(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Dashboard', $response->getBody());
        $this->assertStringContainsString("admin render engine 'nonexistent-engine' not registered", strtolower($this->readLogContents()));
    }
}
