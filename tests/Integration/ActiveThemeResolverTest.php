<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\View\Theme\ActiveThemeResolver;

final class ActiveThemeResolverTest extends IntegrationTestCase
{
    private Database $db;
    private SettingsRepository $settings;
    private PluginRepository $pluginRepo;
    private MerchantRepository $merchantRepo;
    private ActiveThemeResolver $resolver;
    private int $testMerchantId = 0;

    /**
     * Snapshot of the `own-pay` plugin row's original status, so the test can
     * force it 'active' (the resolver's fallback slug must resolve to a real,
     * active plugin row to be usable) without permanently mutating shared
     * environment state. Null means no row existed and we must remove the one
     * we inserted.
     */
    private ?string $originalOwnPayStatus = null;
    private bool $ownPayRowCreatedByTest = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
        $this->db = Database::getInstance();
        $this->settings = new SettingsRepository($this->db);
        $this->pluginRepo = new PluginRepository($this->db);
        $this->merchantRepo = new MerchantRepository($this->db);
        $registry = new PluginRegistry($this->pluginRepo);
        $this->resolver = new ActiveThemeResolver(
            $this->settings,
            $registry,
            dirname(__DIR__, 2) . '/modules/themes',
            'own-pay'
        );
        $this->cleanup();
        $this->ensureOwnPayThemeActive();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->restoreOwnPayThemeStatus();
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_system_settings WHERE group_name = 'appearance' AND key_name = 'active_theme' AND merchant_id IS NOT NULL AND merchant_id >= 900000");
        if ($this->testMerchantId > 0) {
            $this->db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $this->testMerchantId]);
            $this->testMerchantId = 0;
        }
    }

    /**
     * The bundled 'own-pay' theme must have an active op_plugins row for the
     * resolver's fallback path to be considered "usable". The shared test
     * database may not have this seeded (or may have it inactive), so force
     * it active for the duration of this test and restore afterward.
     */
    private function ensureOwnPayThemeActive(): void
    {
        $existing = $this->pluginRepo->findBySlug('own-pay');
        if ($existing === null) {
            $this->pluginRepo->create([
                'slug'       => 'own-pay',
                'name'       => 'Own Pay',
                'type'       => 'theme',
                'version'    => '1.0.0',
                'entrypoint' => 'theme.php',
                'status'     => 'active',
            ]);
            $this->ownPayRowCreatedByTest = true;
            return;
        }

        $this->originalOwnPayStatus = is_string($existing['status'] ?? null) ? $existing['status'] : null;
        if ($this->originalOwnPayStatus !== 'active') {
            $this->pluginRepo->update((int) $existing['id'], ['status' => 'active']);
        }
    }

    private function restoreOwnPayThemeStatus(): void
    {
        if ($this->ownPayRowCreatedByTest) {
            $this->db->execute("DELETE FROM op_plugins WHERE slug = 'own-pay'");
            $this->ownPayRowCreatedByTest = false;
            return;
        }

        if ($this->originalOwnPayStatus !== null && $this->originalOwnPayStatus !== 'active') {
            $existing = $this->pluginRepo->findBySlug('own-pay');
            if ($existing !== null) {
                $this->pluginRepo->update((int) $existing['id'], ['status' => $this->originalOwnPayStatus]);
            }
        }
    }

    private function createTestMerchant(): int
    {
        $merchantId = (int) $this->merchantRepo->createMerchant([
            'name'             => 'Theme Resolver Test Brand',
            'slug'             => 'theme-resolver-test-brand-' . bin2hex(random_bytes(4)),
            'email'            => 'theme-resolver-test@example.com',
            'phone'            => '01700000000',
            'timezone'         => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'status'           => 'active',
        ]);
        $this->testMerchantId = $merchantId;
        return $merchantId;
    }

    public function testGlobalResolvesToBundledThemeWhenNoOverride(): void
    {
        $theme = $this->resolver->resolve(null);
        $this->assertNotSame('', $theme->slug);
        $this->assertFalse($theme->fellBack);
    }

    public function testDeactivatedBrandThemeFallsBackWithFlag(): void
    {
        // Brand picks a slug that has no active plugin row.
        $merchantId = $this->createTestMerchant();
        $this->settings->setScoped('appearance', 'active_theme', 'zzdefinitely-not-installed', $merchantId);
        $theme = $this->resolver->resolve($merchantId);
        $this->assertTrue($theme->fellBack);
        $this->assertNotSame('zzdefinitely-not-installed', $theme->slug);
    }
}
