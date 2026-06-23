<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Controller\Install\InstallerController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;

/**
 * Verifies the installer re-arm protection: when storage/.installed is
 * missing but the configured database already contains a superadmin, every
 * wizard endpoint must refuse (and self-heal the marker) instead of letting
 * an unauthenticated visitor drop the schema or mint a new superadmin.
 */
final class InstallerLockTest extends IntegrationTestCase
{
    private const TEST_EMAIL = 'install-lock-test@example.com';
    private const TEST_USERNAME = 'install-lock-test';
    private const TEST_ROLE_SLUG = 'install-lock-test';

    private Database $db;
    private string $tempRoot;

    /** @var array<string, string|null> Environment values to restore in tearDown. */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            return;
        }

        $this->db = Database::getInstance();

        $merchant = $this->db->fetchOne("SELECT id FROM op_merchants WHERE id = 1 LIMIT 1");
        if ($merchant === null) {
            $this->db->execute(
                "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (1, 'merchant-uuid-1', 'Test Merchant', 'test-merchant-1', 'test1@example.com', 'active', '{}')"
            );
        }

        $this->cleanSeededRows();

        $this->db->execute(
            "INSERT INTO op_roles (merchant_id, name, slug, description, is_system, created_at)
             VALUES (1, 'Install Lock Test', :slug, 'fixture', 1, NOW())",
            ['slug' => self::TEST_ROLE_SLUG]
        );
        $roleId = (int) $this->db->lastInsertId();

        $this->db->execute(
            "INSERT INTO op_merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at, updated_at)
             VALUES (1, :role, 'Install Lock Test', :username, :email, :hash, 1, 'active', NOW(), NOW())",
            [
                'role'     => $roleId,
                'username' => self::TEST_USERNAME,
                'email'    => self::TEST_EMAIL,
                'hash'     => password_hash('irrelevant-password', PASSWORD_ARGON2ID),
            ]
        );

        // Isolated root: the controller's marker file and .env.temp paths must
        // not touch the real installation in this working copy.
        $this->tempRoot = sys_get_temp_dir() . '/op_installer_lock_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot . '/storage', 0777, true);
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanSeededRows();
        }
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->envBackup = [];
        if (isset($this->tempRoot) && is_dir($this->tempRoot)) {
            @unlink($this->tempRoot . '/storage/.installed');
            @unlink($this->tempRoot . '/storage/.env.temp');
            @rmdir($this->tempRoot . '/storage');
            @rmdir($this->tempRoot);
        }
        parent::tearDown();
    }

    private function cleanSeededRows(): void
    {
        $this->db->execute("DELETE FROM op_merchant_users WHERE email = :e", ['e' => self::TEST_EMAIL]);
        $this->db->execute("DELETE FROM op_roles WHERE slug = :s", ['s' => self::TEST_ROLE_SLUG]);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $existing = $_ENV[$key] ?? null;
            $this->envBackup[$key] = is_string($existing) ? $existing : null;
        }
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
    }

    /**
     * Builds an installer controller whose filesystem paths point at the
     * isolated temp root instead of the real project.
     */
    private function makeController(): InstallerController
    {
        $controller = new InstallerController();

        $rootProp = new \ReflectionProperty(InstallerController::class, 'rootDir');
        $rootProp->setValue($controller, $this->tempRoot);
        $markerProp = new \ReflectionProperty(InstallerController::class, 'markerFile');
        $markerProp->setValue($controller, $this->tempRoot . '/storage/.installed');

        return $controller;
    }

    /**
     * @param array<string, string> $extraServer
     */
    private function postJson(array $body, array $extraServer = []): Request
    {
        $raw = json_encode($body);
        return new Request(
            [],
            [],
            array_merge(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install/create-admin'], $extraServer),
            [],
            [],
            $raw === false ? '{}' : $raw
        );
    }

    public function testWizardRefusesWhenMarkerMissingButDatabaseInstalled(): void
    {
        $controller = $this->makeController();
        $markerPath = $this->tempRoot . '/storage/.installed';
        $this->assertFileDoesNotExist($markerPath);

        $response = $controller->createAdmin($this->postJson([
            'name'     => 'Evil Admin',
            'email'    => 'evil@example.com',
            'username' => 'evil',
            'password' => 'password123',
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('Already installed', $body['error']);

        $this->assertFileExists($markerPath, 'Marker must self-heal when the DB probe confirms an installation');

        $evil = $this->db->fetchOne("SELECT id FROM op_merchant_users WHERE email = 'evil@example.com' LIMIT 1");
        $this->assertNull($evil, 'No superadmin may be created through the re-armed wizard');
    }

    public function testImportSchemaRefusedWhenDatabaseInstalled(): void
    {
        $controller = $this->makeController();

        $response = $controller->importSchema($this->postJson([
            'host' => 'localhost',
            'name' => 'ownpay_test',
            'user' => 'root',
            'pass' => 'root',
            'confirm_overwrite' => 1,
        ]));

        $this->assertSame(403, $response->getStatusCode(), 'Schema overwrite must be refused on an installed database');
    }

    public function testForceKeyAllowsDeliberateReinstall(): void
    {
        $this->setEnv('INSTALL_FORCE_KEY', 'force-key-0123456789abcdef');
        $controller = $this->makeController();

        $response = $controller->createAdmin($this->postJson(
            [
                'name'     => 'Rebuild Admin',
                'email'    => 'rebuild@example.com',
                'username' => 'rebuild',
                'password' => 'password123',
            ],
            ['HTTP_X_INSTALL_FORCE_KEY' => 'force-key-0123456789abcdef']
        ));

        // Past the install lock, the wizard enforces step order: with no
        // .env.temp the request stops with 400, not the 403 lock.
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('Complete DB step first', $body['error']);
    }

    public function testShortForceKeyNeverUnlocks(): void
    {
        $this->setEnv('INSTALL_FORCE_KEY', 'short');
        $controller = $this->makeController();

        $response = $controller->createAdmin($this->postJson(
            [
                'name'     => 'Evil Admin',
                'email'    => 'evil2@example.com',
                'username' => 'evil2',
                'password' => 'password123',
            ],
            ['HTTP_X_INSTALL_FORCE_KEY' => 'short']
        ));

        $this->assertSame(403, $response->getStatusCode(), 'A weak force key must not unlock a populated installation');
    }

    public function testFreshInstallNotBlockedWhenDatabaseUnreachable(): void
    {
        // Simulate a fresh box: env points at a database that does not exist,
        // so the probe must fail open and let the wizard run.
        $this->setEnv('DB_NAME', 'op_no_such_database_xyz');
        $controller = $this->makeController();

        $response = $controller->createAdmin($this->postJson([
            'name'     => 'First Admin',
            'email'    => 'first@example.com',
            'username' => 'first',
            'password' => 'password123',
        ]));

        // Not the install lock (403) — the wizard proceeds and stops at the
        // step-order check because the DB step has not run yet.
        $this->assertSame(400, $response->getStatusCode());
    }
}
