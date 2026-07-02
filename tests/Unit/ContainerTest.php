<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Container;

class ContainerTest extends TestCase
{
    public function testInstanceAndGet(): void
    {
        $c = new Container();
        $c->instance('foo', 'bar');
        $this->assertSame('bar', $c->get('foo'));
    }

    public function testSingletonFactory(): void
    {
        $c = new Container();
        $c->singleton('counter', function () { return new \stdClass(); });
        $a = $c->get('counter');
        $b = $c->get('counter');
        $this->assertSame($a, $b);
    }

    public function testBindCreatesNewInstances(): void
    {
        $c = new Container();
        $c->bind('maker', function () { return new \stdClass(); });
        $a = $c->get('maker');
        $b = $c->get('maker');
        $this->assertNotSame($a, $b);
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $c = new Container();
        $c->instance('x', 42);
        $this->assertTrue($c->has('x'));
        $this->assertFalse($c->has('missing'));
    }

    public function testGetThrowsOnMissing(): void
    {
        $c = new Container();
        $this->expectException(\RuntimeException::class);
        $c->get('nonexistent');
    }

    public function testAliasResolution(): void
    {
        $c = new Container();
        $c->instance('real', 'value');
        $c->alias('shortcut', 'real');
        $this->assertSame('value', $c->get('shortcut'));
    }

    public function testParameterStorage(): void
    {
        $c = new Container();
        $c->parameter('db.host', 'localhost');
        $this->assertSame('localhost', $c->param('db.host'));
    }

    public function testForgetRemovesBinding(): void
    {
        $c = new Container();
        $c->instance('temp', 'val');
        $c->forget('temp');
        $this->assertFalse($c->has('temp'));
    }
}
