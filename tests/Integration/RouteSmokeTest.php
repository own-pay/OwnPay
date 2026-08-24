<?php
declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Middleware\SecurityHeadersMiddleware;
use OwnPay\Security\CspNonce;

final class RouteSmokeTest extends IntegrationTestCase
{
    public function testSecurityHeadersMiddlewareWithFrozenContainer(): void
    {
        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);

        // Freeze container as Kernel does
        $container->freeze();
        $this->assertTrue($container->isFrozen());

        $middleware = new SecurityHeadersMiddleware($container);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/install', 'SERVER_NAME' => 'localhost']);

        $executed = false;
        $response = $middleware->handle($req, function (Request $r) use (&$executed, $container): Response {
            $executed = true;
            $this->assertNotEmpty($r->getAttribute('csp_nonce'));
            $cspNonce = $container->get(CspNonce::class);
            $this->assertInstanceOf(CspNonce::class, $cspNonce);
            $this->assertSame($r->getAttribute('csp_nonce'), $cspNonce->getNonce());
            return Response::html('<html><body>OK</body></html>', 200);
        });

        $this->assertTrue($executed);
        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertStringContainsString('nonce-', $headers['Content-Security-Policy']);
    }
}
