<?php
declare(strict_types=1);

namespace OwnPay\Event;

use OwnPay\Service\System\Logger;

/**
 * Class EventManager
 *
 * The sole event dispatching and processing engine for the OwnPay platform.
 * Provides action (fire-and-forget) and filter (pipeline mutation) hooks allowing addons, custom themes,
 * and gateway integrations to extend core checkout, billing, and notification features.
 * Enforces strict error isolation by capturing and logging hook exceptions individually, preventing
 * third-party plugin failures from crashing critical transaction flows or double-entry ledger bookkeeping tasks.
 *
 * @package OwnPay\Event
 */
final class EventManager
{
    /**
     * @var \OwnPay\Container|null The dependency injection container.
     */
    private ?\OwnPay\Container $container = null;

    /**
     * @var bool Re-entrancy guard to avoid recursive loops when resolving brand plugin status.
     */
    private bool $resolvingOwnerActive = false;

    /**
     * Registered actions mapped by event hook name.
     *
     * @var array<string, array<int, array{callable: callable, priority: int, owner: string}>>
     */
    private array $actions = [];

    /**
     * Registered filters mapped by event hook name.
     *
     * @var array<string, array<int, array{callable: callable, priority: int, owner: string}>>
     */
    private array $filters = [];

    /**
     * Counter tracker indicating how many times each hook has been fired.
     *
     * @var array<string, int>
     */
    private array $fireCounts = [];

    /**
     * Stack tracking the currently active plugin owner execution context.
     *
     * @var array<int, string>
     */
    private array $ownerStack = [];

    /**
     * The system logging service instance.
     *
     * @var \OwnPay\Service\System\Logger|null
     */
    private ?Logger $logger = null;

    /**
     * The singleton instance for non-dependency injected execution (e.g. cron triggers).
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Retrieve the singleton instance.
     *
     * @return self The active event manager instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Assign the singleton instance.
     *
     * Typically executed by the dependency injection container bootstrap phase.
     *
     * @param self $instance The event manager instance to bind.
     * @return void
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Reset the static singleton instance.
     *
     * Primary usage resides within unit test suite teardown cycles.
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Set the dependency injection container.
     *
     * @param \OwnPay\Container $container The container.
     * @return void
     */
    public function setContainer(\OwnPay\Container $container): void
    {
        $this->container = $container;
    }

    /**
     * Push an owner to the stack.
     *
     * @param string $owner Slug identifier of the owner.
     * @return void
     */
    public function pushOwner(string $owner): void
    {
        $this->ownerStack[] = $owner;
    }

    /**
     * Pop an owner from the stack.
     *
     * @return void
     */
    public function popOwner(): void
    {
        array_pop($this->ownerStack);
    }

    /**
     * Resolve the current execution context owner.
     *
     * @return string Returns 'core' or the active plugin slug identifier.
     */
    public function getActiveOwner(): string
    {
        return empty($this->ownerStack) ? 'core' : end($this->ownerStack);
    }

    /**
     * Set the system logger.
     *
     * @param \OwnPay\Service\System\Logger $logger The logging engine instance.
     * @return void
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Checks if the owner (plugin) is active for the current brand context.
     *
     * @param string $owner Slug identifier of the plugin owner or 'core'.
     * @return bool True if active, false otherwise.
     */
    private function isOwnerActive(string $owner): bool
    {
        if ($owner === 'core') {
            return true;
        }

        if ($this->container === null) {
            return true;
        }

        if ($this->resolvingOwnerActive) {
            return true;
        }

        $this->resolvingOwnerActive = true;
        try {
            /** @var \OwnPay\Service\Brand\BrandContext $brandContext */
            $brandContext = $this->container->get(\OwnPay\Service\Brand\BrandContext::class);
            /** @var \OwnPay\Plugin\PluginRegistry $pluginRegistry */
            $pluginRegistry = $this->container->get(\OwnPay\Plugin\PluginRegistry::class);

            $brandId = $brandContext->getActiveBrandId();
            return $pluginRegistry->isPluginActive($owner, $brandId);
        } catch (\Throwable) {
            return true;
        } finally {
            $this->resolvingOwnerActive = false;
        }
    }

    /**
     * Register an action callback.
     *
     * Actions are fire-and-forget triggers that execute callbacks sequentially when matching events fire.
     *
     * @param string $hook The unique event hook namespace (e.g., 'payment.transaction.completed').
     * @param callable $callback The listener function block or method.
     * @param int $priority Execution order index; smaller integers execute first.
     * @param string $owner Slug identifier of the registering plugin or 'core'.
     * @return void
     */
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        string $owner = 'core'
    ): void {
        if ($owner === 'core') {
            $owner = $this->getActiveOwner();
        }
        $this->actions[$hook][] = [
            'callable' => $callback,
            'priority' => $priority,
            'owner'    => $owner,
        ];
        usort($this->actions[$hook], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    /**
     * Execute all registered action callbacks matching the hook name.
     *
     * Wraps individual listener callbacks in try-catch structures to prevent single listener failures
     * from disrupting other hooks or the primary thread flow.
     *
     * @param string $hook The event hook namespace to trigger.
     * @param mixed ...$args Variable list of arguments passed to each registered listener.
     * @return void
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        $this->fireCounts[$hook] = ($this->fireCounts[$hook] ?? 0) + 1;

        if (empty($this->actions[$hook])) {
            return;
        }

        foreach ($this->actions[$hook] as $listener) {
            if (!$this->isOwnerActive($listener['owner'])) {
                continue;
            }
            $this->ownerStack[] = $listener['owner'];
            try {
                ($listener['callable'])(...$args);
            } catch (\Throwable $e) {
                $this->logHookError($hook, $listener['owner'], $e);
            } finally {
                array_pop($this->ownerStack);
            }
        }
    }

    /**
     * Register a filter callback.
     *
     * Filters pass a target value through a pipeline of callbacks where each can modify and return the value.
     *
     * @param string $hook The unique event hook namespace (e.g., 'payment.amount.calculate').
     * @param callable $callback The filter function matching fn($value, ...$args): mixed signature.
     * @param int $priority Execution order index; smaller integers execute first.
     * @param string $owner Slug identifier of the registering plugin or 'core'.
     * @return void
     */
    public function addFilter(
        string $hook,
        callable $callback,
        int $priority = 10,
        string $owner = 'core'
    ): void {
        if ($owner === 'core') {
            $owner = $this->getActiveOwner();
        }
        $this->filters[$hook][] = [
            'callable' => $callback,
            'priority' => $priority,
            'owner'    => $owner,
        ];
        usort($this->filters[$hook], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    /**
     * Transform a target value by piping it sequentially through registered filters.
     *
     * Safeguards execution pipeline by capturing plugin-side exceptions and returning
     * the last valid mutated value block, ensuring critical processes continue uninterrupted.
     *
     * @param string $hook The filter event hook namespace.
     * @param mixed $value The initial value to be transformed.
     * @param mixed ...$args Additional contextual parameters forwarded to the filter callbacks.
     * @return mixed The mutated final filtered value representation.
     */
    public function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        $this->fireCounts[$hook] = ($this->fireCounts[$hook] ?? 0) + 1;

        if (empty($this->filters[$hook])) {
            return $value;
        }

        foreach ($this->filters[$hook] as $listener) {
            if (!$this->isOwnerActive($listener['owner'])) {
                continue;
            }
            $this->ownerStack[] = $listener['owner'];
            try {
                $newValue = ($listener['callable'])($value, ...$args);

                // Enforce SQL sandbox check if a plugin hook modifies database queries
                if ($hook === 'db.query.before' && $listener['owner'] !== 'core') {
                    $sqlToCheck = $newValue['sql'] ?? '';
                    if ($this->container !== null) {
                        $registry = $this->container->get(\OwnPay\Plugin\PluginRegistry::class);
                        if ($registry instanceof \OwnPay\Plugin\PluginRegistry) {
                            $sandbox = $registry->getSandbox($listener['owner']);
                            if ($sandbox === null) {
                                throw new \RuntimeException(
                                    "Database query modified by plugin '{$listener['owner']}' blocked: No active sandbox context defined."
                                );
                            }
                            if (!$sandbox->validateSql($sqlToCheck)) {
                                throw new \RuntimeException(
                                    "Database query modified by plugin '{$listener['owner']}' blocked: direct access to core tables or dangerous SQL operations are restricted."
                                );
                            }
                        }
                    }
                }

                $value = $newValue;
            } catch (\Throwable $e) {
                $this->logHookError($hook, $listener['owner'], $e);
                if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'blocked')) {
                    throw $e;
                }
            } finally {
                array_pop($this->ownerStack);
            }
        }

        return $value;
    }

    /**
     * Purge all action and filter callbacks bound to the specified event hook.
     *
     * @param string $hook The event hook namespace to clean.
     * @return void
     */
    public function removeHook(string $hook): void
    {
        unset($this->actions[$hook], $this->filters[$hook]);
    }

    /**
     * Purge all hook registrations belonging to a specific plugin owner.
     *
     * @param string $owner The unique slug identifier of the plugin owner.
     * @return void
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

    /**
     * Check if one or more listeners are actively bound to the given event hook.
     *
     * @param string $hook The event hook namespace.
     * @return bool True if listeners exist, false otherwise.
     */
    public function hasHook(string $hook): bool
    {
        return !empty($this->actions[$hook]) || !empty($this->filters[$hook]);
    }

    /**
     * Retrieve the count of trigger calls dispatched to the specified hook during execution.
     *
     * @param string $hook The event hook namespace.
     * @return int The total fire count value.
     */
    public function getFireCount(string $hook): int
    {
        return $this->fireCounts[$hook] ?? 0;
    }

    /**
     * Retrieve a list of all registered event hooks and their respective listener counts.
     *
     * @return array<string, array{actions: int, filters: int}> Map of hook namespaces to listener tallies.
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

    /**
     * Alias wrapper method for applyFilter.
     *
     * @param string $hook The filter event hook namespace.
     * @param mixed $value The initial value.
     * @param mixed ...$args Additional parameters.
     * @return mixed The mutated value.
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->applyFilter($hook, $value, ...$args);
    }

    /**
     * Check if action listeners exist for the specified event hook.
     *
     * @param string $hook The event hook namespace.
     * @return bool True if action listeners are registered, false otherwise.
     */
    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    /**
     * Check if filter listeners exist for the specified event hook.
     *
     * @param string $hook The event hook namespace.
     * @return bool True if filter listeners are registered, false otherwise.
     */
    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    /**
     * Unregister a specific action callback from the hook list.
     *
     * @param string $hook The event hook namespace.
     * @param callable $callback The registered callable instance.
     * @return bool True if callback was successfully removed, false otherwise.
     */
    public function removeAction(string $hook, callable $callback): bool
    {
        if (empty($this->actions[$hook])) {
            return false;
        }
        $initialCount = count($this->actions[$hook]);
        $this->actions[$hook] = array_values(
            array_filter($this->actions[$hook], static fn(array $l): bool => $l['callable'] !== $callback)
        );
        if (empty($this->actions[$hook])) {
            unset($this->actions[$hook]);
        }
        return count($this->actions[$hook] ?? []) < $initialCount;
    }

    /**
     * Unregister a specific filter callback from the hook list.
     *
     * @param string $hook The event hook namespace.
     * @param callable $callback The registered filter callable instance.
     * @return bool True if callback was successfully removed, false otherwise.
     */
    public function removeFilter(string $hook, callable $callback): bool
    {
        if (empty($this->filters[$hook])) {
            return false;
        }
        $initialCount = count($this->filters[$hook]);
        $this->filters[$hook] = array_values(
            array_filter($this->filters[$hook], static fn(array $l): bool => $l['callable'] !== $callback)
        );
        if (empty($this->filters[$hook])) {
            unset($this->filters[$hook]);
        }
        return count($this->filters[$hook] ?? []) < $initialCount;
    }

    /**
     * Purge all action and filter hook registrations owned by the specified plugin slug.
     *
     * @param string $owner The unique slug identifier of the plugin owner.
     * @return int The total number of callbacks removed.
     */
    public function removeAllByOwner(string $owner): int
    {
        $removed = 0;
        foreach ($this->actions as $hook => &$listeners) {
            $before = count($listeners);
            $listeners = array_values(
                array_filter($listeners, static fn(array $l): bool => $l['owner'] !== $owner)
            );
            $removed += ($before - count($listeners));
            if (empty($listeners)) {
                unset($this->actions[$hook]);
            }
        }
        unset($listeners);

        foreach ($this->filters as $hook => &$listeners) {
            $before = count($listeners);
            $listeners = array_values(
                array_filter($listeners, static fn(array $l): bool => $l['owner'] !== $owner)
            );
            $removed += ($before - count($listeners));
            if (empty($listeners)) {
                unset($this->filters[$hook]);
            }
        }
        unset($listeners);

        return $removed;
    }

    /**
     * Retrieve all registered actions and filters with their current registration tallies.
     *
     * @return array{actions: array<string, int>, filters: array<string, int>} Map of registered listeners.
     */
    public function getRegistered(): array
    {
        $actions = [];
        foreach ($this->actions as $hook => $listeners) {
            $actions[$hook] = count($listeners);
        }
        $filters = [];
        foreach ($this->filters as $hook => $listeners) {
            $filters[$hook] = count($listeners);
        }
        return [
            'actions' => $actions,
            'filters' => $filters,
        ];
    }

    /**
     * Inspect all action and filter listeners registered under the specified hook name.
     *
     * Sorts the merged list of listeners according to their priority.
     *
     * @param string $hook The target hook name to inspect.
     * @return array<int, array{callable: callable, priority: int, owner: string}> Sorted list of listener data arrays.
     */
    public function inspectHook(string $hook): array
    {
        $listeners = array_merge(
            $this->actions[$hook] ?? [],
            $this->filters[$hook] ?? []
        );
        usort($listeners, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        return $listeners;
    }

    /**
     * Write error details generated during hook invocation into the system log.
     *
     * @param string $hook The triggered hook namespace.
     * @param string $owner The slug identifier of the plugin that threw the exception.
     * @param \Throwable $e The exception object.
     * @return void
     */
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
        }
    }
}

