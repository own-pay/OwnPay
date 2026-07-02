<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Router;

class RouterTest extends TestCase
{
    public function testStaticRouteRegex(): void
    {
        $pattern = '/health';
        $regex = '#^' . $pattern . '$#';
        $this->assertSame(1, preg_match($regex, '/health'));
        $this->assertSame(0, preg_match($regex, '/health/extra'));
    }

    public function testParameterizedRouteRegex(): void
    {
        $pattern = '/api/v1/payments/{id}';
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([a-zA-Z0-9_\-]+)';
        }, $pattern);
        $regex = '#^' . $regex . '$#';

        $this->assertSame(['id'], $paramNames);
        $this->assertSame(1, preg_match($regex, '/api/v1/payments/42', $matches));
        $this->assertSame('42', $matches[1]);
    }

    public function testMultipleParams(): void
    {
        $pattern = '/api/v1/merchants/{mid}/transactions/{tid}';
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([a-zA-Z0-9_\-]+)';
        }, $pattern);
        $regex = '#^' . $regex . '$#';

        $this->assertSame(['mid', 'tid'], $paramNames);
        preg_match($regex, '/api/v1/merchants/abc/transactions/xyz', $matches);
        $this->assertSame('abc', $matches[1]);
        $this->assertSame('xyz', $matches[2]);
    }

    public function testNoMatchReturnsZero(): void
    {
        $regex = '#^/foo$#';
        $this->assertSame(0, preg_match($regex, '/bar'));
    }

    public function testTrailingSlashNormalization(): void
    {
        $path = '/api/v1/payments/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $this->assertSame('/api/v1/payments', $path);
    }

    public function testHandlerFormatValidation(): void
    {
        $handler = 'PaymentController@show';
        $this->assertTrue(str_contains($handler, '@'));

        [$ctrl, $method] = explode('@', $handler, 2);
        $this->assertSame('PaymentController', $ctrl);
        $this->assertSame('show', $method);
    }

    public function testIdentifierParameterAllowsEmailAndPhone(): void
    {
        $container = new Container();
        $router = new Router($container);
        $router->get('/api/v1/customers/{identifier}', 'Api\\CustomerController@show');

        $requestEmail = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/customers/john.doe@example.com'
        ]);
        $matchEmail = $router->match($requestEmail);
        $this->assertNotNull($matchEmail);
        $this->assertSame('john.doe@example.com', $matchEmail['params']['identifier'] ?? null);

        $requestPhone = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/customers/+8801700000000'
        ]);
        $matchPhone = $router->match($requestPhone);
        $this->assertNotNull($matchPhone);
        $this->assertSame('+8801700000000', $matchPhone['params']['identifier'] ?? null);

        $requestEncodedEmail = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/customers/john.doe%40example.com'
        ]);
        $matchEncodedEmail = $router->match($requestEncodedEmail);
        $this->assertNotNull($matchEncodedEmail);
        $this->assertSame('john.doe%40example.com', $matchEncodedEmail['params']['identifier'] ?? null);
    }
}
