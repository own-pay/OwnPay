<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginManifest;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\PluginRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


/**
 * Security: plugins without the declared hook permission must never receive the sensitive hook payloads.
 */
#[AllowMockObjectsWithoutExpectations]
final class EventManagerPermissionGatingTest extends TestCase
{
    private function makeHarness(array $manifests): array
    {
        $container = new Container();

        // PluginRepository is final, so stub the Database tier instead: findBySlug()
        // resolves to a `SELECT ... WHERE slug = :val` with a known result set.
        $db = $this->createMock(\OwnPay\Core\Database::class);
        $db->method('fetchOne')->willReturnCallback(
            static fn(string $sql, array $params = []): ?array => isset($params['val'], $manifests[$params['val']])
                ? ['slug' => $params['val'], 'status' => 'active', 'type' => 'addon']
                : null
        );
        $repo = new PluginRepository($db);
        $container->instance(\OwnPay\Service\Brand\BrandContext::class,
            new \OwnPay\Service\Brand\BrandContext($db));

        $registry = new PluginRegistry($repo);
        $ref = new \ReflectionClass($registry);
        $prop = $ref->getProperty('manifests');
        $prop->setAccessible(true);
        /** @var array<string, PluginManifest> $map */
        $map = [];
        foreach ($manifests as $slug => $manifest) {
            $map[$slug] = $manifest;
        }
        $prop->setValue($registry, $map);

        $container->instance(PluginRegistry::class, $registry);
        $events = new EventManager();
        $events->setContainer($container);

        return [$events, $registry];
    }

    /**
     * @param array<string, mixed> $manifestFields
     */
    private static function manifestFor(string $slug, array $manifestFields): PluginManifest
    {
        return PluginManifest::fromArray(array_merge([
            'name' => $slug,
            'slug' => $slug,
            'type' => 'addon',
            'entrypoint' => 'Plugin.php',
        ], $manifestFields), '/tmp/' . $slug);
    }

    public function testPluginWithReadSmsReceivesSmsHook(): void
    {
        [$events] = $this->makeHarness([
            'good-plugin' => self::manifestFor('good-plugin', ['permissions' => ['read_sms']]),
        ]);

        $called = false;
        $events->addAction('sms.received.before', static function () use (&$called): void {
            $called = true;
        }, 10, 'good-plugin');

        $events->doAction('sms.received.before', ['messages' => []]);
        $this->assertTrue($called);
    }

    public function testPluginWithoutReadSmsDoesNotReceiveSmsHook(): void
    {
        [$events] = $this->makeHarness([
            'evil-plugin' => self::manifestFor('evil-plugin', ['permissions' => ['read_ledger']]),
        ]);

        $called = false;
        $events->addAction('sms.received.before', static function () use (&$called): void {
            $called = true;
        }, 10, 'evil-plugin');

        $events->doAction('sms.received.before', ['messages' => [['body' => 'Card ending 1234 charged Tk 500']]]);
        $this->assertFalse($called, 'evil-plugin received a sensitive SMS hook without read_sms');
    }

    public function testPluginWithReadLedgerReceivesLedgerHook(): void
    {
        [$events] = $this->makeHarness([
            'ledger-plugin' => self::manifestFor('ledger-plugin', ['permissions' => ['read_ledger']]),
        ]);

        $called = false;
        $events->addAction('ledger.entry.created', static function () use (&$called): void {
            $called = true;
        }, 10, 'ledger-plugin');

        $events->doAction('ledger.entry.created', ['entry_id' => 1]);
        $this->assertTrue($called);
    }

    public function testPluginWithoutReadLedgerDoesNotReceiveLedgerHook(): void
    {
        [$events] = $this->makeHarness([
            'evil-ledger' => self::manifestFor('evil-ledger', ['permissions' => ['read_sms']]),
        ]);

        $called = false;
        $events->addAction('ledger.entry.created', static function () use (&$called): void {
            $called = true;
        }, 10, 'evil-ledger');

        $events->doAction('ledger.entry.created', ['entry_id' => 1]);
        $this->assertFalse($called, 'evil-ledger received a sensitive ledger hook without read_ledger');
    }

    public function testCoreOwnerAlwaysReceivesSensitiveHooks(): void
    {
        [$events] = $this->makeHarness([]);

        $called = false;
        $events->addAction('sms.received.before', static function () use (&$called): void {
            $called = true;
        }, 10, 'core');

        $events->doAction('sms.received.before', ['messages' => []]);
        $this->assertTrue($called);
    }

    public function testUnmatchedOwnerCannotReceiveSensitiveHooks(): void
    {
        [$events] = $this->makeHarness([]); // no manifests at all — unknown owner must fail closed

        $called = false;
        $events->addAction('sms.received.before', static function () use (&$called): void {
            $called = true;
        }, 10, 'ghost-plugin');

        $events->doAction('sms.received.before', ['messages' => []]);
        $this->assertFalse($called, 'ghost-plugin received SMS hook with no registered manifest');
    }
}
