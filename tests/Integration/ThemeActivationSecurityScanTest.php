<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Plugin\PluginManager;
use OwnPay\Repository\PluginRepository;

/**
 * Regression coverage for the theme-sandboxing static AST gate: PluginManager::activate()
 * must reject a theme whose template contains a hard-block pattern (reverting the DB
 * status change), and must accept every bundled/example theme cleanly (proving the gate
 * doesn't false-positive on real, already-shipped themes).
 */
final class ThemeActivationSecurityScanTest extends IntegrationTestCase
{
    private Database $db;
    private PluginManager $manager;
    private PluginRepository $pluginRepo;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, $this->db);

        $manager = $container->get(PluginManager::class);
        $this->assertInstanceOf(PluginManager::class, $manager);
        $this->manager = $manager;

        $pluginRepo = $container->get(PluginRepository::class);
        $this->assertInstanceOf(PluginRepository::class, $pluginRepo);
        $this->pluginRepo = $pluginRepo;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_plugins WHERE slug = 'capability-test-fixture'");
    }

    public function testActivateRejectsThemeWithDangerousTemplatePattern(): void
    {
        // capability-test-fixture already exists on disk (used by earlier capability-gate
        // tests) - this test adds a dangerous template file to it (danger.php, created in
        // this task's Step 3) and confirms activation now fails and reverts.
        $result = $this->manager->activate('capability-test-fixture');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('dangerous', strtolower((string) ($result['error'] ?? '')));

        $row = $this->pluginRepo->findBySlug('capability-test-fixture');
        if ($row !== null) {
            $this->assertNotSame('active', $row['status']);
        }
    }

    public function testBundledAndExampleThemesActivateCleanly(): void
    {
        foreach (['own-pay', 'reference-minimal', 'plain-php-demo'] as $slug) {
            $result = $this->manager->activate($slug);
            $this->assertTrue(
                $result['success'] || (str_contains(strtolower((string) ($result['message'] ?? '')), 'already active')),
                "Expected {$slug} to activate cleanly, got: " . json_encode($result)
            );
        }
    }
}
