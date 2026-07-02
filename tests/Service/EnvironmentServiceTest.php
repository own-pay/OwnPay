<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Core\Database;
use OwnPay\Service\System\EnvironmentService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EnvironmentServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $_ENV['DB_PREFIX'] = 'op_';

        $this->pdo->exec(<<<SQL
            CREATE TABLE op_system_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT NOT NULL DEFAULT 'general',
                key_name TEXT NOT NULL,
                value TEXT,
                type TEXT NOT NULL DEFAULT 'string',
                merchant_id INTEGER DEFAULT NULL
            )
        SQL);

        $this->pdo->exec("INSERT INTO op_system_settings (group_name, key_name, value, type, merchant_id) VALUES ('runtime', 'site_name', 'OwnPay', 'string', NULL)");
        $this->pdo->exec("INSERT INTO op_system_settings (group_name, key_name, value, type, merchant_id) VALUES ('runtime', 'theme', 'dark', 'string', 1)");

        Database::reset();
        $reflection = new ReflectionClass(Database::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setValue($instance, $this->pdo);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $instance);

        $reflectionSvc = new ReflectionClass(EnvironmentService::class);
        $repoProperty = $reflectionSvc->getProperty('settingsRepo');
        $repoProperty->setValue(null, null);

        EnvironmentService::clearCache();
    }

    protected function tearDown(): void
    {
        Database::reset();
        $reflectionSvc = new ReflectionClass(EnvironmentService::class);
        $repoProperty = $reflectionSvc->getProperty('settingsRepo');
        $repoProperty->setValue(null, null);

        EnvironmentService::clearCache();
        unset($_ENV['DB_PREFIX']);
    }

    public function testGetReturnsExistingValue(): void
    {
        $this->assertSame('OwnPay', EnvironmentService::get('site_name'));
    }

    public function testGetReturnsBrandSpecificValue(): void
    {
        $this->assertSame('dark', EnvironmentService::get('theme', '1'));
    }

    public function testGetReturnsEmptyStringForMissingKey(): void
    {
        $this->assertSame('', EnvironmentService::get('nonexistent_key'));
    }

    public function testGetDoesNotAutoCreateMissingRow(): void
    {
        EnvironmentService::get('not_yet_set');
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM op_system_settings WHERE key_name = 'not_yet_set'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testGetUsesInMemoryCacheOnSecondCall(): void
    {
        $this->assertSame('OwnPay', EnvironmentService::get('site_name'));

        $this->pdo->exec("UPDATE op_system_settings SET value = 'CHANGED' WHERE key_name = 'site_name' AND merchant_id IS NULL");

        $this->assertSame('OwnPay', EnvironmentService::get('site_name'));

        EnvironmentService::clearCache();
        $this->assertSame('CHANGED', EnvironmentService::get('site_name'));
    }

    public function testSetCreatesNewRow(): void
    {
        $value = EnvironmentService::set('new_option', 'new-value');
        $this->assertSame('new-value', $value);

        $row = $this->pdo->query("SELECT value FROM op_system_settings WHERE key_name = 'new_option' AND merchant_id IS NULL")->fetch();
        $this->assertSame('new-value', $row['value']);
    }

    public function testSetUpdatesExistingRow(): void
    {
        $value = EnvironmentService::set('site_name', 'NewName');
        $this->assertSame('NewName', $value);

        $rows = $this->pdo->query("SELECT * FROM op_system_settings WHERE key_name = 'site_name' AND merchant_id IS NULL")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('NewName', $rows[0]['value']);
    }

    public function testSetUpdatesCacheImmediately(): void
    {
        $this->assertSame('NewName', EnvironmentService::set('site_name', 'NewName'));
        $this->assertSame('NewName', EnvironmentService::get('site_name'));
    }

    public function testSetIsBrandScoped(): void
    {
        EnvironmentService::set('theme', 'light', '1');
        EnvironmentService::set('theme', 'matrix', '2');

        $this->assertSame('light', EnvironmentService::get('theme', '1'));
        $this->assertSame('matrix', EnvironmentService::get('theme', '2'));
        $this->assertSame('', EnvironmentService::get('theme', 'both'));
    }

    public function testDeleteRemovesRow(): void
    {
        $this->assertTrue(EnvironmentService::delete('site_name'));

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM op_system_settings WHERE key_name = 'site_name' AND merchant_id IS NULL")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDeleteEvictsFromCache(): void
    {
        EnvironmentService::get('site_name');
        EnvironmentService::delete('site_name');
        $this->assertSame('', EnvironmentService::get('site_name'));
    }

    public function testDeleteOnMissingRowReturnsTrue(): void
    {
        $this->assertTrue(EnvironmentService::delete('never_existed'));
    }

    public function testClearCacheForcesReReadFromDb(): void
    {
        $this->assertSame('OwnPay', EnvironmentService::get('site_name'));

        $this->pdo->exec("UPDATE op_system_settings SET value = 'AfterClear' WHERE key_name = 'site_name' AND merchant_id IS NULL");

        EnvironmentService::clearCache();
        $this->assertSame('AfterClear', EnvironmentService::get('site_name'));
    }
}
