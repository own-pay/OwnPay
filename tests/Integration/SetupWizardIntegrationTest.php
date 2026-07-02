<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\PluginRepository;
use OwnPay\Controller\Admin\DashboardController;

final class SetupWizardIntegrationTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private SettingsRepository $settingsRepo;
    private PluginRepository $pluginRepo;
    private DashboardController $controller;

    private array $backedUpSettings = [];
    private ?array $backedUpMailPlugin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $boot = require dirname(__DIR__, 2) . '/config/services.php';
        $boot($this->container);

        $this->db = $this->container->get(Database::class);
        $this->settingsRepo = $this->container->get(SettingsRepository::class);
        $this->pluginRepo = $this->container->get(PluginRepository::class);
        $this->controller = $this->container->get(DashboardController::class);

        $keysToBackup = [
            ['general', 'app_name'],
            ['general', 'site_name'],
            ['branding', 'site_name'],
            ['general', 'site_tagline'],
            ['general', 'timezone'],
            ['general', 'default_timezone'],
            ['general', 'currency'],
            ['general', 'base_currency'],
            ['general', 'default_currency'],
            ['general', 'landing_page_enabled'],
            ['checkout', 'timer_seconds'],
            ['checkout', 'require_customer_phone'],
        ];

        foreach ($keysToBackup as $pair) {
            $group = $pair[0];
            $key = $pair[1];
            $val = $this->settingsRepo->get($group, $key);
            if ($val !== null) {
                $this->backedUpSettings[] = [
                    'group' => $group,
                    'key' => $key,
                    'value' => $val
                ];
            }
        }

        $this->backedUpMailPlugin = $this->pluginRepo->findBySlug('mail-gateway');

        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_name'] = 'Test Superadmin';
        $_SESSION['auth_email'] = 'admin@example.com';
        $_SESSION['is_superadmin'] = true;
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_system_settings WHERE group_name = 'plugin.mail-gateway'");

            $this->db->execute("DELETE FROM op_system_settings WHERE group_name IN ('general', 'branding', 'checkout')");
            foreach ($this->backedUpSettings as $setting) {
                $this->settingsRepo->set($setting['group'], $setting['key'], $setting['value']);
            }

            $this->db->execute("DELETE FROM op_plugins WHERE slug = 'mail-gateway'");
            if ($this->backedUpMailPlugin) {
                $this->pluginRepo->create([
                    'slug'       => $this->backedUpMailPlugin['slug'],
                    'name'       => $this->backedUpMailPlugin['name'],
                    'type'       => $this->backedUpMailPlugin['type'],
                    'version'    => $this->backedUpMailPlugin['version'],
                    'entrypoint' => $this->backedUpMailPlugin['entrypoint'] ?? 'Plugin.php',
                    'manifest'   => $this->backedUpMailPlugin['manifest'],
                    'status'     => $this->backedUpMailPlugin['status'],
                ]);
            }
        }

        unset($_SESSION['auth_user_id'], $_SESSION['auth_name'], $_SESSION['auth_email'], $_SESSION['is_superadmin']);

        parent::tearDown();
    }

    public function testSaveOnboardingSettings(): void
    {
        $postData = [
            'site_name'              => 'Super Sovereign Pay',
            'site_tagline'           => 'Enterprise Sovereign Engine',
            'currency'               => 'EUR',
            'timezone'               => 'Europe/Berlin',
            'timer_minutes'          => '15',
            'require_customer_phone' => '1',
            'landing_page_enabled'   => '0',
        ];

        $req = new Request([], $postData);
        $response = $this->controller->saveOnboardingSettings($req);
        $body = json_decode($response->getBody(), true);

        $this->assertTrue($body['success'], 'Should successfully save Step 1 settings');

        $this->assertSame('Super Sovereign Pay', $this->settingsRepo->get('general', 'app_name'));
        $this->assertSame('Super Sovereign Pay', $this->settingsRepo->get('general', 'site_name'));
        $this->assertSame('Super Sovereign Pay', $this->settingsRepo->get('branding', 'site_name'));
        $this->assertSame('Enterprise Sovereign Engine', $this->settingsRepo->get('general', 'site_tagline'));
        $this->assertSame('Europe/Berlin', $this->settingsRepo->get('general', 'timezone'));
        $this->assertSame('Europe/Berlin', $this->settingsRepo->get('general', 'default_timezone'));
        $this->assertSame('EUR', $this->settingsRepo->get('general', 'currency'));
        $this->assertSame('EUR', $this->settingsRepo->get('general', 'base_currency'));
        $this->assertSame('EUR', $this->settingsRepo->get('general', 'default_currency'));
        $this->assertSame('0', $this->settingsRepo->get('general', 'landing_page_enabled'));
        $this->assertSame('900', $this->settingsRepo->get('checkout', 'timer_seconds'));
        $this->assertSame('1', $this->settingsRepo->get('checkout', 'require_customer_phone'));
    }

    public function testSetupOnboardingMailSmtp(): void
    {
        $postData = [
            'provider'        => 'smtp',
            'from_email'      => 'noreply@sovereignpay.test',
            'from_name'       => 'Sovereign Gateway',
            'smtp_host'       => 'smtp.mailtrap.io',
            'smtp_port'       => '2525',
            'smtp_user'       => 'user123',
            'smtp_password'   => 'pass123',
            'smtp_encryption' => 'tls',
            'skip'            => '0',
        ];

        $req = new Request([], $postData);
        $response = $this->controller->setupOnboardingMail($req);
        $body = json_decode($response->getBody(), true);

        $this->assertTrue($body['success'], 'Should successfully setup SMTP configurations');

        $this->assertSame('smtp', $this->settingsRepo->get('plugin.mail-gateway', 'provider'));
        $this->assertSame('noreply@sovereignpay.test', $this->settingsRepo->get('plugin.mail-gateway', 'from_email'));
        $this->assertSame('Sovereign Gateway', $this->settingsRepo->get('plugin.mail-gateway', 'from_name'));
        $this->assertSame('1', $this->settingsRepo->get('plugin.mail-gateway', 'enabled'));
        $this->assertSame('smtp.mailtrap.io', $this->settingsRepo->get('plugin.mail-gateway', 'smtp_host'));
        $this->assertSame('2525', $this->settingsRepo->get('plugin.mail-gateway', 'smtp_port'));
        $this->assertSame('user123', $this->settingsRepo->get('plugin.mail-gateway', 'smtp_user'));
        $this->assertSame('pass123', $this->settingsRepo->get('plugin.mail-gateway', 'smtp_password'));
        $this->assertSame('tls', $this->settingsRepo->get('plugin.mail-gateway', 'smtp_encryption'));

        $plugin = $this->pluginRepo->findBySlug('mail-gateway');
        $this->assertNotNull($plugin, 'mail-gateway plugin should exist');
        $this->assertSame('active', $plugin['status'], 'mail-gateway plugin status should be active');
        $this->assertSame('Plugin.php', $plugin['entrypoint'], 'mail-gateway plugin entrypoint should be Plugin.php');
    }

    public function testSetupOnboardingMailSkip(): void
    {
        $plugin = $this->pluginRepo->findBySlug('mail-gateway');
        if ($plugin) {
            $this->pluginRepo->update((int) $plugin['id'], ['status' => 'inactive']);
        }

        $postData = [
            'skip' => '1',
        ];

        $req = new Request([], $postData);
        $response = $this->controller->setupOnboardingMail($req);
        $body = json_decode($response->getBody(), true);

        $this->assertTrue($body['success'], 'Should successfully skip SMTP configuration');
        $this->assertTrue($body['skipped'], 'Response should declare skipped status');

        $this->assertSame('0', $this->settingsRepo->get('plugin.mail-gateway', 'enabled'));

        $plugin = $this->pluginRepo->findBySlug('mail-gateway');
        if ($plugin) {
            $this->assertNotSame('active', $plugin['status'], 'plugin status should not be active when skipped');
        }
    }
}
