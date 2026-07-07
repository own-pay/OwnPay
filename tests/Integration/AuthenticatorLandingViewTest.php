<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Security\Authenticator;

/**
 * Regression coverage for the login-landing-view bug: startSession() always
 * scoped every user to their own merchant record, so even superadmins and
 * brands.access_all-permitted staff landed on a single brand instead of the
 * global "All Brands" view after login.
 */
final class AuthenticatorLandingViewTest extends IntegrationTestCase
{
    private Database $db;
    private Authenticator $auth;
    private int $merchantId = 500001;
    private int $ordinaryRoleId = 0;
    private int $accessAllRoleId = 0;

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

        $auth = $container->get(Authenticator::class);
        $this->assertInstanceOf(Authenticator::class, $auth);
        $this->auth = $auth;

        $this->cleanup();

        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (:id, :uuid, 'Landing View Test Merchant', :slug, 'landing-view-test@example.com', 'active', '{}')",
            ['id' => $this->merchantId, 'uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee1', 'slug' => 'landing-view-test']
        );

        $this->db->execute(
            "INSERT INTO op_roles (merchant_id, name, slug, is_system) VALUES (:mid, 'Ordinary Staff', 'landing-view-ordinary', 0)",
            ['mid' => $this->merchantId]
        );
        $this->ordinaryRoleId = (int) $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO op_roles (merchant_id, name, slug, is_system) VALUES (:mid, 'All Brands Staff', 'landing-view-access-all', 0)",
            ['mid' => $this->merchantId]
        );
        $this->accessAllRoleId = (int) $this->db->lastInsertId();

        $permissionId = $this->db->fetchOne(
            "SELECT id FROM op_permissions WHERE slug = 'brands.access_all'"
        )['id'] ?? null;
        $this->assertNotNull($permissionId, 'Precondition: brands.access_all permission must be seeded');

        $this->db->execute(
            "INSERT INTO op_role_permissions (role_id, permission_id) VALUES (:rid, :pid)",
            ['rid' => $this->accessAllRoleId, 'pid' => $permissionId]
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        unset(
            $_SESSION['auth_user_id'], $_SESSION['auth_merchant_id'], $_SESSION['active_brand_id'],
            $_SESSION['auth_role_id'], $_SESSION['auth_email'], $_SESSION['auth_name'],
            $_SESSION['is_superadmin'], $_SESSION['two_fa_enabled'], $_SESSION['auth_at'],
            $_SESSION['brand_view_mode']
        );
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_role_permissions WHERE role_id IN (SELECT id FROM op_roles WHERE merchant_id = :mid)", ['mid' => $this->merchantId]);
        $this->db->execute("DELETE FROM op_roles WHERE merchant_id = :mid", ['mid' => $this->merchantId]);
        $this->db->execute("DELETE FROM op_merchants WHERE id = :mid", ['mid' => $this->merchantId]);
    }

    public function testSuperadminLandsOnGlobalView(): void
    {
        $this->auth->startSession([
            'id' => 1, 'merchant_id' => $this->merchantId, 'role_id' => $this->ordinaryRoleId,
            'email' => 'super@example.com', 'name' => 'Super Admin', 'is_superadmin' => true,
        ]);

        $this->assertSame('global', $_SESSION['brand_view_mode']);
        $this->assertSame(0, $_SESSION['active_brand_id']);
    }

    public function testUserWithAccessAllPermissionLandsOnGlobalView(): void
    {
        $this->auth->startSession([
            'id' => 2, 'merchant_id' => $this->merchantId, 'role_id' => $this->accessAllRoleId,
            'email' => 'staff@example.com', 'name' => 'All Brands Staff', 'is_superadmin' => false,
        ]);

        $this->assertSame('global', $_SESSION['brand_view_mode']);
        $this->assertSame(0, $_SESSION['active_brand_id']);
    }

    public function testOrdinaryUserStillLandsOnOwnMerchantBrand(): void
    {
        $this->auth->startSession([
            'id' => 3, 'merchant_id' => $this->merchantId, 'role_id' => $this->ordinaryRoleId,
            'email' => 'staff2@example.com', 'name' => 'Ordinary Staff', 'is_superadmin' => false,
        ]);

        $this->assertSame('single', $_SESSION['brand_view_mode']);
        $this->assertSame($this->merchantId, $_SESSION['active_brand_id']);
    }
}
