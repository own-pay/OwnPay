<?php

declare(strict_types=1);

namespace Tests\Feature;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Controller\Admin\SettingsController;
use OwnPay\Http\Request;
use Tests\Integration\IntegrationTestCase;

final class PlatformMaintenanceTest extends IntegrationTestCase
{
    private Container $c;
    private Database $db;
    private SettingsRepository $settingsRepo;
    private SettingsController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Database::getInstance();

        $this->c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->c);

        $this->c->instance(Database::class, $this->db);

        $settingsRepo = $this->c->get(SettingsRepository::class);
        assert($settingsRepo instanceof SettingsRepository);
        $this->settingsRepo = $settingsRepo;

        $controller = $this->c->get(SettingsController::class);
        assert($controller instanceof SettingsController);
        $this->controller = $controller;

        $this->settingsRepo->set('runtime', 'optimization.last_cache_clear_time', '');
        $this->settingsRepo->set('runtime', 'optimization.last_db_optimize_time', '');
        $this->settingsRepo->set('runtime', 'optimization.last_logs_purge_time', '');
        $this->settingsRepo->set('runtime', 'optimization.last_uploads_purge_time', '');
    }

    public function testOptimizeCacheAction(): void
    {
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache/twig';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $mockFile = $cacheDir . '/mock_template.php';
        file_put_contents($mockFile, '<?php echo "twig";');
        $this->assertFileExists($mockFile);

        $req = new Request();
        $response = $this->controller->optimizeCache($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFileDoesNotExist($mockFile);

        $lastClear = $this->settingsRepo->get('runtime', 'optimization.last_cache_clear_time', '');
        $this->assertNotEmpty($lastClear);
    }

    public function testOptimizeDatabaseAction(): void
    {
        $req = new Request();
        $response = $this->controller->optimizeDatabase($req);

        $this->assertSame(302, $response->getStatusCode());

        $lastOpt = $this->settingsRepo->get('runtime', 'optimization.last_db_optimize_time', '');
        $this->assertNotEmpty($lastOpt);
    }

    public function testOptimizeLogsAction(): void
    {
        $oldDate = date('Y-m-d H:i:s', strtotime('-100 days'));
        $newDate = date('Y-m-d H:i:s');

        $db = Database::getInstance();
        $db->execute("DELETE FROM op_audit_logs");

        // Plain insert bypasses HMAC signing verification
        $db->execute(
            "INSERT INTO op_audit_logs (merchant_id, user_id, action, created_at) 
             VALUES (1, 1, 'mock.old', :old), (1, 1, 'mock.new', :new)",
            ['old' => $oldDate, 'new' => $newDate]
        );

        $countVal = $db->fetchColumn("SELECT COUNT(*) FROM op_audit_logs");
        $count = is_numeric($countVal) ? (int) $countVal : 0;
        $this->assertSame(2, $count);

        $req = new Request([], ['log_retention_days' => '90']);
        $response = $this->controller->optimizeLogs($req);

        $this->assertSame(302, $response->getStatusCode());

        $remaining = $db->fetchAll("SELECT action FROM op_audit_logs");
        $actions = array_column($remaining, 'action');
        $this->assertNotContains('mock.old', $actions);
        $this->assertContains('mock.new', $actions);
        $this->assertContains('settings.logs_purged', $actions);

        $lastPurge = $this->settingsRepo->get('runtime', 'optimization.last_logs_purge_time', '');
        $this->assertNotEmpty($lastPurge);
        $this->assertSame('90', $this->settingsRepo->get('runtime', 'optimization.log_retention_days', ''));
    }

    public function testOptimizeUploadsAction(): void
    {
        $tempDir = dirname(__DIR__, 2) . '/storage/temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $mockOldFile = $tempDir . '/old_temp.tmp';
        $mockNewFile = $tempDir . '/new_temp.tmp';

        file_put_contents($mockOldFile, 'old');
        file_put_contents($mockNewFile, 'new');

        // 25 hours ago - exceeds the 24h cleanup threshold
        touch($mockOldFile, time() - 90000);
        touch($mockNewFile, time());

        $this->assertFileExists($mockOldFile);
        $this->assertFileExists($mockNewFile);

        $req = new Request();
        $response = $this->controller->optimizeUploads($req);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFileDoesNotExist($mockOldFile);
        $this->assertFileExists($mockNewFile);

        @unlink($mockNewFile);

        $lastUploads = $this->settingsRepo->get('runtime', 'optimization.last_uploads_purge_time', '');
        $this->assertNotEmpty($lastUploads);
    }

    public function testOptimizeAllAction(): void
    {
        $req = new Request([], ['log_retention_days' => '60']);
        $response = $this->controller->optimizeAll($req);

        $this->assertSame(302, $response->getStatusCode());

        $this->assertNotEmpty($this->settingsRepo->get('runtime', 'optimization.last_cache_clear_time', ''));
        $this->assertNotEmpty($this->settingsRepo->get('runtime', 'optimization.last_db_optimize_time', ''));
        $this->assertNotEmpty($this->settingsRepo->get('runtime', 'optimization.last_logs_purge_time', ''));
        $this->assertNotEmpty($this->settingsRepo->get('runtime', 'optimization.last_uploads_purge_time', ''));
    }
}
