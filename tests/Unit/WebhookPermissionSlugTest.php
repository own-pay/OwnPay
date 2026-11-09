<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Middleware\PermissionMiddleware;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;
use PHPUnit\Framework\TestCase;

/**
 * Webhook CRUD is mounted under /admin/developer but must be gated by the
 * dedicated webhooks.* permission slugs, not by api_keys.*.
 *
 * @see https://github.com/own-pay/OwnPay/issues/616
 */
final class WebhookPermissionSlugTest extends TestCase
{
    private Container $container;
    private PermissionMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->container->instance(AdminSession::class, new AdminSession());

        $brandContext = new BrandContext($this->createMock(\OwnPay\Core\Database::class));
        $brandContext->setActiveBrandId(3);
        $this->container->instance(BrandContext::class, $brandContext);

        $this->middleware = new PermissionMiddleware($this->container);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function dispatch(string $path, string $method, array $permissions): int
    {
        $request = new Request([], [], [
            'REQUEST_URI'    => $path,
            'REQUEST_METHOD' => $method,
        ]);
        $request->setAttribute('merchant_id', 3);
        $request->setAttribute('auth_user_id', 7);
        $request->setAttribute('auth_user', [
            'id'            => 7,
            'merchant_id'   => 3,
            'role_id'       => 3,
            'is_superadmin' => 0,
        ]);
        $request->setAttribute('user_permissions', $permissions);

        return $this->middleware->handle(
            $request,
            static fn(Request $req): Response => Response::html('OK', 200)
        )->getStatusCode();
    }

    public static function webhookCrudRouteProvider(): array
    {
        return [
            'store'          => ['/admin/developer/webhooks/store', 'POST'],
            'toggle'         => ['/admin/developer/webhooks/12/toggle', 'POST'],
            'delete'         => ['/admin/developer/webhooks/12/delete', 'POST'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('webhookCrudRouteProvider')]
    public function testWebhookCrudIsAllowedWithWebhooksManage(string $path, string $method): void
    {
        $this->assertNotSame(
            403,
            $this->dispatch($path, $method, ['webhooks.view', 'webhooks.manage']),
            "{$method} {$path} must be reachable by a role holding webhooks.manage."
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('webhookCrudRouteProvider')]
    public function testWebhookCrudIsDeniedWithoutWebhooksManage(string $path, string $method): void
    {
        $this->assertSame(
            403,
            $this->dispatch($path, $method, ['webhooks.view']),
            "{$method} {$path} must be denied for a role holding webhooks.view only."
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('webhookCrudRouteProvider')]
    public function testApiKeyManageDoesNotGrantWebhookCrud(string $path, string $method): void
    {
        $this->assertSame(
            403,
            $this->dispatch($path, $method, ['api_keys.view', 'api_keys.manage']),
            "{$method} {$path} must not be authorized by api_keys.manage."
        );
    }

    public function testUnrelatedDeveloperRoutesStillUseApiKeyPermissions(): void
    {
        $this->assertSame(
            403,
            $this->dispatch('/admin/developer/rate-limits/reset', 'POST', ['webhooks.view', 'webhooks.manage']),
            '/admin/developer/rate-limits/reset must remain gated by api_keys.manage.'
        );
        $this->assertNotSame(
            403,
            $this->dispatch('/admin/developer/rate-limits/reset', 'POST', ['api_keys.view', 'api_keys.manage']),
            '/admin/developer/rate-limits/reset must stay reachable with api_keys.manage.'
        );
    }
}
