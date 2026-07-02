<?php

declare(strict_types=1);

namespace Tests\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        $this->container->instance('config.app', [
            'debug' => false,
            'paths' => [
                'modules' => dirname(__DIR__, 2) . '/modules',
            ]
        ]);
    }

    public function testSecurityHeadersAreApplied(): void
    {
        $middleware = new SecurityHeadersMiddleware($this->container);
        $request = new Request([], [], ['HTTP_HOST' => 'ownpay.test']);

        $response = $middleware->handle($request, function (Request $req) {
            return new Response('OK', 200);
        });

        $headers = $response->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
        $this->assertSame('DENY', $headers['X-Frame-Options'] ?? null);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy'] ?? null);
        $this->assertStringContainsString('payment=()', $headers['Permissions-Policy'] ?? '');
    }

    public function testReportToHeaderAndCspReportingDirectives(): void
    {
        $middleware = new SecurityHeadersMiddleware($this->container);
        $request = new Request([], [], ['HTTP_HOST' => 'ownpay.test']);

        $response = $middleware->handle($request, function (Request $req) {
            return new Response('OK', 200);
        });

        $headers = $response->getHeaders();

        $this->assertArrayHasKey('Report-To', $headers);
        $reportTo = json_decode($headers['Report-To'], true);
        $this->assertSame('csp-endpoint', $reportTo['group'] ?? null);
        $this->assertSame('http://ownpay.test/csp-report-api', $reportTo['endpoints'][0]['url'] ?? null);

        $csp = $headers['Content-Security-Policy'] ?? '';
        $this->assertStringContainsString('report-uri /csp-report', $csp);
        $this->assertStringContainsString('report-to csp-endpoint', $csp);
    }

    public function testReportToHeaderOnHttps(): void
    {
        $middleware = new SecurityHeadersMiddleware($this->container);
        $request = new Request([], [], ['HTTP_HOST' => 'ownpay.test', 'HTTPS' => 'on']);

        $response = $middleware->handle($request, function (Request $req) {
            return new Response('OK', 200);
        });

        $headers = $response->getHeaders();

        $this->assertArrayHasKey('Report-To', $headers);
        $reportTo = json_decode($headers['Report-To'], true);
        $this->assertSame('https://ownpay.test/csp-report-api', $reportTo['endpoints'][0]['url'] ?? null);
        $this->assertStringContainsString('max-age=31536000', $headers['Strict-Transport-Security'] ?? '');
    }

    public function testCspReportOnlyHeaderInDebugMode(): void
    {
        $this->container->instance('config.app', [
            'debug' => true,
            'paths' => [
                'modules' => dirname(__DIR__, 2) . '/modules',
            ]
        ]);

        $middleware = new SecurityHeadersMiddleware($this->container);
        $request = new Request([], [], ['HTTP_HOST' => 'ownpay.test']);

        $response = $middleware->handle($request, function (Request $req) {
            return new Response('OK', 200);
        });

        $headers = $response->getHeaders();

        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);
        $this->assertArrayHasKey('Content-Security-Policy-Report-Only', $headers);
    }

    public function testCheckoutCspDirectives(): void
    {
        $middleware = new SecurityHeadersMiddleware($this->container);
        $request = new Request([], [], ['HTTP_HOST' => 'ownpay.test', 'REQUEST_URI' => '/checkout/intent/123']);

        $response = $middleware->handle($request, function (Request $req) {
            return new Response('OK', 200);
        });

        $headers = $response->getHeaders();
        $csp = $headers['Content-Security-Policy'] ?? '';

        $this->assertStringContainsString('script-src', $csp);
        $this->assertStringContainsString('style-src', $csp);
        $this->assertStringContainsString('frame-src', $csp);
        $this->assertStringContainsString('connect-src', $csp);
    }
}
