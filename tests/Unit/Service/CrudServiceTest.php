<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OwnPay\Core\Database;
use OwnPay\Service\CrudService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CrudServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(<<<SQL
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                amount INTEGER DEFAULT 0,
                version INTEGER DEFAULT 1,
                note TEXT
            )
        SQL);

        $this->pdo->exec("INSERT INTO items (name, amount, version, note) VALUES ('alpha', 100, 1, 'first')");
        $this->pdo->exec("INSERT INTO items (name, amount, version, note) VALUES ('beta', 200, 1, 'second')");
        $this->pdo->exec("INSERT INTO items (name, amount, version, note) VALUES ('gamma', 300, 1, '--')");

        Database::reset();
        $reflection = new ReflectionClass(Database::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setValue($instance, $this->pdo);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $instance);
    }

    protected function tearDown(): void
    {
        Database::reset();
    }

    public function testSelectReturnsAllRows(): void
    {
        $result = CrudService::select('items');
        $this->assertTrue($result['status']);
        $this->assertCount(3, $result['response']);
    }

    public function testSelectReturnsEmptyResponseForNoMatch(): void
    {
        $result = CrudService::select('items', 'WHERE name = :name', '* FROM', [':name' => 'nonexistent']);
        $this->assertFalse($result['status']);
        $this->assertSame([], $result['response']);
    }

    public function testSelectAppliesNamedParameters(): void
    {
        $result = CrudService::select('items', 'WHERE name = :name', '* FROM', [':name' => 'beta']);
        $this->assertTrue($result['status']);
        $this->assertCount(1, $result['response']);
        $this->assertSame('beta', $result['response'][0]['name']);
        $this->assertSame(200, (int) $result['response'][0]['amount']);
    }

    public function testSelectConvertsLegacyDashSentinelToNull(): void
    {
        $result = CrudService::select('items', 'WHERE name = :name', '* FROM', [':name' => 'gamma']);
        $this->assertTrue($result['status']);
        $this->assertNull($result['response'][0]['note']);
    }

    public function testSelectAppliesSelectExpression(): void
    {
        $result = CrudService::select('items', 'ORDER BY id', 'name FROM');
        $this->assertTrue($result['status']);
        $this->assertSame(['name' => 'alpha'], $result['response'][0]);
    }

    public function testSelectReturnsErrorStatusOnInvalidTable(): void
    {
        $previousErrorLog = ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'crud-test'));
        try {
            $result = CrudService::select('nonexistent_table');
        } finally {
            ini_set('error_log', $previousErrorLog ?: '');
        }
        $this->assertFalse($result['status']);
        $this->assertSame([], $result['response']);
    }

    public function testUpdateModifiesMatchingRows(): void
    {
        $ok = CrudService::update(
            'items',
            ['amount'],
            [999],
            'name = :name',
            [':name' => 'alpha'],
        );
        $this->assertTrue($ok);

        $row = $this->pdo->query("SELECT amount FROM items WHERE name = 'alpha'")->fetch();
        $this->assertSame(999, (int) $row['amount']);
    }

    public function testUpdateBindsNullForEmptyOrNullValue(): void
    {
        $ok = CrudService::update(
            'items',
            ['note'],
            [null],
            'name = :name',
            [':name' => 'alpha'],
        );
        $this->assertTrue($ok);
        $row = $this->pdo->query("SELECT note FROM items WHERE name = 'alpha'")->fetch();
        $this->assertNull($row['note']);
    }

    public function testUpdateMultipleColumns(): void
    {
        $ok = CrudService::update(
            'items',
            ['amount', 'note'],
            [555, 'updated'],
            'name = :name',
            [':name' => 'beta'],
        );
        $this->assertTrue($ok);
        $row = $this->pdo->query("SELECT amount, note FROM items WHERE name = 'beta'")->fetch();
        $this->assertSame(555, (int) $row['amount']);
        $this->assertSame('updated', $row['note']);
    }

    public function testDeleteRemovesMatchingRows(): void
    {
        $ok = CrudService::delete('items', 'name = :name', [':name' => 'alpha']);
        $this->assertTrue($ok);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testDeleteWithNoMatchStillSucceeds(): void
    {
        $ok = CrudService::delete('items', 'name = :name', [':name' => 'nonexistent']);
        $this->assertTrue($ok);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function testCountReturnsTotalRowsWithoutCondition(): void
    {
        $this->assertSame(3, CrudService::count('items'));
    }

    public function testCountReturnsFilteredCountWithCondition(): void
    {
        $this->assertSame(2, CrudService::count('items', "WHERE amount >= 200"));
    }

    public function testCountReturnsZeroOnError(): void
    {
        $previousErrorLog = ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'crud-test'));
        try {
            $this->assertSame(0, CrudService::count('nonexistent_table'));
        } finally {
            ini_set('error_log', $previousErrorLog ?: '');
        }
    }

    public function testOptimisticUpdateSucceedsWithCorrectVersion(): void
    {
        $rows = CrudService::optimisticUpdate(
            'items',
            ['amount'],
            [777],
            'name = :name',
            [':name' => 'alpha'],
            currentVersion: 1,
        );
        $this->assertSame(1, $rows);

        $row = $this->pdo->query("SELECT amount, version FROM items WHERE name = 'alpha'")->fetch();
        $this->assertSame(777, (int) $row['amount']);
        $this->assertSame(2, (int) $row['version']);
    }

    public function testOptimisticUpdateReturnsZeroForStaleVersion(): void
    {
        $rows = CrudService::optimisticUpdate(
            'items',
            ['amount'],
            [777],
            'name = :name',
            [':name' => 'alpha'],
            currentVersion: 99,
        );
        $this->assertSame(0, $rows);

        $row = $this->pdo->query("SELECT amount, version FROM items WHERE name = 'alpha'")->fetch();
        $this->assertSame(100, (int) $row['amount']);
        $this->assertSame(1, (int) $row['version']);
    }
}
