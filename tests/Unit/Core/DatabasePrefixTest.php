<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use OwnPay\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DatabasePrefixTest extends TestCase
{
    public function testConfiguredPrefixRewritesCanonicalTableIdentifiers(): void
    {
        $database = new Database(new PDO('sqlite::memory:'), 'cz_');
        $method = new ReflectionMethod(Database::class, 'applyTablePrefix');
        $method->setAccessible(true);

        $sql = $method->invoke(
            $database,
            "SELECT * FROM op_users u JOIN `op_roles` r ON r.id = u.role_id WHERE u.note = 'op_users'"
        );

        self::assertSame(
            "SELECT * FROM cz_users u JOIN `cz_roles` r ON r.id = u.role_id WHERE u.note = 'op_users'",
            $sql
        );
    }

    public function testDefaultPrefixLeavesCanonicalSqlUnchanged(): void
    {
        $database = new Database(new PDO('sqlite::memory:'));
        $method = new ReflectionMethod(Database::class, 'applyTablePrefix');
        $method->setAccessible(true);

        self::assertSame('SELECT * FROM op_users', $method->invoke($database, 'SELECT * FROM op_users'));
    }
}
