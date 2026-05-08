<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OwnPay\Core\Database;
use OwnPay\Service\System\EnvironmentService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class EnvironmentServiceTest extends TestCase
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
            CREATE TABLE op_env (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brand_id TEXT NOT NULL,
                option_name TEXT NOT NULL,
                value TEXT,
                created_date TEXT,
                updated_date TEXT
            )
        SQL);

        $this->pdo->exec("INSERT INTO op_env (brand_id, option_name, value, created_date, updated_date) VALUES ('both', 'site_name', 'OwnPay', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO op_env (brand_id, option_name, value, created_date, updated_date) VALUES ('brand-1', 'theme', 'dark', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");

        Database::reset();
        $reflection = new ReflectionClass(Database::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setValue($instance, $this->pdo);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $instance);

        EnvironmentService::clearCache();
    }

    protected function tearDown(): void
    {
        Database::reset();
        EnvironmentService::clearCache();
        unset($_ENV['DB_PREFIX']);
    }

    public function testGetReturnsExistingValue(): void
    {
        $this->assertSame('OwnPay', EnvironmentService::get('site_name'));
    }

    public function testGetReturnsBrandSpecificValue(): void
    {
        $this->assertSame('dark', EnvironmentService::get('theme', 'brand-1'));
    }

    public function testGetReturnsEmptyStringForMissingKey(): void
    {
        $this->assertSame('', EnvironmentService::get('nonexistent_key'));
    }

    public function testGetDoesNotAutoCreateMissingRow(): void
    {
        EnvironmentService::get('not_yet_set');
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM op_env WHERE option_name = 'not_yet_set'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testGetUsesInMemoryCacheOnSecondCall(): void
    {
        $this->assertSame('OwnPay', EnvironmentService::get('site_name'));

        $this->pdo->exec("UPDATE op_env SET value = 'CHANGED' WHERE option_name = 'site_name'");

        $this->assertSame('OwnPay', EnvironmentService::get('site_name'));

        EnvironmentService::clearCache();
        $this->assertSame('CHANGED', EnvironmentService::get('site_name'));
    }

    public function testSetCreatesNewRow(): void
    {
        $value = EnvironmentService::set('new_option', 'new-value');
        $this->assertSame('new-value', $value);

        $row = $this->pdo->query("SELECT value FROM op_env WHERE option_name = 'new_option'")->fetch();
        $this->assertSame('new-value', $row['value']);
    }

    public function testSetUpdatesExistingRow(): void
    {
        $value = EnvironmentService::set('site_name', 'NewName');
        $this->assertSame('NewName', $value);

        $rows = $this->pdo->query("SELECT * FROM op_env WHERE option_name = 'site_name'")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('NewName', $rows[0]['value']);
    }

    public function testSetUpdatesCacheImmediately(): void
    {
        EnvironmentService::set('site_name', 'CachedNewName');
        $this->assertSame('CachedNewName', EnvironmentService::get('site_name'));
    }

    public function testSetIsBrandScoped(): void
    {
        EnvironmentService::set('theme', 'light', 'brand-1');
        EnvironmentService::set('theme', 'matrix', 'brand-2');

        $this->assertSame('light', EnvironmentService::get('theme', 'brand-1'));
        $this->assertSame('matrix', EnvironmentService::get('theme', 'brand-2'));
        $this->assertSame('', EnvironmentService::get('theme', 'both'));
    }

    public function testDeleteRemovesRow(): void
    {
        $this->assertTrue(EnvironmentService::delete('site_name'));

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM op_env WHERE option_name = 'site_name'")->fetchColumn();
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

        $this->pdo->exec("UPDATE op_env SET value = 'AfterClear' WHERE option_name = 'site_name'");

        EnvironmentService::clearCache();
        $this->assertSame('AfterClear', EnvironmentService::get('site_name'));
    }
}
