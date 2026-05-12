<?php
declare(strict_types=1);

namespace OwnPay;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lightweight PSR-11 compatible DI container.
 *
 * Supports:
 * - Singleton and transient bindings
 * - Factory closures with auto-injection of container
 * - Parameter binding for primitives
 * - Alias resolution
 * - Lazy instantiation (services created only when first requested)
 */
final class Container
{
    /** @var array<string, Closure> Factory closures keyed by abstract name */
    private array $bindings = [];

    /** @var array<string, mixed> Resolved singleton instances */
    private array $instances = [];

    /** @var array<string, bool> Whether a binding should be treated as singleton */
    private array $singletons = [];

    /** @var array<string, string> Alias â†’ concrete mapping */
    private array $aliases = [];

    /** @var array<string, mixed> Raw parameter values */
    private array $parameters = [];

    /** @var array<string, bool> Guard against circular dependencies */
    private array $resolving = [];

    // â”€â”€â”€ Binding â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Register a factory closure. Transient by default.
     *
     * @param string  $abstract Service identifier (class name or alias)
     * @param Closure $factory  fn(Container): mixed
     */
    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract], $this->singletons[$abstract]);
    }

    /**
     * Register a factory that will be resolved once and cached.
     *
     * @param string  $abstract Service identifier
     * @param Closure $factory  fn(Container): mixed
     */
    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        $this->singletons[$abstract] = true;
        unset($this->instances[$abstract]);
    }

    /**
     * Register a pre-built instance directly.
     *
     * @param string $abstract Service identifier
     * @param mixed  $instance The concrete instance
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
        unset($this->bindings[$abstract]);
    }

    /**
     * Create an alias that points to another binding.
     *
     * @param string $alias    The alias name
     * @param string $concrete The target binding
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
     * Store a raw parameter value (non-service).
     */
    public function parameter(string $key, mixed $value): void
    {
        $this->parameters[$key] = $value;
    }

    // â”€â”€â”€ Resolution â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Resolve a service from the container. PSR-11 `get()`.
     *
     * @throws RuntimeException If binding not found or circular dependency detected
     */
    public function get(string $abstract): mixed
    {
        // Resolve alias chain
        $abstract = $this->resolveAlias($abstract);

        // Return cached singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            // Basic autowiring for Repositories
            if (class_exists($abstract) && str_ends_with($abstract, 'Repository') && is_subclass_of($abstract, \OwnPay\Repository\BaseRepository::class)) {
                $db = $this->get(\OwnPay\Core\Database::class);
                $instance = new $abstract($db);
                $this->instances[$abstract] = $instance;
                return $instance;
            }

            // Generic Autowiring via Reflection
            if (class_exists($abstract)) {
                return $this->autowire($abstract);
            }

            throw new RuntimeException(
                "No binding registered for [{$abstract}]."
            );
        }

        // Circular dependency guard
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

        // Cache if singleton
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Check if a binding or instance exists. PSR-11 `has()`.
     */
    public function has(string $abstract): bool
    {
        $abstract = $this->resolveAlias($abstract);

        return isset($this->instances[$abstract])
            || isset($this->bindings[$abstract]);
    }

    /**
     * Retrieve a raw parameter value.
     *
     * @throws RuntimeException If parameter not found
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
     * Check if a parameter exists.
     */
    public function hasParam(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    /**
     * Resolve an alias chain to its final concrete name.
     */
    private function resolveAlias(string $abstract): string
    {
        $seen = [];
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

    // â”€â”€â”€ Autowiring â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

    // â”€â”€â”€ Introspection â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Get all registered binding keys (excluding aliases).
     *
     * @return string[]
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
     * Remove a binding and its cached instance.
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
     * Flush all bindings, instances, aliases, and parameters.
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
