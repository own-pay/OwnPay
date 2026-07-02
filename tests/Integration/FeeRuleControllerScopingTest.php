<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\FeeRuleController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;

/**
 * Regression coverage for FeeRuleController::update()/delete(). Added while removing a
 * PHPStan-flagged redundant null check on $mid (Active brand id) that duplicated an earlier
 * guard in the same method - this test locks in that brand-scoped and global (superadmin)
 * update/delete both still work correctly through the real DI container + DB.
 */
final class FeeRuleControllerScopingTest extends IntegrationTestCase
{
    private Database $db;
    private FeeRuleController $controller;
    private int $brandId = 1;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $this->db);
        $c->instance(Container::class, $c);

        $controller = $c->get(FeeRuleController::class);
        $this->assertInstanceOf(FeeRuleController::class, $controller);
        $this->controller = $controller;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['auth_user_id']     = 1;
        $_SESSION['auth_merchant_id'] = $this->brandId;
        $_SESSION['active_brand_id']  = $this->brandId;
        $_SESSION['brand_view_mode']  = 'single';
        $_SESSION['is_superadmin']    = true;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        unset($_SESSION['active_brand_id'], $_SESSION['brand_view_mode']);
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_fee_rules WHERE gateway_slug LIKE 'zztest-%'");
    }

    private function insertRule(?int $merchantId, string $gatewaySlug): int
    {
        $this->db->execute(
            "INSERT INTO op_fee_rules (merchant_id, gateway_slug, type, value, currency, status)
             VALUES (:mid, :slug, 'percentage', 2.5000, 'BDT', 'active')",
            ['mid' => $merchantId, 'slug' => $gatewaySlug]
        );
        $row = $this->db->fetchOne("SELECT id FROM op_fee_rules WHERE gateway_slug = :slug", ['slug' => $gatewaySlug]);
        $this->assertIsArray($row);
        return (int) $row['id'];
    }

    public function testBrandScopedUpdatePersistsChanges(): void
    {
        $_SESSION['brand_view_mode'] = 'single';
        $id = $this->insertRule($this->brandId, 'zztest-brand-update');

        $request = new Request([], [
            'gateway_slug' => 'zztest-brand-update',
            'type'         => 'flat',
            'value'        => '10.00',
            'currency'     => 'BDT',
            'status'       => 'active',
        ]);
        $request->setRouteParams(['id' => (string) $id]);

        $response = $this->controller->update($request);

        $this->assertSame(302, $response->getStatusCode());
        $row = $this->db->fetchOne("SELECT type, value FROM op_fee_rules WHERE id = :id", ['id' => $id]);
        $this->assertIsArray($row);
        $this->assertSame('flat', $row['type']);
        $this->assertSame('10.0000', $row['value']);
    }

    public function testBrandScopedDeleteRemovesRow(): void
    {
        $_SESSION['brand_view_mode'] = 'single';
        $id = $this->insertRule($this->brandId, 'zztest-brand-delete');

        $request = new Request();
        $request->setRouteParams(['id' => (string) $id]);

        $response = $this->controller->delete($request);

        $this->assertSame(302, $response->getStatusCode());
        $row = $this->db->fetchOne("SELECT id FROM op_fee_rules WHERE id = :id", ['id' => $id]);
        $this->assertNull($row);
    }

    public function testGlobalSuperadminUpdatePersistsChanges(): void
    {
        $_SESSION['brand_view_mode'] = 'global';
        $id = $this->insertRule(null, 'zztest-global-update');

        $request = new Request([], [
            'gateway_slug' => 'zztest-global-update',
            'type'         => 'flat',
            'value'        => '5.00',
            'currency'     => 'BDT',
            'status'       => 'active',
        ]);
        $request->setRouteParams(['id' => (string) $id]);

        $response = $this->controller->update($request);

        $this->assertSame(302, $response->getStatusCode());
        $row = $this->db->fetchOne("SELECT type, value FROM op_fee_rules WHERE id = :id", ['id' => $id]);
        $this->assertIsArray($row);
        $this->assertSame('flat', $row['type']);
        $this->assertSame('5.0000', $row['value']);
    }

    public function testGlobalSuperadminDeleteRemovesRow(): void
    {
        $_SESSION['brand_view_mode'] = 'global';
        $id = $this->insertRule(null, 'zztest-global-delete');

        $request = new Request();
        $request->setRouteParams(['id' => (string) $id]);

        $response = $this->controller->delete($request);

        $this->assertSame(302, $response->getStatusCode());
        $row = $this->db->fetchOne("SELECT id FROM op_fee_rules WHERE id = :id", ['id' => $id]);
        $this->assertNull($row);
    }
}
