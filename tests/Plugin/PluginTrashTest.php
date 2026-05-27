<?php

declare(strict_types=1);

namespace Tests\Plugin;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Repository\PluginRepository;
use OwnPay\Plugin\PluginManager;
use OwnPay\Plugin\PluginLoader;
use Tests\Integration\IntegrationTestCase;

/**
 * PluginTrashTest — Automated integration tests for the Plugin Trash system.
 *
 * Checks:
 *   1. Move inactive plugin to trash (files moved to storage/trash/plugins, status = 'trashed')
 *   2. Restore plugin from trash (files moved back to modules, status = 'inactive')
 *   3. Permanent uninstall of trashed plugin (migration rollback, files deleted, record purged)
 *
 * @group Integration
 */
final class PluginTrashTest extends IntegrationTestCase
{
    private Container $c;
    private PluginRepository $pluginRepo;
    private PluginManager $pluginManager;

    private string $modulesPath;
    private string $trashPath;

    private string $dummySlug = 'dummy-trash-test-plugin';
    private string $dummyType = 'addon';
    private string $dummyTypeDir = 'addons';

    protected function setUp(): void
    {
        parent::setUp(); // verifies DB is available

        // Initialize container
        $this->c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->c);

        // Bind our Database singleton to PDO just in case
        $this->c->instance(Database::class, Database::getInstance());

        // Ensure status enum contains 'trashed' in the test database schema
        $db = Database::getInstance();
        $col = $db->fetchOne("SHOW COLUMNS FROM op_plugins LIKE 'status'");
        if ($col && !str_contains($col['Type'], 'trashed')) {
            $db->execute("ALTER TABLE op_plugins MODIFY COLUMN status ENUM('active','inactive','error','trashed') NOT NULL DEFAULT 'inactive'");
        }

        $this->pluginRepo = $this->c->get(PluginRepository::class);
        $this->pluginManager = $this->c->get(PluginManager::class);

        $paths = $this->c->get('config.app')['paths'];
        $this->modulesPath = $paths['modules'] . '/' . $this->dummyTypeDir . '/' . $this->dummySlug;
        $this->trashPath = $paths['storage'] . '/trash/plugins/' . $this->dummyTypeDir . '/' . $this->dummySlug;

        // Clean up any leftover database records or directories from previous failures
        $this->cleanupDummyPlugin();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanupDummyPlugin();
        }
        parent::tearDown();
    }

    private function cleanupDummyPlugin(): void
    {
        // Delete DB record
        $plugin = $this->pluginRepo->findBySlug($this->dummySlug);
        if ($plugin !== null) {
            $this->pluginRepo->delete((int) $plugin['id']);
        }

        // Delete active modules folder
        if (is_dir($this->modulesPath)) {
            $this->removeDirectory($this->modulesPath);
        }

        // Delete trash folder
        if (is_dir($this->trashPath)) {
            $this->removeDirectory($this->trashPath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function createDummyPlugin(): void
    {
        @mkdir($this->modulesPath, 0755, true);
        
        $manifest = [
            'slug' => $this->dummySlug,
            'name' => 'Dummy Trash Test Plugin',
            'version' => '1.0.0',
            'type' => $this->dummyType,
            'entrypoint' => 'DummyPlugin.php',
            'description' => 'A dummy plugin for trashing and restoring tests.'
        ];

        file_put_contents($this->modulesPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        file_put_contents($this->modulesPath . '/DummyPlugin.php', "<?php\n\nclass DummyPlugin {}\n");

        // Insert database record
        $this->pluginRepo->create([
            'slug' => $this->dummySlug,
            'name' => 'Dummy Trash Test Plugin',
            'type' => $this->dummyType,
            'version' => '1.0.0',
            'status' => 'inactive',
            'entrypoint' => 'DummyPlugin.php',
            'manifest' => json_encode($manifest)
        ]);
    }

    public function testPluginTrashingAndRestorationFlow(): void
    {
        // 1. Create dummy plugin in live modules
        $this->createDummyPlugin();
        $this->assertDirectoryExists($this->modulesPath);
        $this->assertFileExists($this->modulesPath . '/manifest.json');

        $record = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNotNull($record);
        $this->assertSame('inactive', $record['status']);

        // 2. Trash the plugin
        $result = $this->pluginManager->trash($this->dummySlug);
        $this->assertTrue($result['success'], $result['error'] ?? 'Trashing failed');

        // Check file movement
        $this->assertDirectoryDoesNotExist($this->modulesPath);
        $this->assertDirectoryExists($this->trashPath);
        $this->assertFileExists($this->trashPath . '/manifest.json');

        // Check DB update
        $record = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertSame('trashed', $record['status']);

        // 3. Restore the plugin
        $result = $this->pluginManager->restore($this->dummySlug);
        $this->assertTrue($result['success'], $result['error'] ?? 'Restoring failed');

        // Check file movement back
        $this->assertDirectoryExists($this->modulesPath);
        $this->assertDirectoryDoesNotExist($this->trashPath);
        $this->assertFileExists($this->modulesPath . '/manifest.json');

        // Check DB update
        $record = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertSame('inactive', $record['status']);
    }

    public function testPermanentDeletionOfTrashedPlugin(): void
    {
        // 1. Create and trash dummy plugin
        $this->createDummyPlugin();
        $result = $this->pluginManager->trash($this->dummySlug);
        $this->assertTrue($result['success']);

        $this->assertDirectoryDoesNotExist($this->modulesPath);
        $this->assertDirectoryExists($this->trashPath);

        // 2. Perform permanent delete (uninstall)
        $result = $this->pluginManager->uninstall($this->dummySlug);
        $this->assertTrue($result['success'], $result['error'] ?? 'Uninstall failed');

        // Verify folders are deleted
        $this->assertDirectoryDoesNotExist($this->modulesPath);
        $this->assertDirectoryDoesNotExist($this->trashPath);

        // Verify DB record is purged
        $record = $this->pluginRepo->findBySlug($this->dummySlug);
        $this->assertNull($record);
    }
}
