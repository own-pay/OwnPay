<?php

declare(strict_types=1);

namespace Tests\Event;

use OwnPay\Event\EventManager;
use PHPUnit\Framework\TestCase;

class EventManagerTest extends TestCase
{
    protected function setUp(): void
    {
        EventManager::resetInstance();
    }

    protected function tearDown(): void
    {
        EventManager::resetInstance();
    }

    // â”€â”€ Singleton â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testGetInstanceReturnsSameObject(): void
    {
        $a = EventManager::getInstance();
        $b = EventManager::getInstance();
        $this->assertSame($a, $b);
    }

    public function testResetInstanceCreatesNewSingleton(): void
    {
        $a = EventManager::getInstance();
        EventManager::resetInstance();
        $b = EventManager::getInstance();
        $this->assertNotSame($a, $b);
    }

    // â”€â”€ Actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testDoActionInvokesRegisteredCallback(): void
    {
        $em = EventManager::getInstance();
        $captured = null;
        $em->addAction('test.event', function (string $arg) use (&$captured): void {
            $captured = $arg;
        });
        $em->doAction('test.event', 'hello');
        $this->assertSame('hello', $captured);
    }

    public function testDoActionWithUnregisteredHookIsNoOp(): void
    {
        $em = EventManager::getInstance();
        // Should not throw
        $em->doAction('never.registered');
        $this->assertFalse($em->hasAction('never.registered'));
    }

    public function testDoActionRunsCallbacksInPriorityOrder(): void
    {
        $em = EventManager::getInstance();
        $log = [];
        $em->addAction('priority.test', function () use (&$log): void {
            $log[] = 'medium';
        }, 10);
        $em->addAction('priority.test', function () use (&$log): void {
            $log[] = 'first';
        }, 1);
        $em->addAction('priority.test', function () use (&$log): void {
            $log[] = 'last';
        }, 100);
        $em->doAction('priority.test');
        $this->assertSame(['first', 'medium', 'last'], $log);
    }

    public function testDoActionContinuesAfterCallbackException(): void
    {
        $em = EventManager::getInstance();
        $log = [];
        $em->addAction('exception.test', function () use (&$log): void {
            $log[] = 'before';
            throw new \RuntimeException('boom');
        });
        $em->addAction('exception.test', function () use (&$log): void {
            $log[] = 'after';
        });

        $errorLog = ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'em-test'));
        try {
            $em->doAction('exception.test');
        } finally {
            ini_set('error_log', $errorLog ?: '');
        }

        $this->assertSame(['before', 'after'], $log);
    }

    public function testRemoveActionRemovesSpecificCallback(): void
    {
        $em = EventManager::getInstance();
        $log = [];
        $cb1 = function () use (&$log): void {
            $log[] = 'a';
        };
        $cb2 = function () use (&$log): void {
            $log[] = 'b';
        };
        $em->addAction('removal.test', $cb1);
        $em->addAction('removal.test', $cb2);

        $this->assertTrue($em->removeAction('removal.test', $cb1));
        $em->doAction('removal.test');
        $this->assertSame(['b'], $log);
    }

    public function testRemoveActionReturnsFalseForUnknownHook(): void
    {
        $em = EventManager::getInstance();
        $cb = fn() => null;
        $this->assertFalse($em->removeAction('unknown', $cb));
    }

    public function testHasActionReturnsTrueWhenRegistered(): void
    {
        $em = EventManager::getInstance();
        $em->addAction('exists.test', fn() => null);
        $this->assertTrue($em->hasAction('exists.test'));
        $this->assertFalse($em->hasAction('not.exists'));
    }

    // â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testApplyFiltersReturnsValueUnchangedWhenNoCallbacks(): void
    {
        $em = EventManager::getInstance();
        $this->assertSame('original', $em->applyFilters('no.filter', 'original'));
    }

    public function testApplyFiltersTransformsValueThroughCallbacks(): void
    {
        $em = EventManager::getInstance();
        $em->addFilter('transform.test', fn(string $v) => $v . '-A');
        $em->addFilter('transform.test', fn(string $v) => $v . '-B');
        $this->assertSame('start-A-B', $em->applyFilters('transform.test', 'start'));
    }

    public function testApplyFiltersRespectsPriority(): void
    {
        $em = EventManager::getInstance();
        $em->addFilter('order.test', fn(string $v) => $v . 'M', 10);
        $em->addFilter('order.test', fn(string $v) => $v . 'F', 1);
        $em->addFilter('order.test', fn(string $v) => $v . 'L', 100);
        $this->assertSame('xFML', $em->applyFilters('order.test', 'x'));
    }

    public function testApplyFiltersPassesExtraArgsToEachCallback(): void
    {
        $em = EventManager::getInstance();
        $em->addFilter('args.test', fn(int $value, int $multiplier) => $value * $multiplier);
        $this->assertSame(20, $em->applyFilters('args.test', 5, 4));
    }

    public function testApplyFiltersPassesUnchangedValueOnException(): void
    {
        $em = EventManager::getInstance();
        $em->addFilter('exc.test', fn(string $v) => $v . '-good');
        $em->addFilter('exc.test', function (string $v): string {
            throw new \RuntimeException('bad');
        });
        $em->addFilter('exc.test', fn(string $v) => $v . '-also-good');

        $errorLog = ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'em-test'));
        try {
            $result = $em->applyFilters('exc.test', 'x');
        } finally {
            ini_set('error_log', $errorLog ?: '');
        }

        // The exception filter is skipped â€” original value flows through unchanged
        $this->assertSame('x-good-also-good', $result);
    }

    public function testHasFilterReflectsRegistration(): void
    {
        $em = EventManager::getInstance();
        $this->assertFalse($em->hasFilter('foo'));
        $em->addFilter('foo', fn($v) => $v);
        $this->assertTrue($em->hasFilter('foo'));
    }

    // â”€â”€ Owner-based bulk removal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testRemoveAllByOwnerRemovesActionsAndFilters(): void
    {
        $em = EventManager::getInstance();
        $em->addAction('hookA', fn() => null, owner: 'plugin-x');
        $em->addAction('hookB', fn() => null, owner: 'plugin-x');
        $em->addAction('hookC', fn() => null, owner: 'plugin-y');
        $em->addFilter('filterA', fn($v) => $v, owner: 'plugin-x');
        $em->addFilter('filterB', fn($v) => $v, owner: 'plugin-y');

        $removed = $em->removeAllByOwner('plugin-x');

        $this->assertSame(3, $removed);
        $this->assertFalse($em->hasAction('hookA'));
        $this->assertFalse($em->hasAction('hookB'));
        $this->assertTrue($em->hasAction('hookC'));
        $this->assertFalse($em->hasFilter('filterA'));
        $this->assertTrue($em->hasFilter('filterB'));
    }

    public function testRemoveAllByOwnerWithUnknownOwnerReturnsZero(): void
    {
        $em = EventManager::getInstance();
        $em->addAction('hookA', fn() => null, owner: 'plugin-x');
        $this->assertSame(0, $em->removeAllByOwner('plugin-z'));
    }

    // â”€â”€ Introspection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testGetRegisteredReturnsHookCounts(): void
    {
        $em = EventManager::getInstance();
        $em->addAction('a.one', fn() => null);
        $em->addAction('a.two', fn() => null);
        $em->addAction('a.two', fn() => null);
        $em->addFilter('f.one', fn($v) => $v);

        $reg = $em->getRegistered();

        $this->assertSame(['a.one' => 1, 'a.two' => 2], $reg['actions']);
        $this->assertSame(['f.one' => 1], $reg['filters']);
    }

    public function testInspectHookReturnsSortedDetails(): void
    {
        $em = EventManager::getInstance();
        $em->addAction('inspect', fn() => null, priority: 50, owner: 'a');
        $em->addAction('inspect', fn() => null, priority: 10, owner: 'b');
        $em->addFilter('inspect', fn($v) => $v, priority: 30, owner: 'c');

        $details = $em->inspectHook('inspect');

        $this->assertCount(3, $details);
        $this->assertSame(10, $details[0]['priority']);
        $this->assertSame('b', $details[0]['owner']);
        $this->assertSame(30, $details[1]['priority']);
        $this->assertSame(50, $details[2]['priority']);
    }
}

