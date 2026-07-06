<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\ApiKeyController;
use OwnPay\Http\Request;
use OwnPay\Service\Brand\BrandContext;

/**
 * Regression coverage for the Developer Hub API Keys restructure's new Lock/Unlock
 * admin actions, mirroring the existing revoke() action's request-handling contract.
 */
final class ApiKeyControllerLockUnlockTest extends IntegrationTestCase
{
    private Database $db;
    private ApiKeyController $controller;
    private BrandContext $brand;
    private int $merchantId = 1;

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

        $controller = $container->get(ApiKeyController::class);
        $this->assertInstanceOf(ApiKeyController::class, $controller);
        $this->controller = $controller;

        $brand = $container->get(BrandContext::class);
        $this->assertInstanceOf(BrandContext::class, $brand);
        $this->brand = $brand;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

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
        $this->db->execute("DELETE FROM op_api_keys WHERE name LIKE 'zzctl-%'");
        unset($_SESSION['active_brand_id'], $_SESSION['brand_view_mode']);
    }

    private function insertKey(string $name, string $status): int
    {
        return (int) $this->db->insert(
            "INSERT INTO op_api_keys (merchant_id, name, key_prefix, key_hash, scopes, status, created_at)
             VALUES (:mid, :name, :prefix, :hash, '[\"read\"]', :status, NOW())",
            [
                'mid' => $this->merchantId,
                'name' => $name,
                'prefix' => substr(bin2hex(random_bytes(4)), 0, 8),
                'hash' => hash('sha256', bin2hex(random_bytes(16))),
                'status' => $status,
            ]
        );
    }

    private function requestFor(int $id): Request
    {
        $req = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "/admin/api-keys/{$id}/lock"]);
        $req->setRouteParams(['id' => (string) $id]);
        return $req;
    }

    public function testLockActionTransitionsKeyAndRedirects(): void
    {
        $id = $this->insertKey('zzctl-lock-me', 'active');
        $this->brand->resolveFromRequest(new Request([], [], ['REQUEST_METHOD' => 'GET']));

        $res = $this->controller->lock($this->requestFor($id));

        $this->assertSame(302, $res->getStatusCode());
        $row = $this->db->fetchOne("SELECT status FROM op_api_keys WHERE id = :id", ['id' => $id]);
        $this->assertSame('locked', $row['status']);
    }

    public function testUnlockActionTransitionsKeyAndRedirects(): void
    {
        $id = $this->insertKey('zzctl-unlock-me', 'locked');
        $this->brand->resolveFromRequest(new Request([], [], ['REQUEST_METHOD' => 'GET']));

        $res = $this->controller->unlock($this->requestFor($id));

        $this->assertSame(302, $res->getStatusCode());
        $row = $this->db->fetchOne("SELECT status FROM op_api_keys WHERE id = :id", ['id' => $id]);
        $this->assertSame('active', $row['status']);
    }
}
