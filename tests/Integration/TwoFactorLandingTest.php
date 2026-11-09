<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\AuthController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Middleware\TwoFactorMiddleware;
use OwnPay\Repository\MerchantUserRepository;
use PHPUnit\Framework\Attributes\DataProvider;

final class TwoFactorLandingTest extends IntegrationTestCase
{
    /** @return array<string, array{string, bool, string}> */
    public static function landingCases(): array
    {
        return [
            'staff' => ['staff.view', false, '/admin/staff'],
            'dashboard staff' => ['dashboard.view', false, '/admin'],
            'superadmin without role permissions' => ['', true, '/admin'],
        ];
    }

    #[DataProvider('landingCases')]
    public function testSuccessfulTwoFactorUsesPermittedLanding(string $permission, bool $superadmin, string $expected): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $db = Database::getInstance();
        $db->beginTransaction();
        $previousSession = $_SESSION ?? [];
        try {
            $db->execute("INSERT INTO op_roles (merchant_id, name, slug) VALUES (1, '2FA landing', 'two-factor-landing')");
            $roleId = (int) $db->lastInsertId();
            if ($permission !== '') {
                $db->execute(
                    'INSERT INTO op_role_permissions (role_id, permission_id) SELECT :id, id FROM op_permissions WHERE slug = :permission',
                    ['id' => $roleId, 'permission' => $permission]
                );
            }
            $db->execute(
                "INSERT INTO op_merchant_users (merchant_id, role_id, name, email, password_hash, two_factor_enabled, is_superadmin) VALUES (1, :role, '2FA Staff', 'two-factor-landing@example.test', 'unused', 1, :superadmin)",
                ['role' => $roleId, 'superadmin' => (int) $superadmin]
            );
            $userId = (int) $db->lastInsertId();
            $container = new Container();
            $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
            $bootstrap($container);
            $container->instance(Database::class, $db);
            // Replay protection remains enabled in production; isolate these synthetic attempts.
            $cache = $this->createMock(\OwnPay\Cache\CacheInterface::class);
            $cache->expects(self::once())->method('add')->willReturn(true);
            $container->instance(\OwnPay\Cache\CacheInterface::class, $cache);
            $settings = $container->get(\OwnPay\Repository\SettingsRepository::class);
            self::assertInstanceOf(\OwnPay\Repository\SettingsRepository::class, $settings);
            $settings->set('system', 'onboarding_completed', '0');
            $users = $container->get(MerchantUserRepository::class);
            self::assertInstanceOf(MerchantUserRepository::class, $users);
            $secret = 'JBSWY3DPEHPK3PXP';
            $users->setTotpSecret($userId, $secret);
            $_SESSION = ['2fa_user_id' => $userId];
            $generate = new \ReflectionMethod(TwoFactorMiddleware::class, 'generateTotp');
            $code = $generate->invoke(null, $secret, (int) floor(time() / 30));
            $controller = $container->get(AuthController::class);
            self::assertInstanceOf(AuthController::class, $controller);

            $response = $controller->twoFactorVerify(new Request([], ['code' => $code], ['REQUEST_METHOD' => 'POST']));

            self::assertSame(302, $response->getStatusCode(), strip_tags($response->getBody()));
            self::assertSame($expected, $response->getHeaders()['Location'] ?? null);
            self::assertSame($userId, $_SESSION['auth_user_id']);
            self::assertTrue($_SESSION['2fa_verified']);
            self::assertArrayNotHasKey('2fa_user_id', $_SESSION);
        } finally {
            $db->rollBack();
            $_SESSION = $previousSession;
        }
    }
}
