<?php
declare(strict_types=1);

namespace OwnPay;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lightweight PSR-11 compatible Dependency Injection (DI) container.
 *
 * Supports singleton and transient bindings, autowiring via reflection,
 * parameter bindings, and aliasing.
 */
final class Container
{
    /**
     * @var array<string, \Closure> Factory closures keyed by abstract service names.
     */
    private array $bindings = [];

    /**
     * @var array<string, mixed> Cached singleton instances resolved at runtime.
     */
    private array $instances = [];

    /**
     * @var array<string, bool> Tracks which registered bindings are singletons.
     */
    private array $singletons = [];

    /**
     * @var array<string, string> Alias mapping of abstract names to concrete service classes.
     */
    private array $aliases = [];

    /**
     * @var array<string, mixed> Raw parameters (primitives/arrays) injected into services.
     */
    private array $parameters = [];

    /**
     * @var array<string, bool> Active resolution guard tracking to prevent circular dependencies.
     */
    private array $resolving = [];

    // Service Binding Operations

    /**
     * Register a transient service factory closure.
     *
     * @param string $abstract Service identifier or class name.
     * @param \Closure $factory Factory closure used to instantiate the service.
     * @return void
     */
    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract], $this->singletons[$abstract]);
    }

    /**
     * Register a singleton service factory closure.
     *
     * @param string $abstract Service identifier or class name.
     * @param \Closure $factory Factory closure resolved once and cached as a singleton.
     * @return void
     */
    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        $this->singletons[$abstract] = true;
        unset($this->instances[$abstract]);
    }

    /**
     * Bind a pre-constructed instance directly into the container.
     *
     * @param string $abstract Service identifier or class name.
     * @param mixed $instance The pre-built concrete instance.
     * @return void
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
        unset($this->bindings[$abstract]);
    }

    /**
     * Define an alias pointing to an existing service mapping.
     *
     * @param string $alias The shortcut or alias name.
     * @param string $concrete The targeted class or service name.
     * @return void
     * @throws \InvalidArgumentException If the alias attempts to point to itself.
     */
    public function alias(string $alias, string $concrete): void
    {
        if ($alias === $concrete) {
            throw new InvalidArgumentException(
                "Alias [{$alias}] cannot reference itself."
            );
        }
        $this->aliases[$alias] = $concrete;
    }

    /**
     * Bind a raw parameter value into the container's registry.
     *
     * @param string $key Parameter name/key.
     * @param mixed $value Raw parameter value (scalar, array, or object).
     * @return void
     */
    public function parameter(string $key, mixed $value): void
    {
        $this->parameters[$key] = $value;
    }

    // Service Resolution Operations

    /**
     * Retrieve and resolve a registered service by its identifier.
     *
     * Part of the PSR-11 container interface implementation.
     *
     * @param string $abstract Service identifier (fully qualified class name or alias).
     * @return mixed Resolved service instance.
     * @throws \RuntimeException If the service cannot be resolved or a circular dependency is hit.
     */
    public function get(string $abstract): mixed
    {

        $abstract = $this->resolveAlias($abstract);

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            if (class_exists($abstract)) {
                return $this->autowire($abstract);
            }

            throw new RuntimeException(
                "No binding registered for [{$abstract}]."
            );
        }

        if (isset($this->resolving[$abstract])) {
            throw new RuntimeException(
                "Circular dependency detected while resolving [{$abstract}]."
            );
        }

        $this->resolving[$abstract] = true;

        try {
            $instance = ($this->bindings[$abstract])($this);
        } finally {
            unset($this->resolving[$abstract]);
        }

        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Check if a service binding or cached instance exists in the container.
     *
     * Part of the PSR-11 container interface implementation.
     *
     * @param string $abstract Service identifier.
     * @return bool True if registered, false otherwise.
     */
    public function has(string $abstract): bool
    {
        $abstract = $this->resolveAlias($abstract);

        return isset($this->instances[$abstract])
            || isset($this->bindings[$abstract]);
    }

    /**
     * Fetch a raw parameter value from the parameters registry.
     *
     * @param string $key Parameter identifier.
     * @return mixed The raw parameter value.
     * @throws \RuntimeException If the parameter is not defined.
     */
    public function param(string $key): mixed
    {
        if (!array_key_exists($key, $this->parameters)) {
            throw new RuntimeException(
                "Parameter [{$key}] not found in container."
            );
        }
        return $this->parameters[$key];
    }

    /**
     * Verify if a parameter is defined in the registry.
     *
     * @param string $key Parameter identifier.
     * @return bool True if it exists, false otherwise.
     */
    public function hasParam(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    /**
     * Trace an alias chain to determine the canonical concrete service identifier.
     *
     * @param string $abstract The alias name to trace.
     * @return string Canonical service identifier.
     * @throws \RuntimeException If a circular alias reference chain is encountered.
     */
    private function resolveAlias(string $abstract): string
    {
        $seen = [];
        // Loop to trace nested alias declarations, guarding against cyclic references.
        while (isset($this->aliases[$abstract])) {
            if (isset($seen[$abstract])) {
                throw new RuntimeException(
                    "Circular alias detected for [{$abstract}]."
                );
            }
            $seen[$abstract] = true;
            $abstract = $this->aliases[$abstract];
        }
        return $abstract;
    }

    // Reflection Autowiring Layer

    /**
     * Dynamically construct an object and inject its constructor dependencies using Reflection.
     *
     * @param class-string $class Fully qualified class name to instantiate.
     * @return mixed Instantiated class with resolved dependencies.
     * @throws \RuntimeException If class is uninstantiable or constructor arguments cannot be resolved.
     */
    private function autowire(string $class): mixed
    {
        $reflector = new \ReflectionClass($class);
        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class [{$class}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencyClass = $type->getName();
                if ($dependencyClass === self::class) {
                    $dependencies[] = $this;
                } else {
                    $dependencies[] = $this->get($dependencyClass);
                }
            } else {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new RuntimeException("Cannot resolve primitive parameter \${$parameter->getName()} in class {$class}.");
                }
            }
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    // Container Introspection and Cleanup

    /**
     * Get all registered binding keys (excluding aliases).
     *
     * @return string[] Array of abstract service names.
     */
    public function keys(): array
    {
        return array_unique(
            array_merge(
                array_keys($this->bindings),
                array_keys($this->instances)
            )
        );
    }

    /**
     * Remove a binding and its resolved singleton instance from memory.
     *
     * @param string $abstract Service identifier to remove.
     * @return void
     */
    public function forget(string $abstract): void
    {
        $abstract = $this->resolveAlias($abstract);
        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract],
            $this->singletons[$abstract]
        );
    }

    /**
     * Clear all bindings, aliases, cached singleton instances, and parameters.
     *
     * Reset the container back to its initial empty state.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->singletons = [];
        $this->aliases = [];
        $this->parameters = [];
        $this->resolving = [];
    }
}
