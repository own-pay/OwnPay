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
            $this->markTestSkipped('Live database not available - skipping integration test.');
            return; // unreachable but signals intent
        }

        // Seed a default test merchant with id=1 (platform-owned) so that tests
        // which hardcode `merchant_id = 1` in child tables (op_api_keys,
        // op_transactions, op_paired_devices, op_fee_rules, op_system_settings,
        // op_roles, ...) do not trip FK constraint 1452. INSERT IGNORE is used
        // so this call is idempotent: a subclass setUp that explicitly DELETEs
        // the row before re-inserting its own version is unaffected, and a
        // subclass setUp that does a plain INSERT will already have a row
        // present (those tests use the "SELECT then INSERT" pattern, see
        // LedgerServiceTest / WebhookIdempotencyTest / etc., so the seed
        // short-circuits their INSERT branch harmlessly).
        $db = Database::getInstance();
        $db->execute(
            "INSERT IGNORE INTO op_merchants (id, uuid, name, slug, email, status, is_platform, settings)
             VALUES (1, 'seed-platform-uuid', 'Seed Platform', 'seed-platform', 'seed@ownpay.test', 'active', 1, '{}')"
        );
    }

    /**
     * Ensures a merchant row exists for the given id, creating one with
     * sensible defaults if it does not. Useful for tests that need a
     * non-platform brand merchant (id != 1) to verify brand scoping.
     *
     * @param int  $id          The merchant id to ensure.
     * @param bool $isPlatform  Whether the merchant should be marked as the platform owner.
     */
    protected function ensureMerchant(int $id, bool $isPlatform = false): void
    {
        Database::getInstance()->execute(
            "INSERT IGNORE INTO op_merchants (id, uuid, name, slug, email, status, is_platform, settings)
             VALUES (:id, :uuid, :name, :slug, :email, 'active', :is_platform, '{}')",
            [
                'id'          => $id,
                'uuid'        => 'seed-merchant-' . $id,
                'name'        => 'Seed Merchant ' . $id,
                'slug'        => 'seed-merchant-' . $id,
                'email'       => 'seed-' . $id . '@ownpay.test',
                'is_platform' => $isPlatform ? 1 : 0,
            ]
        );
    }
}
