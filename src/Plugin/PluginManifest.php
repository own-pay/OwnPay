<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Plugin manifest â€” parsed from manifest.json.
 *
 * Every plugin must have a manifest.json at its root:
 * {
 *   "name": "My Plugin",
 *   "slug": "my-plugin",
 *   "version": "1.0.0",
 *   "description": "Does something",
 *   "author": "Dev Name",
 *   "type": "addon",
 *   "entrypoint": "MyPlugin.php",
 *   "capabilities": ["addon"],
 *   "requires": {"core": ">=0.1.0", "php": ">=8.1"},
 *   "permissions": ["transactions.view"]
 * }
 */
final class PluginManifest
{
    public readonly string $name;
    public readonly string $slug;
    public readonly string $version;
    public readonly string $description;
    public readonly string $author;
    public readonly string $type;
    public readonly string $entrypoint;
    public readonly array $capabilities;
    public readonly array $requires;
    public readonly array $permissions;
    public readonly string $path;

    private function __construct(array $data, string $path)
    {
        $this->name = $data['name'] ?? basename($path);
        $this->slug = $data['slug'] ?? $data['name'] ?? basename($path);
        $this->version = $data['version'] ?? '0.0.0';
        $this->description = $data['description'] ?? '';
        $this->author = $data['author'] ?? '';
        $this->type = $data['type'] ?? 'addon';
        $this->entrypoint = $data['entrypoint'] ?? $data['entry'] ?? '';
        $this->capabilities = $data['capabilities'] ?? [];
        $this->requires = $data['requires'] ?? [];
        $this->permissions = $data['permissions'] ?? [];
        $this->path = $path;
    }

    /**
     * Parse plugin.json from directory.
     */
    public static function fromDirectory(string $dir): ?self
    {
        $base = rtrim($dir, '/\\');
        $file = $base . '/manifest.json';

        if (!file_exists($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        return new self($data, $dir);
    }

    /**
     * Validate manifest has all required fields.
     * @return string[] List of validation errors (empty = valid)
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->name === '') {
            $errors[] = 'Missing "name"';
        }
        if ($this->slug === '') {
            $errors[] = 'Missing "slug"';
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $this->slug)) {
            $errors[] = 'Invalid "slug" format (lowercase alphanumeric + hyphens)';
        }
        if ($this->version === '' || $this->version === '0.0.0') {
            $errors[] = 'Missing or invalid "version"';
        }
        if ($this->entrypoint === '') {
            $errors[] = 'Missing "entrypoint"';
        }
        if (!in_array($this->type, ['gateway', 'theme', 'addon'], true)) {
            $errors[] = 'Invalid "type" (must be: gateway, theme, addon)';
        }
        return $errors;
    }

    /**
     * Check if core version requirement met.
     */
    public function isCompatible(string $coreVersion): bool
    {
        $required = $this->requires['core'] ?? '>=0.1.0';
        return version_compare($coreVersion, ltrim($required, '>= '), '>=');
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'type' => $this->type,
            'entrypoint' => $this->entrypoint,
            'capabilities' => $this->capabilities,
            'requires' => $this->requires,
            'permissions' => $this->permissions,
        ];
    }
}
