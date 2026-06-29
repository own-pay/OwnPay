<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Represents the parsed manifest metadata (manifest.json) of a plugin.
 *
 * Exposes plugin properties such as capabilities, dependencies, required versions,
 * database migrations, and background jobs. Facilitates lifecycle checking and
 * ensures strict integration with the application architecture.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
final class PluginManifest
{
    /**
     * Human-readable plugin name.
     *
     * @var string
     */
    public readonly string $name;

    /**
     * Unique url-safe slug identifier.
     *
     * @var string
     */
    public readonly string $slug;

    /**
     * Current version of the plugin using semantic versioning.
     *
     * @var string
     */
    public readonly string $version;

    /**
     * Capability type of the plugin (e.g. gateway, addon, theme).
     *
     * @var string
     */
    public readonly string $type;

    /**
     * Brief description of the plugin functionality.
     *
     * @var string
     */
    public readonly string $description;

    /**
     * Plugin author name.
     *
     * @var string
     */
    public readonly string $author;

    /**
     * URL reference to the plugin author.
     *
     * @var string
     */
    public readonly string $authorUrl;

    /**
     * Software license identifier.
     *
     * @var string
     */
    public readonly string $license;

    /**
     * Entrypoint PHP filename.
     *
     * @var string
     */
    public readonly string $entrypoint;

    /**
     * Explicit PHP namespace declared by the plugin.
     *
     * @var string
     */
    public readonly string $namespace;

    /**
     * Minimum PHP version required to run this plugin safely.
     *
     * @var string
     */
    public readonly string $minPhp;

    /**
     * Minimum OwnPay core platform version required.
     *
     * @var string
     */
    public readonly string $minApp;

    /**
     * List of capabilities requested/provided.
     *
     * @var array<int, string>
     */
    public readonly array $capabilities;

    /**
     * Slugs of other plugins that must be installed/activated beforehand.
     *
     * @var array<int, string>
     */
    public readonly array $dependencies;

    /**
     * Action and filter hook mappings.
     *
     * @var array{actions: array<string, mixed>, filters: array<string, mixed>}
     */
    public readonly array $hooks;

    /**
     * Admin menu hierarchy configurations for automatic injection.
     *
     * @var array<string, mixed>
     */
    public readonly array $adminMenu;

    /**
     * Background cron schedules registered by this plugin.
     *
     * @var array<int, array{name: string, schedule: string, class?: string}>
     */
    public readonly array $cron;

    /**
     * List of migration file names/classes.
     *
     * @var array<int, string>
     */
    public readonly array $migrations;

    /**
     * Custom routes registered by this plugin.
     *
     * @var array<int, array<mixed>>
     */
    public readonly array $routes;

    /**
     * Absolute directory path of the manifest file.
     *
     * @var string
     */
    public readonly string $sourcePath;

    /**
     * Plugin category grouping (e.g. 'global', 'payment').
     *
     * @var string
     */
    public readonly string $category;

    /**
     * Icon image path relative to the plugin root.
     *
     * @var string
     */
    public readonly string $icon;

    /**
     * Color hex code representing the plugin.
     *
     * @var string
     */
    public readonly string $color;

    /**
     * Absolute physical directory containing this plugin.
     *
     * @var string
     */
    public readonly string $path;

    /**
     * Minimum version requirements (php and core).
     *
     * @var array{php: string, core: string}
     */
    public readonly array $requires;

    /**
     * PluginManifest constructor.
     *
     * @param array<string, mixed> $data       Parsed JSON manifest array.
     * @param string               $sourcePath Absolute directory path of the plugin.
     */
    private function __construct(array $data, string $sourcePath)
    {
        $this->name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $this->slug = is_string($data['slug'] ?? null) ? $data['slug'] : '';
        $this->version = is_string($data['version'] ?? null) ? $data['version'] : '0.0.0';
        $this->type = is_string($data['type'] ?? null) ? $data['type'] : 'plugin';
        $this->description = is_string($data['description'] ?? null) ? $data['description'] : '';
        $this->author = is_string($data['author'] ?? null) ? $data['author'] : '';
        $this->authorUrl = is_string($data['author_url'] ?? null) ? $data['author_url'] : '';
        $this->license = is_string($data['license'] ?? null) ? $data['license'] : '';
        
        $entrypointRaw = $data['entrypoint'] ?? ($data['entry'] ?? null);
        $this->entrypoint = is_string($entrypointRaw) ? $entrypointRaw : 'Plugin.php';
        $this->namespace = is_string($data['namespace'] ?? null) ? $data['namespace'] : '';
        
        $minPhpRaw = '8.2';
        if (isset($data['min_php']) && is_string($data['min_php'])) {
            $minPhpRaw = $data['min_php'];
        } elseif (isset($data['requires']) && is_array($data['requires']) && isset($data['requires']['php']) && is_string($data['requires']['php'])) {
            $minPhpRaw = $data['requires']['php'];
        }
        $this->minPhp = ltrim($minPhpRaw, '>= ');
        
        $minAppRaw = '';
        if (isset($data['min_app']) && is_string($data['min_app'])) {
            $minAppRaw = $data['min_app'];
        } elseif (isset($data['requires']) && is_array($data['requires']) && isset($data['requires']['core']) && is_string($data['requires']['core'])) {
            $minAppRaw = $data['requires']['core'];
        }
        $this->minApp = ltrim($minAppRaw, '>= ');

        $rawCapabilities = $data['capabilities'] ?? [];
        $capabilities = [];
        if (is_array($rawCapabilities)) {
            foreach ($rawCapabilities as $cap) {
                if (is_string($cap) && $cap !== '') {
                    $capabilities[] = $cap;
                }
            }
        }
        $this->capabilities = $capabilities;
        
        $rawDeps = $data['dependencies'] ?? [];
        $dependencies = [];
        if (is_array($rawDeps)) {
            foreach ($rawDeps as $dep) {
                if (is_string($dep) && $dep !== '') {
                    $dependencies[] = $dep;
                }
            }
        }
        $this->dependencies = $dependencies;

        $rawHooks = $data['hooks'] ?? [];
        $actions = [];
        $filters = [];
        if (is_array($rawHooks)) {
            if (isset($rawHooks['actions']) && is_array($rawHooks['actions'])) {
                foreach ($rawHooks['actions'] as $k => $v) {
                    $actions[(string) $k] = $v;
                }
            }
            if (isset($rawHooks['filters']) && is_array($rawHooks['filters'])) {
                foreach ($rawHooks['filters'] as $k => $v) {
                    $filters[(string) $k] = $v;
                }
            }
        }
        $this->hooks = [
            'actions' => $actions,
            'filters' => $filters,
        ];

        $rawAdminMenu = $data['admin_menu'] ?? $data['adminMenu'] ?? [];
        $adminMenu = [];
        if (is_array($rawAdminMenu)) {
            foreach ($rawAdminMenu as $k => $v) {
                $adminMenu[(string) $k] = $v;
            }
        }
        $this->adminMenu = $adminMenu;

        $rawCron = $data['cron'] ?? [];
        $cron = [];
        if (is_array($rawCron)) {
            foreach ($rawCron as $entry) {
                if (is_array($entry) && isset($entry['name'], $entry['schedule']) && is_string($entry['name']) && is_string($entry['schedule'])) {
                    $cronEntry = [
                        'name' => $entry['name'],
                        'schedule' => $entry['schedule'],
                    ];
                    if (isset($entry['class']) && is_string($entry['class'])) {
                        $cronEntry['class'] = $entry['class'];
                    }
                    $cron[] = $cronEntry;
                }
            }
        }
        $this->cron = $cron;

        $rawMigrations = $data['migrations'] ?? [];
        $migrations = [];
        if (is_array($rawMigrations)) {
            foreach ($rawMigrations as $migration) {
                if (is_string($migration) && $migration !== '') {
                    $migrations[] = $migration;
                }
            }
        }
        $this->migrations = $migrations;

        $rawRoutes = $data['routes'] ?? [];
        $routes = [];
        if (is_array($rawRoutes)) {
            foreach ($rawRoutes as $route) {
                if (is_array($route) && count($route) >= 3) {
                    $routes[] = $route;
                }
            }
        }
        $this->routes = $routes;
        
        $this->sourcePath = $sourcePath;

        $this->category = is_string($data['category'] ?? null) ? $data['category'] : 'global';
        $this->icon = is_string($data['icon'] ?? null) ? $data['icon'] : '';
        $this->color = is_string($data['color'] ?? null) ? $data['color'] : '#0D9488';
        $this->path = $sourcePath;

        $rawRequires = $data['requires'] ?? null;
        if (is_array($rawRequires) && isset($rawRequires['php'], $rawRequires['core']) && is_string($rawRequires['php']) && is_string($rawRequires['core'])) {
            $this->requires = [
                'php' => $rawRequires['php'],
                'core' => $rawRequires['core'],
            ];
        } else {
            $this->requires = [
                'php' => '>=' . $this->minPhp,
                'core' => '>=' . $this->minApp,
            ];
        }
    }

    /**
     * Parses and builds a manifest from a directory scan.
     *
     * @param string $dir Path to the plugin directory.
     * @return self|null The parsed manifest object, or null if manifest.json does not exist.
     */
    public static function fromDirectory(string $dir): ?self
    {
        $base = rtrim($dir, '/\\');
        $file = $base . '/manifest.json';

        if (!file_exists($file)) {
            return null;
        }

        try {
            return self::fromFile($file);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Builds a manifest directly from a raw data array.
     *
     * @param array<string, mixed> $data       Metadata values.
     * @param string               $sourcePath Absolute directory path of the plugin.
     * @return self Newly instantiated manifest wrapper.
     */
    public static function fromArray(array $data, string $sourcePath = ''): self
    {
        return new self($data, $sourcePath);
    }

    /**
     * Parses and instantiates a manifest from a specific JSON file path.
     *
     * @param string $path Absolute path to manifest.json.
     * @return self Manifest instance.
     * @throws \RuntimeException If the file is missing or contains invalid JSON structure.
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException('Manifest file not found');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Failed to read manifest file');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException('Manifest must decode to a JSON object');
        }

        $stringKeyedData = [];
        foreach ($data as $k => $v) {
            $stringKeyedData[(string) $k] = $v;
        }

        return new self($stringKeyedData, dirname($path));
    }

    /**
     * Validates that the manifest defines all required platform attributes.
     *
     * Performs regex checks on the slug pattern and checks for path traversal.
     *
     * @return array<int, string> List of validation error messages.
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->name === '') {
            $errors[] = 'Missing required field: "name"';
        }
        if ($this->slug === '') {
            $errors[] = 'Missing required field: "slug"';
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $this->slug)) {
            $errors[] = 'Invalid slug format';
        }
        if (!in_array($this->type, ['plugin', 'gateway', 'theme', 'addon'], true)) {
            $errors[] = 'Invalid type';
        }
        if ($this->entrypoint === '') {
            $errors[] = 'Missing required field: "entrypoint"';
        } elseif (str_contains($this->entrypoint, '..') || str_contains($this->entrypoint, '/') || str_contains($this->entrypoint, '\\')) {
            $errors[] = 'Entrypoint must be a plain filename';
        }
        
        foreach ($this->cron as $idx => $entry) {
            if (empty($entry['name'])) {
                $errors[] = "Cron entry #{$idx}: missing \"name\"";
            }
            if (empty($entry['schedule'])) {
                $errors[] = "Cron entry #{$idx}: missing \"schedule\"";
            }
        }

        foreach ($this->migrations as $migration) {
            if (str_contains($migration, '..')) {
                $errors[] = "Migration contains path traversal";
            }
        }

        return $errors;
    }

    /**
     * Checks if this plugin is compatible with the running application version.
     *
     * @param string $coreVersion Active system platform version.
     * @return bool True if core satisfies minimum requirements.
     */
    public function isCompatible(string $coreVersion): bool
    {
        $required = $this->minApp ?: '0.1.0';
        return version_compare($coreVersion, $required, '>=');
    }

    /**
     * Verifies if the plugin declares a specific Capability value.
     *
     * @param \OwnPay\Plugin\Capability $cap The capability enum query.
     * @return bool True if capability is declared.
     */
    public function hasCapability(Capability $cap): bool
    {
        return in_array($cap->value, $this->capabilities, true);
    }

    /**
     * Resolves the list of Capability enums declared by this plugin.
     *
     * @return array<int, \OwnPay\Plugin\Capability> Declared capabilities.
     */
    public function getCapabilities(): array
    {
        $cases = [];
        foreach ($this->capabilities as $capVal) {
            $case = Capability::tryFrom($capVal);
            if ($case !== null) {
                $cases[] = $case;
            }
        }
        return $cases;
    }

    /**
     * Resolves the fully qualified class name of the plugin entrypoint.
     *
     * MUST stay identical to PluginLoader::resolveClassName(): route handlers are built from this
     * method, and the loader registers the instance under that same name in the container - any
     * divergence makes plugin routes dispatch to a non-existent class.
     *
     * @return string Fully qualified class name.
     */
    public function getFullyQualifiedClassName(): string
    {
        $className = pathinfo($this->entrypoint, PATHINFO_FILENAME);
        if ($this->namespace !== '') {
            return rtrim($this->namespace, '\\') . '\\' . $className;
        }
        // Fallback convention mirrors PluginLoader::resolveClassName() exactly.
        $pascal = str_replace('-', '', ucwords($this->slug, '-'));
        return 'OwnPay\\Plugins\\' . $pascal . '\\' . $className;
    }

    /**
     * Serializes the manifest model back into a standard array format.
     *
     * @return array<string, mixed> Array representation of manifest properties.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'type' => $this->type,
            'description' => $this->description,
            'author' => $this->author,
            'author_url' => $this->authorUrl,
            'license' => $this->license,
            'entrypoint' => $this->entrypoint,
            'namespace' => $this->namespace,
            'min_php' => $this->minPhp,
            'min_app' => $this->minApp,
            'capabilities' => $this->capabilities,
            'dependencies' => $this->dependencies,
            'hooks' => $this->hooks,
            'admin_menu' => $this->adminMenu,
            'cron' => $this->cron,
            'migrations' => $this->migrations,
            'routes' => $this->routes,
        ];
    }
}
