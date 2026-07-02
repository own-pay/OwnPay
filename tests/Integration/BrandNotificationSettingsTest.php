<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Controller\Admin\SettingsController;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SettingsRepository;

/**
 * Verifies the brand-scoped "Email Notifications" settings save path:
 * non-blank fields persist as per-brand overrides, blank text + "Inherit" toggles clear the
 * override so the brand falls back to the All-Brands default.
 */
final class BrandNotificationSettingsTest extends IntegrationTestCase
{
    private Container $c;
    private SettingsController $controller;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $db = Database::getInstance();
        $this->c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->c);
        $this->c->instance(Database::class, $db);

        $controller = $this->c->get(SettingsController::class);
        $this->assertInstanceOf(SettingsController::class, $controller);
        $this->controller = $controller;

        $settings = $this->c->get(SettingsRepository::class);
        $this->assertInstanceOf(SettingsRepository::class, $settings);
        $this->settings = $settings;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['auth_user_id']     = 1;
        $_SESSION['auth_merchant_id'] = 1;
        $_SESSION['active_brand_id']  = 1;
        $_SESSION['brand_view_mode']  = 'single';
        $_SESSION['is_superadmin']    = true;

        $this->settings->deleteGroupScoped('general', 1);
        $this->settings->flushCache();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->settings->deleteGroupScoped('general', 1);
            $this->settings->flushCache();
        }
        unset($_SESSION['active_brand_id'], $_SESSION['brand_view_mode']);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $post
     */
    private function saveNotifications(array $post): Response
    {
        $post['_tab'] = 'notifications';
        return $this->controller->save(new Request([], $post));
    }

    public function testBrandOverridesPersistAndBlankToggleInherits(): void
    {
        $response = $this->saveNotifications([
            'mail_from_name'           => 'Brand Co',
            'mail_from_email'          => 'no-reply@brand.test',
            'admin_notification_email' => 'admin@brand.test',
            'email_on_payment'         => '1',
            'email_on_refund'          => '',
        ]);
        $this->assertSame(302, $response->getStatusCode());

        $this->settings->flushCache();
        $this->assertSame('Brand Co', $this->settings->getScopedOverride('general', 'mail_from_name', 1));
        $this->assertSame('no-reply@brand.test', $this->settings->getScopedOverride('general', 'mail_from_email', 1));
        $this->assertSame('admin@brand.test', $this->settings->getScopedOverride('general', 'admin_notification_email', 1));
        $this->assertSame('1', $this->settings->getScopedOverride('general', 'email_on_payment', 1));
        $this->assertNull(
            $this->settings->getScopedOverride('general', 'email_on_refund', 1),
            'A blank toggle must clear the override so the brand inherits the All-Brands default.'
        );
    }

    public function testBlankTextClearsExistingOverride(): void
    {
        $this->settings->setScoped('general', 'mail_from_name', 'Old Brand', 1);
        $this->settings->setScoped('general', 'email_on_payment', '1', 1);
        $this->settings->flushCache();

        $response = $this->saveNotifications([
            'mail_from_name'           => '',
            'mail_from_email'          => '',
            'admin_notification_email' => '',
            'email_on_payment'         => '',
            'email_on_refund'          => '0',
        ]);
        $this->assertSame(302, $response->getStatusCode());

        $this->settings->flushCache();
        $this->assertNull($this->settings->getScopedOverride('general', 'mail_from_name', 1));
        $this->assertNull($this->settings->getScopedOverride('general', 'email_on_payment', 1));
        $this->assertSame('0', $this->settings->getScopedOverride('general', 'email_on_refund', 1));
    }
}
