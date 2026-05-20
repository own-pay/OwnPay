<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Plugin manifest — parsed from manifest.json.
 */
final class PluginManifest
{
    public readonly string $name;
    public readonly string $slug;
    public readonly string $version;
    public readonly string $type;
    public readonly string $description;
    public readonly string $author;
    public readonly string $authorUrl;
    public readonly string $license;
    public readonly string $entrypoint;
    public readonly string $namespace;
    public readonly string $minPhp;
    public readonly string $minApp;
    public readonly array $capabilities;
    public readonly array $dependencies;
    public readonly array $hooks;
    public readonly array $adminMenu;
    public readonly array $cron;
    public readonly array $migrations;
    public readonly string $sourcePath;

    // Backwards compatibility properties
    public readonly array $permissions;
    public readonly string $category;
    public readonly string $icon;
    public readonly string $color;
    public readonly string $path;
    public readonly array $requires;

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
        
        // dependencies: filter out non-string and empty values
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

        // hooks: actions and filters
        $rawHooks = $data['hooks'] ?? [];
        $this->hooks = [
            'actions' => $rawHooks['actions'] ?? [],
            'filters' => $rawHooks['filters'] ?? [],
        ];

        $this->adminMenu = $data['admin_menu'] ?? $data['adminMenu'] ?? [];
        $this->cron = $data['cron'] ?? [];
        $this->migrations = $data['migrations'] ?? [];
        
        $this->sourcePath = $sourcePath;
        
        // backwards compatibility properties
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
     * Parse from directory.
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
     * Parse from array.
     */
    public static function fromArray(array $data, string $sourcePath = ''): self
    {
        return new self($data, $sourcePath);
    }

    /**
     * Parse from file.
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException('Manifest file not found');
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException('Manifest must decode to a JSON object');
        }

        return new self($data, $path);
    }

    /**
     * Validate manifest has all required fields.
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
        if (!in_array($this->type, ['plugin', 'gateway', 'theme'], true)) {
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
     * Check compatibility.
     */
    public function isCompatible(string $coreVersion): bool
    {
        $required = $this->minApp ?: '0.1.0';
        return version_compare($coreVersion, $required, '>=');
    }

    /**
     * Capability check.
     */
    public function hasCapability(Capability $cap): bool
    {
        return in_array($cap->value, $this->capabilities, true);
    }

    /**
     * Get capability enum cases.
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
     * Class name resolution.
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
     * Serialise manifest.
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
