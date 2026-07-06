<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Repository\ApiKeyRepository;
use OwnPay\Service\Customer\ApiKeyService;

/**
 * Regression coverage for the Developer Hub API Keys restructure: op_api_keys.status
 * gains a 'locked' value (reversible, alongside the existing permanent 'revoked'), and
 * the admin listing must surface every status (previously active-only), not just active.
 */
final class ApiKeyLockUnlockTest extends IntegrationTestCase
{
    private Database $db;
    private ApiKeyRepository $keys;
    private ApiKeyService $service;
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

        $keys = $container->get(ApiKeyRepository::class);
        $this->assertInstanceOf(ApiKeyRepository::class, $keys);
        $this->keys = $keys;

        $service = $container->get(ApiKeyService::class);
        $this->assertInstanceOf(ApiKeyService::class, $service);
        $this->service = $service;

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
        $this->db->execute("DELETE FROM op_api_keys WHERE name LIKE 'zzkey-%'");
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

    public function testStatusColumnAcceptsLocked(): void
    {
        $id = $this->insertKey('zzkey-locked-column-check', 'locked');
        $row = $this->db->fetchOne("SELECT status FROM op_api_keys WHERE id = :id", ['id' => $id]);
        $this->assertSame('locked', $row['status']);
    }

    public function testListAllKeysReturnsEveryStatusForTenant(): void
    {
        $this->insertKey('zzkey-active', 'active');
        $this->insertKey('zzkey-locked', 'locked');
        $this->insertKey('zzkey-revoked', 'revoked');

        $all = $this->keys->forTenant($this->merchantId)->listAllKeys();
        $names = array_column($all, 'name');

        $this->assertContains('zzkey-active', $names);
        $this->assertContains('zzkey-locked', $names);
        $this->assertContains('zzkey-revoked', $names);
    }

    public function testListAllKeysScopedToTenant(): void
    {
        $this->insertKey('zzkey-mine', 'active');

        $otherTenantResult = $this->keys->forTenant(999999)->listAllKeys();
        $names = array_column($otherTenantResult, 'name');

        $this->assertNotContains('zzkey-mine', $names);
    }

    public function testLockTransitionsActiveKeyToLocked(): void
    {
        $id = $this->insertKey('zzkey-to-lock', 'active');

        $affected = $this->service->lock($this->merchantId, $id);

        $this->assertSame(1, $affected);
        $row = $this->db->fetchOne("SELECT status FROM op_api_keys WHERE id = :id", ['id' => $id]);
        $this->assertSame('locked', $row['status']);
    }

    public function testUnlockTransitionsLockedKeyToActive(): void
    {
        $id = $this->insertKey('zzkey-to-unlock', 'locked');

        $affected = $this->service->unlock($this->merchantId, $id);

        $this->assertSame(1, $affected);
        $row = $this->db->fetchOne("SELECT status FROM op_api_keys WHERE id = :id", ['id' => $id]);
        $this->assertSame('active', $row['status']);
    }

    public function testLockIsScopedToTenant(): void
    {
        $id = $this->insertKey('zzkey-cross-tenant', 'active');

        $affected = $this->service->lock(999999, $id);

        $this->assertSame(0, $affected, 'a merchant must not be able to lock a key it does not own');
        $row = $this->db->fetchOne("SELECT status FROM op_api_keys WHERE id = :id", ['id' => $id]);
        $this->assertSame('active', $row['status'], 'status must be unchanged by a wrong-tenant lock attempt');
    }

    public function testListAllMasksHashAndDecodesScopes(): void
    {
        $this->insertKey('zzkey-list-all', 'locked');

        $all = $this->service->listAll($this->merchantId);
        $found = null;
        foreach ($all as $row) {
            if ($row['name'] === 'zzkey-list-all') {
                $found = $row;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertArrayNotHasKey('key_hash', $found);
        $this->assertArrayNotHasKey('hash', $found);
        $this->assertIsArray($found['scopes']);
        $this->assertSame(['read'], $found['scopes']);
        $this->assertSame('locked', $found['status']);
    }
}
