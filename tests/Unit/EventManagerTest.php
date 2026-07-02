<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Event\EventManager;

class EventManagerTest extends TestCase
{
    public function testActionFires(): void
    {
        $em = new EventManager();
        $called = false;
        $em->addAction('test.fire', function () use (&$called) { $called = true; });
        $em->doAction('test.fire');
        $this->assertTrue($called);
    }

    public function testFilterModifiesValue(): void
    {
        $em = new EventManager();
        $em->addFilter('price.format', function (string $val): string { return '$' . $val; });
        $result = $em->applyFilter('price.format', '100');
        $this->assertSame('$100', $result);
    }

    public function testPriorityOrdering(): void
    {
        $em = new EventManager();
        $order = [];
        $em->addAction('ordered', function () use (&$order) { $order[] = 'B'; }, 20);
        $em->addAction('ordered', function () use (&$order) { $order[] = 'A'; }, 10);
        $em->doAction('ordered');
        $this->assertSame(['A', 'B'], $order);
    }

    public function testMultipleFiltersChain(): void
    {
        $em = new EventManager();
        $em->addFilter('chain', function (int $v): int { return $v + 1; }, 10);
        $em->addFilter('chain', function (int $v): int { return $v * 2; }, 20);
        $result = $em->applyFilter('chain', 5);
        $this->assertSame(12, $result);
    }

    public function testRemoveHook(): void
    {
        $em = new EventManager();
        $em->addAction('removable', function () { throw new \RuntimeException('Should not fire'); });
        $em->removeHook('removable');
        $em->doAction('removable');
        $this->assertTrue(true);
    }

    public function testActionWithPayload(): void
    {
        $em = new EventManager();
        $received = null;
        $em->addAction('payload', function (array $data) use (&$received) { $received = $data; });
        $em->doAction('payload', ['key' => 'val']);
        $this->assertSame(['key' => 'val'], $received);
    }

    public function testNoListenersNoError(): void
    {
        $em = new EventManager();
        $em->doAction('nobody.listening');
        $result = $em->applyFilter('nobody.filtering', 'unchanged');
        $this->assertSame('unchanged', $result);
    }

    public function testHasHook(): void
    {
        $em = new EventManager();
        $this->assertFalse($em->hasHook('missing'));
        $em->addAction('present', function () {});
        $this->assertTrue($em->hasHook('present'));
    }
}
