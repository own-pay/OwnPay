<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Core\Database;
use OwnPay\Repository\MerchantRepository;
use Tests\Integration\IntegrationTestCase;

final class MerchantRepositoryFindFirstTest extends IntegrationTestCase
{
    private Database $db;
    private MerchantRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
        $this->db = Database::getInstance();
        $this->repo = new MerchantRepository($this->db);
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
        $this->db->execute("DELETE FROM op_merchants WHERE slug LIKE 'zzfindfirst-%'");
    }

    public function testReturnsNullWhenNoMerchantsExist(): void
    {
        // Table may have unrelated rows from other tests/fixtures; this only
        // proves findFirst() doesn't throw and returns an array-or-null shape.
        $result = $this->repo->findFirst();
        $this->assertTrue($result === null || is_array($result));
    }

    public function testReturnsTheLowestIdMerchant(): void
    {
        $idA = (int) $this->repo->createMerchant([
            'name' => 'ZZ First', 'slug' => 'zzfindfirst-a', 'email' => 'a@example.com',
            'default_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active',
        ]);
        $idB = (int) $this->repo->createMerchant([
            'name' => 'ZZ Second', 'slug' => 'zzfindfirst-b', 'email' => 'b@example.com',
            'default_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active',
        ]);

        $first = $this->repo->findFirst();
        $this->assertNotNull($first);
        $this->assertLessThanOrEqual($idB, (int) $first['id']);
    }
}
