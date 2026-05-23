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
     * Absolute directory path of the manifest file.
     *
     * @var string
     */
    public readonly string $sourcePath;

    /**
     * Deprecated compatibility permissions array.
     *
     * @var array<int, string>
     */
    public readonly array $permissions;

    /**
     * Deprecated compatibility category string.
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
     * Deprecated requirement definitions structure.
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
        $this->name = $data['name'] ?? '';
        $this->slug = $data['slug'] ?? '';
        $this->version = $data['version'] ?? '0.0.0';
        $this->type = $data['type'] ?? 'plugin';
        $this->description = $data['description'] ?? '';
        $this->author = $data['author'] ?? '';
        $this->authorUrl = $data['author_url'] ?? $data['author_url'] ?? '';
        $this->license = $data['license'] ?? '';
        $this->entrypoint = $data['entrypoint'] ?? $data['entry'] ?? 'Plugin.php';
        $this->namespace = $data['namespace'] ?? '';
        
        $minPhpRaw = $data['min_php'] ?? ($data['requires']['php'] ?? '8.2');
        $this->minPhp = ltrim($minPhpRaw, '>= ');
        
        $minAppRaw = $data['min_app'] ?? ($data['requires']['core'] ?? '');
        $this->minApp = ltrim($minAppRaw, '>= ');

        $this->capabilities = $data['capabilities'] ?? [];
        
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
        $this->hooks = [
            'actions' => $rawHooks['actions'] ?? [],
            'filters' => $rawHooks['filters'] ?? [],
        ];

        $this->adminMenu = $data['admin_menu'] ?? $data['adminMenu'] ?? [];
        $this->cron = $data['cron'] ?? [];
        $this->migrations = $data['migrations'] ?? [];
        
        $this->sourcePath = $sourcePath;
        
        $this->permissions = $data['permissions'] ?? [];
        $this->category = $data['category'] ?? 'global';
        $this->icon = $data['icon'] ?? '';
        $this->color = $data['color'] ?? '#0D9488';
        $this->path = $sourcePath;
        $this->requires = $data['requires'] ?? [
            'php' => '>=' . $this->minPhp,
            'core' => '>=' . $this->minApp,
        ];
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

        return new self($data, dirname($path));
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
     * Resolves the fully qualified class name for the plugin entrypoint class.
     *
     * Utilizes PSR-4 standard mappings under the `OwnPayPlugin` root namespace.
     *
     * @return string Fully qualified class name.
     */
    public function getFullyQualifiedClassName(): string
    {
        $className = pathinfo($this->entrypoint, PATHINFO_FILENAME);
        $ns = $this->namespace;
        if ($ns === '') {
            $parts = explode('-', $this->slug);
            $capitalized = array_map('ucfirst', $parts);
            $ns = implode('', $capitalized);
        }
        return 'OwnPayPlugin\\' . $ns . '\\' . $className;
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
        ];
    }
}
