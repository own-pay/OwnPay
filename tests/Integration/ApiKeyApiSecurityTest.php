<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Controller\Api\ApiKeyController;
use OwnPay\Service\Customer\ApiKeyService;

final class ApiKeyApiSecurityTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private ApiKeyService $apiKeyService;
    private ApiKeyController $controller;

    private string $standardKey;
    private string $adminKey;
    private string $superAdminEmail = 'super.admin.test@example.com';
    private string $inactiveSuperAdminEmail = 'inactive.admin@example.com';
    private string $nonAdminEmail = 'staff.user@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $_ENV['ENCRYPTION_KEY'] = $_ENV['PII_ENCRYPTION_KEY'] ?? 'cd4c6edf857c4ad19cb41784e849adf79ec3fc20319c28e735bd3fbd801eca33';

        $this->db = Database::getInstance();
        $this->container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);

        $this->apiKeyService = $this->container->get(ApiKeyService::class);
        $this->controller = $this->container->get(ApiKeyController::class);

        // Cleanup previous runs
        $this->db->execute("DELETE FROM op_api_keys WHERE merchant_id = 99996");
        $this->db->execute("DELETE FROM op_merchant_users WHERE merchant_id = 99996");
        $this->db->execute("DELETE FROM op_roles WHERE merchant_id = 99996");
        $this->db->execute("DELETE FROM op_merchants WHERE id = 99996");

        // 1. Create a test merchant
        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (99996, 'test-merchant-uuid-99996', 'API Key Test Merchant', 'key-test', 'key-test@test.com', 'active', '{}')"
        );

        // 1.5. Create test roles for merchant 99996
        $this->db->execute(
            "INSERT INTO op_roles (id, merchant_id, name, slug, description, is_system)
             VALUES (999961, 99996, 'Administrator', 'admin', 'Super User role', 1)"
        );
        $this->db->execute(
            "INSERT INTO op_roles (id, merchant_id, name, slug, description, is_system)
             VALUES (999962, 99996, 'Staff', 'staff', 'Staff user role', 1)"
        );

        // 2. Create users under test merchant
        // Active Super Admin
        $this->db->execute(
            "INSERT INTO op_merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at)
             VALUES (99996, 999961, 'Super Admin User', 'superadmin', :email, 'hash', 1, 'active', NOW())",
            ['email' => $this->superAdminEmail]
        );

        // Inactive Super Admin
        $this->db->execute(
            "INSERT INTO op_merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at)
             VALUES (99996, 999961, 'Inactive Super Admin', 'inactivesuper', :email, 'hash', 1, 'suspended', NOW())",
            ['email' => $this->inactiveSuperAdminEmail]
        );

        // Non-admin user
        $this->db->execute(
            "INSERT INTO op_merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at)
             VALUES (99996, 999962, 'Staff User', 'staff', :email, 'hash', 0, 'active', NOW())",
            ['email' => $this->nonAdminEmail]
        );

        // 3. Generate test API keys
        // Key with write but missing admin scope
        $stdKeyInfo = $this->apiKeyService->generate(99996, 'Standard Key', ['read', 'write']);
        $this->standardKey = $stdKeyInfo['key'];

        // Key with write and admin scope
        $admKeyInfo = $this->apiKeyService->generate(99996, 'Admin Key', ['read', 'write', 'admin']);
        $this->adminKey = $admKeyInfo['key'];
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_api_keys WHERE merchant_id = 99996");
            $this->db->execute("DELETE FROM op_merchant_users WHERE merchant_id = 99996");
            $this->db->execute("DELETE FROM op_roles WHERE merchant_id = 99996");
            $this->db->execute("DELETE FROM op_merchants WHERE id = 99996");
        }
        parent::tearDown();
    }

    private function getCallerKeyRow(string $key): array
    {
        $prefix = substr($key, 3, 8);
        return $this->db->fetchOne("SELECT * FROM op_api_keys WHERE key_prefix = :p", ['p' => $prefix]);
    }

    public function testIndexRequiresWriteAdminScopesAndValidSuperAdminHeader(): void
    {
        // Case 1: Standard key (write, no admin)
        $req1 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail]);
        $req1->setAttribute('merchant_id', 99996);
        $req1->setAttribute('api_key', $this->getCallerKeyRow($this->standardKey));

        $res1 = $this->controller->index($req1);
        $this->assertSame(403, $res1->getStatusCode());
        $body1 = json_decode($res1->getBody(), true);
        $this->assertFalse($body1['success']);
        $this->assertSame('INSUFFICIENT_PRIVILEGE', $body1['errors'][0]['code']);

        // Case 2: Admin key, missing header
        $req2 = new Request([], [], []);
        $req2->setAttribute('merchant_id', 99996);
        $req2->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res2 = $this->controller->index($req2);
        $this->assertSame(400, $res2->getStatusCode());
        $body2 = json_decode($res2->getBody(), true);
        $this->assertSame('SUPER_ADMIN_EMAIL_REQUIRED', $body2['errors'][0]['code']);

        // Case 3: Admin key, non-admin email
        $req3 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->nonAdminEmail]);
        $req3->setAttribute('merchant_id', 99996);
        $req3->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res3 = $this->controller->index($req3);
        $this->assertSame(403, $res3->getStatusCode());
        $body3 = json_decode($res3->getBody(), true);
        $this->assertSame('INVALID_SUPER_ADMIN', $body3['errors'][0]['code']);

        // Case 4: Admin key, inactive superadmin email
        $req4 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->inactiveSuperAdminEmail]);
        $req4->setAttribute('merchant_id', 99996);
        $req4->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res4 = $this->controller->index($req4);
        $this->assertSame(403, $res4->getStatusCode());
        $body4 = json_decode($res4->getBody(), true);
        $this->assertSame('INVALID_SUPER_ADMIN', $body4['errors'][0]['code']);

        // Case 5: Valid admin key and superadmin header
        $req5 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail]);
        $req5->setAttribute('merchant_id', 99996);
        $req5->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res5 = $this->controller->index($req5);
        $this->assertSame(200, $res5->getStatusCode());
        $body5 = json_decode($res5->getBody(), true);
        $this->assertTrue($body5['success']);
        $this->assertCount(2, $body5['data']); // Standard key + Admin key
    }

    public function testGenerateParsesAndValidatesCustomScopes(): void
    {
        // Case 1: Invalid scope type (not an array)
        $req1 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail], [], [], '{"scopes": "not-an-array"}');
        $req1->setAttribute('merchant_id', 99996);
        $req1->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res1 = $this->controller->generate($req1);
        $this->assertSame(422, $res1->getStatusCode());
        $body1 = json_decode($res1->getBody(), true);
        $this->assertFalse($body1['success']);
        $this->assertSame('INVALID_SCOPES', $body1['errors'][0]['code']);

        // Case 2: Invalid scope values
        $req2 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail], [], [], '{"scopes": ["read", "invalid_privilege"]}');
        $req2->setAttribute('merchant_id', 99996);
        $req2->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res2 = $this->controller->generate($req2);
        $this->assertSame(422, $res2->getStatusCode());

        // Case 3: Empty scopes
        $req3 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail], [], [], '{"scopes": []}');
        $req3->setAttribute('merchant_id', 99996);
        $req3->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res3 = $this->controller->generate($req3);
        $this->assertSame(422, $res3->getStatusCode());

        // Case 4: Default scopes if not provided
        $req4 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail], [], [], '{}');
        $req4->setAttribute('merchant_id', 99996);
        $req4->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res4 = $this->controller->generate($req4);
        $this->assertSame(201, $res4->getStatusCode());
        $body4 = json_decode($res4->getBody(), true);
        $prefix4 = $body4['data']['prefix'];

        $keyRecord4 = $this->db->fetchOne("SELECT * FROM op_api_keys WHERE key_prefix = :p", ['p' => $prefix4]);
        $this->assertNotNull($keyRecord4);
        $this->assertEquals(['read', 'write'], json_decode($keyRecord4['scopes'], true));

        // Case 5: Custom scopes generated correctly
        $req5 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail], [], [], '{"name": "Custom Scope Key", "scopes": ["read", "admin"]}');
        $req5->setAttribute('merchant_id', 99996);
        $req5->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));

        $res5 = $this->controller->generate($req5);
        $this->assertSame(201, $res5->getStatusCode());
        $body5 = json_decode($res5->getBody(), true);
        $prefix5 = $body5['data']['prefix'];

        $keyRecord5 = $this->db->fetchOne("SELECT * FROM op_api_keys WHERE key_prefix = :p", ['p' => $prefix5]);
        $this->assertNotNull($keyRecord5);
        $this->assertEquals(['read', 'admin'], json_decode($keyRecord5['scopes'], true));
    }

    public function testRevokeRequiresWriteAdminScopesAndValidSuperAdminHeader(): void
    {
        $callerRow = $this->getCallerKeyRow($this->standardKey);
        $keyId = (int) $callerRow['id'];

        // Standard key calling revoke -> forbidden
        $req1 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail]);
        $req1->setAttribute('merchant_id', 99996);
        $req1->setAttribute('api_key', $this->getCallerKeyRow($this->standardKey));
        $req1->setRouteParams(['id' => (string) $keyId]);

        $res1 = $this->controller->revoke($req1);
        $this->assertSame(403, $res1->getStatusCode());

        // Admin key calling revoke with valid superadmin header -> success
        $req2 = new Request([], [], ['HTTP_X_SUPER_ADMIN_EMAIL' => $this->superAdminEmail]);
        $req2->setAttribute('merchant_id', 99996);
        $req2->setAttribute('api_key', $this->getCallerKeyRow($this->adminKey));
        $req2->setRouteParams(['id' => (string) $keyId]);

        $res2 = $this->controller->revoke($req2);
        $this->assertSame(200, $res2->getStatusCode());

        // Assert key is revoked in DB
        $revokedKey = $this->db->fetchOne("SELECT status FROM op_api_keys WHERE id = :id", ['id' => $keyId]);
        $this->assertSame('revoked', $revokedKey['status']);
    }

    public function testAdminGenerateAssignsSelectedScopes(): void
    {
        $adminController = $this->container->get(\OwnPay\Controller\Admin\ApiKeyController::class);

        $req = new Request([], ['label' => 'Web Admin Key', 'scopes' => ['read', 'admin']]);
        $req->setAttribute('merchant_id', 99996);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $res = $adminController->generate($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/developer', $res->getHeaders()['Location'] ?? null);

        $generatedKeyLabel = $_SESSION['_generated_api_key_label'] ?? null;
        $this->assertSame('Web Admin Key', $generatedKeyLabel);

        $keyRecord = $this->db->fetchOne("SELECT * FROM op_api_keys WHERE name = 'Web Admin Key' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($keyRecord);
        $this->assertEquals(['read', 'admin'], json_decode($keyRecord['scopes'], true));
    }

    public function testAdminGenerateInGlobalViewCreatesPlatformKey(): void
    {
        // Tenancy model: All Brands (platform) view manages platform-owned API keys. Generating in
        // global view now creates a key owned by the reserved platform merchant (isolated to All Brands),
        // instead of being blocked.
        $adminController = $this->container->get(\OwnPay\Controller\Admin\ApiKeyController::class);

        $req = new Request([], ['label' => 'Platform Key', 'scopes' => ['read', 'admin']]);
        $req->setAttribute('merchant_id', 0); // All Brands (platform) view

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION['_generated_api_key'], $_SESSION['flash_error']);
        $res = $adminController->generate($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/developer', $res->getHeaders()['Location'] ?? null);
        $this->assertNotEmpty($_SESSION['_generated_api_key'] ?? null, 'A platform-owned key should be generated');
        $this->assertNull($_SESSION['flash_error'] ?? null, 'No "select a brand" error in All Brands view');
    }

    public function testAdminRevokeInGlobalViewTargetsPlatformKeys(): void
    {
        // In All Brands view, revoke targets platform-owned keys (no "select a brand" gate). Revoking a
        // non-existent id is a safe no-op that still redirects with a success flash.
        $adminController = $this->container->get(\OwnPay\Controller\Admin\ApiKeyController::class);

        $req = new Request([], []);
        $req->setAttribute('merchant_id', 0); // All Brands (platform) view
        $req->setRouteParams(['id' => '123']);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION['flash_error']);
        $res = $adminController->revoke($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertNull($_SESSION['flash_error'] ?? null, 'No "select a brand" error in All Brands view');
    }
}
