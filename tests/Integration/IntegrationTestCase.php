<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base class for all live-DB integration tests.
 *
 * If the test database cannot be reached (e.g. in CI without a DB service),
 * every test in the subclass is automatically marked as skipped rather than
 * failing with a PDOException.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static bool $dbAvailable = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
        $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'ownpay_test';
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);

        try {
            Database::init($host, $name, $user, $pass, $port);
            static::$dbAvailable = true;
        } catch (\Throwable) {
            static::$dbAvailable = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Live database not available — skipping integration test.');
            return; // unreachable but signals intent
        }
    }
}
