<?php

declare(strict_types=1);

namespace OwnPay\Security {
    if (!function_exists('OwnPay\Security\gethostbynamel')) {
        function gethostbynamel(string $hostname): array|false
        {
            return ['8.8.8.8'];
        }
    }
}

namespace Tests\Integration {

    use OwnPay\Controller\Install\InstallerController;
    use OwnPay\Core\Database;
    use OwnPay\Http\Request;
    use OwnPay\Service\System\HttpClient;

    final class InstallerSyncRatesTest extends IntegrationTestCase
    {
        private const TEST_EMAIL = 'installer-sync-test@example.com';
        private const TEST_USERNAME = 'installer-sync-test';
        private const TEST_ROLE_SLUG = 'installer-sync-test';

        /**
         * Every (group, key) pair that finalize() seeds via INSERT IGNORE.
         * Used to back up / restore / delete these keys deterministically
         * regardless of what other tests in the suite left behind.
         *
         * @var array<string, string[]>
         */
        private const SEEDED_SETTINGS = [
            'general'    => ['app_name', 'timezone', 'currency', 'version', 'base_currency', 'exchange_rate_mode'],
            'appearance' => ['active_theme'],
            'branding'   => ['site_name', 'site_logo', 'site_favicon', 'app_logo_light', 'app_logo_dark', 'primary_color', 'footer_text'],
            'mail'       => ['driver', 'from_address', 'from_name'],
            'payment'    => ['default_gateway', 'success_url', 'cancel_url'],
        ];

        private Database $db;
        private string $tempRoot;

        /** @var array<string, string|null> */
        private array $envBackup = [];

        /** @var array<string, array{group: string, key: string, value: string, type: string}> */
        private array $settingsBackup = [];

        protected function setUp(): void
        {
            parent::setUp();

            if (!static::$dbAvailable) {
                return;
            }

            $this->db = Database::getInstance();

            $this->cleanSeededRows();

            $roleId = $this->seedSuperadmin();

            // Seed default currencies exactly as InstallerController::createAdmin() does.
            $this->db->execute("DELETE FROM op_exchange_rates");
            $this->db->execute("DELETE FROM op_currencies");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('BDT', 'Bangladeshi Taka',  '৳', 2, 'active')");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('USD', 'US Dollar',         '$', 2, 'active')");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('EUR', 'Euro',              '€', 2, 'active')");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('GBP', 'British Pound',     '£', 2, 'active')");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('INR', 'Indian Rupee',      '₹', 2, 'active')");

            // Back up + clear every setting finalize() seeds so INSERT IGNORE
            // probes land deterministically. Does not rely on test ordering.
            foreach (self::SEEDED_SETTINGS as $group => $keys) {
                $this->backupSettings($group, $keys);
            }

            // Isolated root so marker file and .env.temp paths don't touch a real installation.
            $this->tempRoot = sys_get_temp_dir() . '/op_installer_sync_' . bin2hex(random_bytes(6));
            mkdir($this->tempRoot . '/storage', 0777, true);
            copy(dirname(__DIR__, 2) . '/.env.example', $this->tempRoot . '/.env.example');

            HttpClient::$mockResponses = null;
        }

        protected function tearDown(): void
        {
            HttpClient::$mockResponses = null;

            if (static::$dbAvailable) {
                $this->db->execute("DELETE FROM op_exchange_rates");
                $this->db->execute("DELETE FROM op_currencies");
                $this->restoreSettings();
                $this->cleanSeededRows();
                $this->dropCustomPrefixTables();
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
                @unlink($this->tempRoot . '/.env');
                @unlink($this->tempRoot . '/storage/.env.temp');
                @unlink($this->tempRoot . '/storage/.installed');
                @rmdir($this->tempRoot . '/storage/languages');
                @rmdir($this->tempRoot . '/storage');
                @rmdir($this->tempRoot);
            }

            parent::tearDown();
        }

        private function seedSuperadmin(): int
        {
            $merchant = $this->db->fetchOne("SELECT id FROM op_merchants WHERE id = 1 LIMIT 1");
            if ($merchant === null) {
                $this->db->execute(
                    "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                     VALUES (1, 'installer-sync-uuid', 'Test Merchant', 'test-merchant-installer-sync', 'installer-sync@example.com', 'active', '{}')"
                );
            }

            $this->db->execute(
                "INSERT INTO op_roles (merchant_id, name, slug, description, is_system, created_at)
                 VALUES (1, 'Installer Sync Test', :slug, 'fixture', 1, NOW())",
                ['slug' => self::TEST_ROLE_SLUG]
            );
            $roleId = (int) $this->db->lastInsertId();

            $this->db->execute(
                "INSERT INTO op_merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at, updated_at)
                 VALUES (1, :role, 'Installer Sync Test', :username, :email, :hash, 1, 'active', NOW(), NOW())",
                [
                    'role'     => $roleId,
                    'username' => self::TEST_USERNAME,
                    'email'    => self::TEST_EMAIL,
                    'hash'     => password_hash('irrelevant-password', PASSWORD_ARGON2ID),
                ]
            );

            return $roleId;
        }

        private function cleanSeededRows(): void
        {
            $this->db->execute("DELETE FROM op_merchant_users WHERE email = :e", ['e' => self::TEST_EMAIL]);
            $this->db->execute("DELETE FROM op_roles WHERE slug = :s", ['s' => self::TEST_ROLE_SLUG]);
        }

        /**
         * @param string[] $keys
         */
        private function backupSettings(string $group, array $keys): void
        {
            foreach ($keys as $key) {
                $row = $this->db->fetchOne(
                    "SELECT `value`, `type` FROM op_system_settings WHERE `group_name` = :g AND `key_name` = :k AND `merchant_id` IS NULL LIMIT 1",
                    ['g' => $group, 'k' => $key]
                );
                if ($row !== null) {
                    $this->settingsBackup[] = [
                        'group' => $group,
                        'key'   => $key,
                        'value' => (string) $row['value'],
                        'type'  => (string) $row['type'],
                    ];
                }
                $this->db->execute(
                    "DELETE FROM op_system_settings WHERE `group_name` = :g AND `key_name` = :k AND `merchant_id` IS NULL",
                    ['g' => $group, 'k' => $key]
                );
            }
        }

        private function restoreSettings(): void
        {
            foreach (self::SEEDED_SETTINGS as $group => $keys) {
                foreach ($keys as $key) {
                    $this->db->execute(
                        "DELETE FROM op_system_settings WHERE `group_name` = :g AND `key_name` = :k AND `merchant_id` IS NULL",
                        ['g' => $group, 'k' => $key]
                    );
                }
            }
            foreach ($this->settingsBackup as $s) {
                $this->db->execute(
                    "INSERT INTO op_system_settings (group_name, key_name, value, type) VALUES (:g, :k, :v, :t)",
                    ['g' => $s['group'], 'k' => $s['key'], 'v' => $s['value'], 't' => $s['type']]
                );
            }
            $this->settingsBackup = [];
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
        private function postFinalize(array $post, array $extraServer = []): Request
        {
            $raw = json_encode($post);
            return new Request(
                [],
                [],
                array_merge(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install/finalize'], $extraServer),
                [],
                [],
                $raw === false ? '{}' : $raw
            );
        }

        /**
         * Writes storage/.env.temp pointing at the real (reachable) test DB so
         * tempEnvHasSuperadmin() and finalize() itself connect to it, while
         * $_ENV['DB_NAME'] is pointed at a non-existent DB so databaseLooksInstalled()
         * returns false (fresh install gate).
         */
        private function writeTempEnv(string $prefix = 'op_'): void
        {
            $realName = $this->envBackup['DB_NAME'] ?? ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'ownpay_test');
            $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
            $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
            $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
            $port = isset($_ENV['DB_PORT']) && $_ENV['DB_PORT'] !== '' ? $_ENV['DB_PORT'] : (getenv('DB_PORT') ?: '3306');

            file_put_contents($this->tempRoot . '/storage/.env.temp', implode("\n", [
                'DB_HOST=' . $host,
                'DB_PORT=' . $port,
                'DB_NAME=' . (is_string($realName) ? $realName : 'ownpay_test'),
                'DB_USER=' . $user,
                'DB_PASS=' . $pass,
                'DB_PREFIX=' . $prefix,
            ]));
        }

        private function dropCustomPrefixTables(): void
        {
            foreach (['exchange_rates', 'currencies', 'system_settings', 'merchant_users', 'roles', 'merchants', 'languages'] as $table) {
                $this->db->execute("DROP TABLE IF EXISTS custom_{$table}");
            }
        }

        /**
         * Clones the op_ tables under a custom_ prefix (LIKE does not copy
         * foreign keys, so the clone needs no cross-prefix FK targets).
         */
        private function createCustomPrefixTables(): void
        {
            $this->dropCustomPrefixTables();
            foreach (['merchants', 'roles', 'merchant_users', 'system_settings', 'currencies', 'exchange_rates', 'languages'] as $table) {
                $this->db->execute("CREATE TABLE custom_{$table} LIKE op_{$table}");
            }
        }

        public function testFinalizeSyncsExchangeRatesOnFreshInstall(): void
        {
            $this->setEnv('DB_NAME', 'op_no_such_database_xyz');
            $this->writeTempEnv();

            $mockJson = json_encode([
                'date' => '2026-09-11',
                'usd' => [
                    'usd' => 1.0,
                    'bdt' => 118.0,
                    'eur' => 0.93,
                    'gbp' => 0.79,
                    'inr' => 84.0,
                ],
            ]);

            HttpClient::$mockResponses = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json' => [
                    'status'  => 200,
                    'body'    => $mockJson,
                    'headers' => [],
                ],
            ];

            $controller = $this->makeController();
            $response = $controller->finalize($this->postFinalize([
                'app_name' => 'Installer Sync Test App',
                'currency' => 'USD',
                'timezone' => 'Asia/Dhaka',
            ]));

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertTrue($body['success']);
            $this->assertSame('Installation complete', $body['message']);

            $this->assertFileExists($this->tempRoot . '/storage/.installed', '.installed marker must exist after finalize');

            $rates = $this->db->fetchAll(
                "SELECT target_currency, rate FROM op_exchange_rates WHERE base_currency = 'USD' ORDER BY target_currency"
            );
            $map = [];
            foreach ($rates as $r) {
                $map[(string) $r['target_currency']] = (string) $r['rate'];
            }

            $this->assertSame('1.00000000', $map['USD'] ?? null, 'Base currency self-rate must be seeded');
            $this->assertSame('118.00000000', $map['BDT'] ?? null, 'USD->BDT rate must be synced');
            $this->assertSame('0.93000000', $map['EUR'] ?? null, 'USD->EUR rate must be synced');
            $this->assertSame('0.79000000', $map['GBP'] ?? null, 'USD->GBP rate must be synced');
            $this->assertSame('84.00000000', $map['INR'] ?? null, 'USD->INR rate must be synced');
        }

        public function testFinalizeSucceedsEvenWhenRateSyncFails(): void
        {
            $this->setEnv('DB_NAME', 'op_no_such_database_xyz');
            $this->writeTempEnv();

            HttpClient::$mockResponses = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json' => [
                    'status'  => 404,
                    'body'    => 'Not Found',
                    'headers' => [],
                ],
                'https://latest.currency-api.pages.dev/v1/currencies/usd.json' => [
                    'status'  => 404,
                    'body'    => 'Not Found',
                    'headers' => [],
                ],
            ];

            $controller = $this->makeController();
            $response = $controller->finalize($this->postFinalize([
                'app_name' => 'Installer Sync Test App',
                'currency' => 'USD',
                'timezone' => 'Asia/Dhaka',
            ]));

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertTrue($body['success']);
            $this->assertSame('Installation complete', $body['message']);

            $this->assertFileExists($this->tempRoot . '/storage/.installed', 'Installer must not be blocked by a failed rate sync');

            $count = (int) $this->db->fetchOne("SELECT COUNT(*) AS c FROM op_exchange_rates")['c'];
            $this->assertSame(0, $count, 'No rates may be written when the sync fails');
        }

        public function testExchangeRateModeSeededAsAuto(): void
        {
            $this->setEnv('DB_NAME', 'op_no_such_database_xyz');
            $this->writeTempEnv();

            HttpClient::$mockResponses = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json' => [
                    'status'  => 404,
                    'body'    => 'Not Found',
                    'headers' => [],
                ],
                'https://latest.currency-api.pages.dev/v1/currencies/usd.json' => [
                    'status'  => 404,
                    'body'    => 'Not Found',
                    'headers' => [],
                ],
            ];

            $controller = $this->makeController();
            $response = $controller->finalize($this->postFinalize([
                'app_name' => 'Installer Sync Test App',
                'currency' => 'USD',
                'timezone' => 'Asia/Dhaka',
            ]));

            $this->assertSame(200, $response->getStatusCode());

            $row = $this->db->fetchOne(
                "SELECT `value` FROM op_system_settings WHERE `group_name` = 'general' AND `key_name` = 'exchange_rate_mode' AND `merchant_id` IS NULL LIMIT 1"
            );
            $this->assertNotNull($row, 'exchange_rate_mode setting must be seeded on install');
            $this->assertSame('auto', $row['value']);
        }

        /**
         * The sync must write to the install's configured table prefix, not a
         * hardcoded op_ prefix. Regression guard for the Database wrapper's
         * op_ -> custom_ SQL rewrite path.
         */
        public function testFinalizeSyncsRatesWithCustomTablePrefix(): void
        {
            $this->createCustomPrefixTables();

            $this->db->execute(
                "INSERT INTO custom_merchants (id, uuid, name, slug, email, status, settings)
                 VALUES (1, 'custom-prefix-uuid', 'Custom Merchant', 'custom-prefix-merchant', 'custom-prefix@example.com', 'active', '{}')"
            );
            $this->db->execute(
                "INSERT INTO custom_roles (merchant_id, name, slug, description, is_system, created_at)
                 VALUES (1, 'Owner', 'owner', 'fixture', 1, NOW())"
            );
            $this->db->execute(
                "INSERT INTO custom_merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at, updated_at)
                 VALUES (1, 1, 'Custom Admin', 'custom-prefix-admin', 'custom-prefix-admin@example.com', :hash, 1, 'active', NOW(), NOW())",
                ['hash' => password_hash('irrelevant-password', PASSWORD_ARGON2ID)]
            );
            foreach ([
                ['BDT', 'Bangladeshi Taka', '৳', 2],
                ['USD', 'US Dollar', '$', 2],
                ['EUR', 'Euro', '€', 2],
            ] as $currency) {
                $this->db->execute(
                    "INSERT INTO custom_currencies (code, name, symbol, decimal_places, status) VALUES (?,?,?,?,'active')",
                    $currency
                );
            }

            $this->setEnv('DB_NAME', 'op_no_such_database_xyz');
            $this->writeTempEnv('custom_');

            $mockJson = json_encode([
                'date' => '2026-09-11',
                'usd' => [
                    'usd' => 1.0,
                    'bdt' => 120.0,
                    'eur' => 0.94,
                ],
            ]);

            HttpClient::$mockResponses = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json' => [
                    'status'  => 200,
                    'body'    => $mockJson,
                    'headers' => [],
                ],
            ];

            $controller = $this->makeController();
            $response = $controller->finalize($this->postFinalize([
                'app_name' => 'Custom Prefix App',
                'currency' => 'USD',
                'timezone' => 'Asia/Dhaka',
            ]));

            $this->assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertTrue($body['success']);

            $rates = $this->db->fetchAll(
                "SELECT target_currency, rate FROM custom_exchange_rates WHERE base_currency = 'USD'"
            );
            $map = [];
            foreach ($rates as $r) {
                $map[(string) $r['target_currency']] = (string) $r['rate'];
            }
            $this->assertSame('1.00000000', $map['USD'] ?? null);
            $this->assertSame('120.00000000', $map['BDT'] ?? null, 'Custom-prefix installs must receive synced rates');
            $this->assertSame('0.94000000', $map['EUR'] ?? null);
        }
    }
}