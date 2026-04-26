<?php

declare(strict_types=1);

namespace OwnPay\Event;

/**
 * Pure OOP, priority-based event bus for OwnPay.
 *
 * Provides two hook primitives:
 *   - **Actions** — fire-and-forget side-effects (logging, notifications, …)
 *   - **Filters** — transform a value through a pipeline of callbacks
 *
 * Every callback is tagged with an optional `$owner` (the plugin slug),
 * enabling bulk removal when a plugin is deactivated at runtime.
 *
 * Thread model: PHP is single-threaded per request, so no locking is needed.
 *
 * @example
 *   $em = EventManager::getInstance();
 *   $em->addAction('payment.completed', fn(array $txn) => sendSms($txn), owner: 'sms-plugin');
 *   $em->doAction('payment.completed', $transaction);
 *
 *   $em->addFilter('invoice.total', fn(string $total, array $inv) => money_add($total, '5'), owner: 'tax-plugin');
 *   $total = $em->applyFilters('invoice.total', $total, $invoice);
 */
final class EventManager
{
    /** @var array<string, list<array{callback: callable, priority: int, owner: ?string}>> */
    private array $actions = [];

    /** @var array<string, list<array{callback: callable, priority: int, owner: ?string}>> */
    private array $filters = [];

    /** @var array<string, bool>  Tracks which hook keys need re-sorting */
    private array $dirty = [];

    private static ?self $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Reset the singleton (for testing only).
     * @internal
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // ── Actions ─────────────────────────────────────────────────────

    /**
     * Register an action callback.
     *
     * @param string   $hook     Dot-separated hook name (e.g. "payment.completed")
     * @param callable $callback The handler to invoke
     * @param int      $priority Lower numbers execute first (default 10)
     * @param ?string  $owner    Plugin slug — enables bulk removal on deactivation
     */
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        ?string $owner = null,
    ): void {
        $this->actions[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'owner'    => $owner,
        ];
        $this->dirty['action:' . $hook] = true;
    }

    /**
     * Fire an action hook — all registered callbacks run sequentially.
     *
     * Exceptions in individual callbacks are caught and logged so that
     * one misbehaving plugin cannot crash the entire request.
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        if (!isset($this->actions[$hook])) {
            return;
        }

        $this->sortIfDirty('action', $hook);

        foreach ($this->actions[$hook] as $entry) {
            try {
                ($entry['callback'])(...$args);
            } catch (\Throwable $e) {
                $owner = $entry['owner'] ?? 'core';
                error_log(
                    "[OwnPay][EventManager] Action '{$hook}' callback from '{$owner}' threw: "
                    . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
            }
        }
    }

    /**
     * Check whether any callbacks are registered for an action hook.
     */
    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    /**
     * Remove a specific action callback (identity comparison).
     */
    public function removeAction(string $hook, callable $callback): bool
    {
        return $this->removeHook($this->actions, $hook, $callback);
    }

    // ── Filters ─────────────────────────────────────────────────────

    /**
     * Register a filter callback.
     *
     * @param string   $hook     Dot-separated hook name (e.g. "invoice.total")
     * @param callable $callback Receives ($value, ...$args), must return the (possibly modified) $value
     * @param int      $priority Lower numbers execute first (default 10)
     * @param ?string  $owner    Plugin slug
     */
    public function addFilter(
        string $hook,
        callable $callback,
        int $priority = 10,
        ?string $owner = null,
    ): void {
        $this->filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'owner'    => $owner,
        ];
        $this->dirty['filter:' . $hook] = true;
    }

    /**
     * Run a value through all registered filter callbacks and return the result.
     *
     * Each callback receives the current $value as its first argument,
     * followed by any extra $args.  It MUST return the (possibly modified) value.
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$hook])) {
            return $value;
        }

        $this->sortIfDirty('filter', $hook);

        foreach ($this->filters[$hook] as $entry) {
            try {
                $value = ($entry['callback'])($value, ...$args);
            } catch (\Throwable $e) {
                $owner = $entry['owner'] ?? 'core';
                error_log(
                    "[OwnPay][EventManager] Filter '{$hook}' callback from '{$owner}' threw: "
                    . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
                // On filter exception, pass through the unmodified value
            }
        }

        return $value;
    }

    /**
     * Check whether any callbacks are registered for a filter hook.
     */
    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    /**
     * Remove a specific filter callback (identity comparison).
     */
    public function removeFilter(string $hook, callable $callback): bool
    {
        return $this->removeHook($this->filters, $hook, $callback);
    }

    // ── Bulk operations ─────────────────────────────────────────────

    /**
     * Remove ALL hooks (actions + filters) registered by a specific plugin.
     *
     * Called by PluginLoader when a plugin is deactivated at runtime.
     *
     * @return int Number of hooks removed
     */
    public function removeAllByOwner(string $owner): int
    {
        $count = 0;
        $count += $this->removeOwnerFromStore($this->actions, $owner);
        $count += $this->removeOwnerFromStore($this->filters, $owner);
        return $count;
    }

    // ── Introspection ───────────────────────────────────────────────

    /**
     * Return a debug snapshot of all registered hooks.
     *
     * @return array{actions: array<string, int>, filters: array<string, int>}
     *         Hook name => callback count
     */
    public function getRegistered(): array
    {
        $summarize = function (array $store): array {
            $out = [];
            foreach ($store as $hook => $entries) {
                $out[$hook] = count($entries);
            }
            ksort($out);
            return $out;
        };

        return [
            'actions' => $summarize($this->actions),
            'filters' => $summarize($this->filters),
        ];
    }

    /**
     * Return detailed info for a specific hook (for debugging).
     *
     * @return list<array{priority: int, owner: ?string, type: string}>
     */
    public function inspectHook(string $hook): array
    {
        $result = [];

        foreach ($this->actions[$hook] ?? [] as $entry) {
            $result[] = [
                'priority' => $entry['priority'],
                'owner'    => $entry['owner'],
                'type'     => 'action',
            ];
        }

        foreach ($this->filters[$hook] ?? [] as $entry) {
            $result[] = [
                'priority' => $entry['priority'],
                'owner'    => $entry['owner'],
                'type'     => 'filter',
            ];
        }

        usort($result, fn(array $a, array $b) => $a['priority'] <=> $b['priority']);

        return $result;
    }

    // ── Internals ───────────────────────────────────────────────────

    /**
     * Sort a hook's callback list by priority (ascending) if it was marked dirty.
     */
    private function sortIfDirty(string $type, string $hook): void
    {
        $key = $type . ':' . $hook;
        if (!isset($this->dirty[$key])) {
            return;
        }

        $store = $type === 'action' ? 'actions' : 'filters';
        usort(
            $this->{$store}[$hook],
            fn(array $a, array $b) => $a['priority'] <=> $b['priority'],
        );
        unset($this->dirty[$key]);
    }

    /**
     * Remove a specific callback from a hook store by identity comparison.
     */
    private function removeHook(array &$store, string $hook, callable $callback): bool
    {
        if (!isset($store[$hook])) {
            return false;
        }

        $removed = false;
        $store[$hook] = array_values(
            array_filter($store[$hook], function (array $entry) use ($callback, &$removed): bool {
                if ($entry['callback'] === $callback) {
                    $removed = true;
                    return false; // exclude
                }
                return true; // keep
            })
        );

        if (empty($store[$hook])) {
            unset($store[$hook]);
        }

        return $removed;
    }

    /**
     * Remove all entries owned by a specific plugin from a hook store.
     */
    private function removeOwnerFromStore(array &$store, string $owner): int
    {
        $count = 0;

        foreach ($store as $hook => &$entries) {
            $before = count($entries);
            $entries = array_values(
                array_filter($entries, fn(array $e): bool => $e['owner'] !== $owner)
            );
            $count += $before - count($entries);

            if (empty($entries)) {
                unset($store[$hook]);
            }
        }
        unset($entries);

        return $count;
    }
}
