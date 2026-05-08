<?php
declare(strict_types=1);

namespace OwnPay\Event;

use OwnPay\Service\System\Logger;

/**
 * Hook/Filter event engine â€” sole event API for Own Pay.
 *
 * Actions: fire-and-forget callbacks.
 * Filters: pass data through callbacks, each can modify and return it.
 *
 * Every callback is wrapped in try/catch â€” a broken plugin never crashes the system.
 */
final class EventManager
{
    /**
     * @var array<string, array<int, array{callable: callable, priority: int, owner: string}>>
     */
    private array $actions = [];

    /**
     * @var array<string, array<int, array{callable: callable, priority: int, owner: string}>>
     */
    private array $filters = [];

    /** @var array<string, int> Hook fire counters for debugging */
    private array $fireCounts = [];

    private ?Logger $logger = null;

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    // â”€â”€â”€ Actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Register an action callback.
     *
     * @param string   $hook     Hook name (e.g., 'payment.transaction.completed')
     * @param callable $callback The callback to fire
     * @param int      $priority Lower = earlier. Default 10.
     * @param string   $owner    Plugin slug or 'core'
     */
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        string $owner = 'core'
    ): void {
        $this->actions[$hook][] = [
            'callable' => $callback,
            'priority' => $priority,
            'owner'    => $owner,
        ];
        usort($this->actions[$hook], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    /**
     * Fire all callbacks registered for an action hook.
     *
     * @param string $hook Hook name
     * @param mixed  ...$args Arguments passed to each callback
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        $this->fireCounts[$hook] = ($this->fireCounts[$hook] ?? 0) + 1;

        if (empty($this->actions[$hook])) {
            return;
        }

        foreach ($this->actions[$hook] as $listener) {
            try {
                ($listener['callable'])(...$args);
            } catch (\Throwable $e) {
                $this->logHookError($hook, $listener['owner'], $e);
            }
        }
    }

    // â”€â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Register a filter callback.
     *
     * @param string   $hook     Filter name (e.g., 'payment.amount.calculate')
     * @param callable $callback fn($value, ...$args): mixed â€” must return modified value
     * @param int      $priority Lower = earlier. Default 10.
     * @param string   $owner    Plugin slug or 'core'
     */
    public function addFilter(
        string $hook,
        callable $callback,
        int $priority = 10,
        string $owner = 'core'
    ): void {
        $this->filters[$hook][] = [
            'callable' => $callback,
            'priority' => $priority,
            'owner'    => $owner,
        ];
        usort($this->filters[$hook], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    /**
     * Pass a value through all filter callbacks.
     *
     * @param string $hook  Filter name
     * @param mixed  $value The value to filter
     * @param mixed  ...$args Additional arguments
     * @return mixed The filtered value
     */
    public function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        $this->fireCounts[$hook] = ($this->fireCounts[$hook] ?? 0) + 1;

        if (empty($this->filters[$hook])) {
            return $value;
        }

        foreach ($this->filters[$hook] as $listener) {
            try {
                $value = ($listener['callable'])($value, ...$args);
            } catch (\Throwable $e) {
                $this->logHookError($hook, $listener['owner'], $e);
            }
        }

        return $value;
    }

    // â”€â”€â”€ Removal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Remove all action/filter callbacks for a given hook.
     */
    public function removeHook(string $hook): void
    {
        unset($this->actions[$hook], $this->filters[$hook]);
    }

    /**
     * Remove all callbacks owned by a specific plugin slug.
     */
    public function removeByOwner(string $owner): void
    {
        foreach ($this->actions as $hook => &$listeners) {
            $listeners = array_values(
                array_filter($listeners, static fn(array $l): bool => $l['owner'] !== $owner)
            );
            if (empty($listeners)) {
                unset($this->actions[$hook]);
            }
        }
        unset($listeners);

        foreach ($this->filters as $hook => &$listeners) {
            $listeners = array_values(
                array_filter($listeners, static fn(array $l): bool => $l['owner'] !== $owner)
            );
            if (empty($listeners)) {
                unset($this->filters[$hook]);
            }
        }
        unset($listeners);
    }

    // â”€â”€â”€ Introspection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Check if any callbacks are registered for a hook (action or filter).
     */
    public function hasHook(string $hook): bool
    {
        return !empty($this->actions[$hook]) || !empty($this->filters[$hook]);
    }

    /**
     * Get number of times a hook has been fired.
     */
    public function getFireCount(string $hook): int
    {
        return $this->fireCounts[$hook] ?? 0;
    }

    /**
     * Get all registered hooks with their listener counts.
     *
     * @return array<string, array{actions: int, filters: int}>
     */
    public function getRegisteredHooks(): array
    {
        $hooks = [];
        foreach (array_keys($this->actions) as $hook) {
            $hooks[$hook]['actions'] = count($this->actions[$hook]);
            $hooks[$hook]['filters'] = $hooks[$hook]['filters'] ?? 0;
        }
        foreach (array_keys($this->filters) as $hook) {
            $hooks[$hook]['filters'] = count($this->filters[$hook]);
            $hooks[$hook]['actions'] = $hooks[$hook]['actions'] ?? 0;
        }
        ksort($hooks);
        return $hooks;
    }

    // â”€â”€â”€ Error Handling â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function logHookError(string $hook, string $owner, \Throwable $e): void
    {
        $message = sprintf(
            '[OwnPay] Hook error in "%s" (owner: %s): %s in %s:%d',
            $hook,
            $owner,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        if ($this->logger !== null) {
            $this->logger->error($message);
        } else {
            error_log($message);
        }
    }
}
