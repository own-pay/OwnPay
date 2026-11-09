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
        $attempt = $this->beginTwoFactorAttempt($permission, $superadmin);
        try {
            $response = $attempt['controller']->twoFactorVerify($this->verifyRequest($attempt['code']));

            self::assertSame(302, $response->getStatusCode(), strip_tags($response->getBody()));
            self::assertSame($expected, $response->getHeaders()['Location'] ?? null);
            self::assertSame($attempt['user_id'], $_SESSION['auth_user_id']);
            self::assertTrue($_SESSION['2fa_verified']);
            self::assertArrayNotHasKey('2fa_user_id', $_SESSION);
        } finally {
            $this->endTwoFactorAttempt($attempt);
        }
    }

    public function testStaffWithoutLandingPermissionIsLoggedOut(): void
    {
        $attempt = $this->beginTwoFactorAttempt('', false);
        try {
            $response = $attempt['controller']->twoFactorVerify($this->verifyRequest($attempt['code']));

            self::assertSame(200, $response->getStatusCode(), strip_tags($response->getBody()));
            self::assertStringContainsString('no active admin permissions', $response->getBody());
            self::assertArrayNotHasKey('auth_user_id', $_SESSION);
            self::assertArrayNotHasKey('2fa_verified', $_SESSION);
            self::assertArrayNotHasKey('2fa_user_id', $_SESSION);
        } finally {
            $this->endTwoFactorAttempt($attempt);
        }
    }

    private function verifyRequest(string $code): Request
    {
        return new Request([], ['code' => $code], ['REQUEST_METHOD' => 'POST']);
    }

    /**
     * Creates a synthetic two-factor user and the pending 2FA session for it,
     * all inside a transaction that endTwoFactorAttempt() rolls back.
     *
     * @return array{controller: AuthController, code: string, user_id: int, db: Database, previous_session: array<string, mixed>}
     */
    private function beginTwoFactorAttempt(string $permission, bool $superadmin): array
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
            $code = (string) $generate->invoke(null, $secret, (int) floor(time() / 30));
            $controller = $container->get(AuthController::class);
            self::assertInstanceOf(AuthController::class, $controller);

            return [
                'controller'      => $controller,
                'code'            => $code,
                'user_id'         => $userId,
                'db'              => $db,
                'previous_session' => $previousSession,
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            $_SESSION = $previousSession;
            throw $e;
        }
    }

    /**
     * @param array{controller: AuthController, code: string, user_id: int, db: Database, previous_session: array<string, mixed>} $attempt
     */
    private function endTwoFactorAttempt(array $attempt): void
    {
        $attempt['db']->rollBack();
        $_SESSION = $attempt['previous_session'];
    }
}
