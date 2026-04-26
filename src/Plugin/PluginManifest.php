<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

/**
 * Immutable value object representing a parsed and validated plugin manifest.
 *
 * Every plugin ships a `manifest.json` at its root.  This class parses that
 * file into a strongly-typed, readonly object and rejects manifests that
 * fail validation (missing required fields, invalid slug, unsupported type, etc.).
 *
 * @example
 *   $manifest = PluginManifest::fromFile('/path/to/manifest.json');
 *   if ($errors = $manifest->validate()) { throw ... }
 *   echo $manifest->slug;
 */
final class PluginManifest
{
    /** Plugin types recognised by the loader */
    private const VALID_TYPES = ['plugin', 'gateway', 'theme'];

    /** Slug format: lowercase alphanum + hyphens, 2-60 chars, no leading/trailing hyphens */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9\-]{0,58}[a-z0-9]$/';

    // ── Core identity ───────────────────────────────────────────────

    public readonly string $name;
    public readonly string $slug;
    public readonly string $version;

    /** One of: 'plugin', 'gateway', 'theme' */
    public readonly string $type;

    public readonly string $description;
    public readonly string $author;
    public readonly string $authorUrl;
    public readonly string $license;

    // ── Entrypoint & namespace ──────────────────────────────────────

    /** Relative path to the main PHP file inside the plugin directory */
    public readonly string $entrypoint;

    /** PSR-4 sub-namespace under OwnPayPlugin\ (e.g. "SmsNotifications") */
    public readonly string $namespace;

    // ── Compatibility ───────────────────────────────────────────────

    public readonly string $minPhp;
    public readonly string $minApp;

    // ── Capabilities & dependencies ─────────────────────────────────

    /** @var list<string> Capability strings (validated against the Capability enum) */
    public readonly array $capabilities;

    /** @var list<string> Slugs of plugins this one depends on */
    public readonly array $dependencies;

    // ── Hook declarations ───────────────────────────────────────────

    /** @var array{actions: list<string>, filters: list<string>} */
    public readonly array $hooks;

    // ── Admin menu entries ──────────────────────────────────────────

    /** @var list<array{title: string, slug: string, icon: string, parent: string, permission: string}> */
    public readonly array $adminMenu;

    // ── Cron job declarations ───────────────────────────────────────

    /** @var list<array{name: string, schedule: string, description: string}> */
    public readonly array $cron;

    // ── Database migrations ─────────────────────────────────────────

    /** @var list<string> Relative paths to SQL migration files */
    public readonly array $migrations;

    // ── Source path (populated by fromFile) ──────────────────────────

    /** Absolute path to the manifest.json file on disk */
    public readonly string $sourcePath;

    // ── Construction ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data Raw decoded manifest
     */
    private function __construct(array $data, string $sourcePath)
    {
        $this->sourcePath = $sourcePath;

        // Core identity
        $this->name        = (string) ($data['name'] ?? '');
        $this->slug        = (string) ($data['slug'] ?? '');
        $this->version     = (string) ($data['version'] ?? '0.0.0');
        $this->type        = (string) ($data['type'] ?? 'plugin');
        $this->description = (string) ($data['description'] ?? '');
        $this->author      = (string) ($data['author'] ?? '');
        $this->authorUrl   = (string) ($data['author_url'] ?? '');
        $this->license     = (string) ($data['license'] ?? '');

        // Entrypoint & namespace
        $this->entrypoint = (string) ($data['entrypoint'] ?? 'Plugin.php');
        $this->namespace  = (string) ($data['namespace'] ?? '');

        // Compatibility
        $this->minPhp = (string) ($data['min_php'] ?? '8.2');
        $this->minApp = (string) ($data['min_app'] ?? '0.0.0');

        // Capabilities
        $this->capabilities = array_values(
            array_filter(
                (array) ($data['capabilities'] ?? []),
                fn(mixed $v): bool => is_string($v) && $v !== '',
            )
        );

        // Dependencies
        $this->dependencies = array_values(
            array_filter(
                (array) ($data['dependencies'] ?? []),
                fn(mixed $v): bool => is_string($v) && $v !== '',
            )
        );

        // Hooks
        $hooks = (array) ($data['hooks'] ?? []);
        $this->hooks = [
            'actions' => array_values(array_filter((array) ($hooks['actions'] ?? []), 'is_string')),
            'filters' => array_values(array_filter((array) ($hooks['filters'] ?? []), 'is_string')),
        ];

        // Admin menu
        $this->adminMenu = array_values(
            array_map(
                fn(array $item): array => [
                    'title'      => (string) ($item['title'] ?? ''),
                    'slug'       => (string) ($item['slug'] ?? ''),
                    'icon'       => (string) ($item['icon'] ?? 'puzzle'),
                    'parent'     => (string) ($item['parent'] ?? ''),
                    'permission' => (string) ($item['permission'] ?? 'manage_plugins'),
                ],
                array_filter((array) ($data['admin_menu'] ?? []), 'is_array'),
            )
        );

        // Cron
        $this->cron = array_values(
            array_map(
                fn(array $item): array => [
                    'name'        => (string) ($item['name'] ?? ''),
                    'schedule'    => (string) ($item['schedule'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                ],
                array_filter((array) ($data['cron'] ?? []), 'is_array'),
            )
        );

        // Migrations
        $this->migrations = array_values(
            array_filter(
                (array) ($data['migrations'] ?? []),
                fn(mixed $v): bool => is_string($v) && $v !== '',
            )
        );
    }

    // ── Factory methods ─────────────────────────────────────────────

    /**
     * Parse a manifest.json file from disk.
     *
     * @throws \RuntimeException If the file does not exist or contains invalid JSON
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Manifest file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read manifest file: {$path}");
        }

        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Manifest must decode to a JSON object: {$path}");
        }

        return new self($data, $path);
    }

    /**
     * Construct from an already-decoded array (e.g. during ZIP inspection).
     */
    public static function fromArray(array $data, string $sourcePath = ''): self
    {
        return new self($data, $sourcePath);
    }

    // ── Validation ──────────────────────────────────────────────────

    /**
     * Validate the manifest and return a list of human-readable errors.
     *
     * An empty return means the manifest is valid.
     *
     * @return list<string> Validation error messages
     */
    public function validate(): array
    {
        $errors = [];

        // Required fields
        if ($this->name === '') {
            $errors[] = 'Missing required field: "name"';
        }
        if ($this->slug === '') {
            $errors[] = 'Missing required field: "slug"';
        }
        if ($this->version === '' || $this->version === '0.0.0') {
            $errors[] = 'Missing or invalid field: "version" (must be a valid SemVer string)';
        }
        if ($this->entrypoint === '') {
            $errors[] = 'Missing required field: "entrypoint"';
        }

        // Slug format
        if ($this->slug !== '' && !preg_match(self::SLUG_PATTERN, $this->slug)) {
            $errors[] = "Invalid slug \"{$this->slug}\": must be 2-60 chars, lowercase alphanumeric + hyphens, no leading/trailing hyphens";
        }

        // Type
        if (!in_array($this->type, self::VALID_TYPES, true)) {
            $errors[] = "Invalid type \"{$this->type}\": must be one of " . implode(', ', self::VALID_TYPES);
        }

        // Capabilities
        $invalidCaps = Capability::validateAll($this->capabilities);
        if ($invalidCaps !== []) {
            $errors[] = 'Unknown capabilities: ' . implode(', ', $invalidCaps);
        }

        // PHP version
        if ($this->minPhp !== '' && version_compare(PHP_VERSION, $this->minPhp, '<')) {
            $errors[] = "Requires PHP >= {$this->minPhp}, current is " . PHP_VERSION;
        }

        // Entrypoint safety: must not contain path separators
        if (str_contains($this->entrypoint, '/') || str_contains($this->entrypoint, '\\') || str_contains($this->entrypoint, '..')) {
            $errors[] = "Entrypoint \"{$this->entrypoint}\" must be a plain filename, not a path";
        }

        // Cron entries
        foreach ($this->cron as $i => $job) {
            if ($job['name'] === '') {
                $errors[] = "Cron entry #{$i}: missing \"name\"";
            }
            if ($job['schedule'] === '') {
                $errors[] = "Cron entry #{$i}: missing \"schedule\"";
            }
        }

        // Admin menu entries
        foreach ($this->adminMenu as $i => $menu) {
            if ($menu['title'] === '') {
                $errors[] = "Admin menu entry #{$i}: missing \"title\"";
            }
            if ($menu['slug'] === '') {
                $errors[] = "Admin menu entry #{$i}: missing \"slug\"";
            }
        }

        // Migration paths: must not contain traversal
        foreach ($this->migrations as $i => $path) {
            if (str_contains($path, '..')) {
                $errors[] = "Migration #{$i}: path traversal detected in \"{$path}\"";
            }
        }

        return $errors;
    }

    // ── Capability helpers ──────────────────────────────────────────

    /**
     * Check if the plugin declares a specific capability.
     */
    public function hasCapability(Capability $cap): bool
    {
        return in_array($cap->value, $this->capabilities, true);
    }

    /**
     * Get all declared capabilities as enum cases.
     *
     * @return list<Capability>
     */
    public function getCapabilities(): array
    {
        $caps = [];
        foreach ($this->capabilities as $capStr) {
            $cap = Capability::tryFrom($capStr);
            if ($cap !== null) {
                $caps[] = $cap;
            }
        }
        return $caps;
    }

    // ── Derived helpers ─────────────────────────────────────────────

    /**
     * Get the expected fully-qualified class name for the plugin's entrypoint.
     *
     * Convention: OwnPayPlugin\{Namespace}\Plugin (or \Gateway, \Theme)
     */
    public function getFullyQualifiedClassName(): string
    {
        if ($this->namespace === '') {
            // Derive from slug: "sms-notifications" → "SmsNotifications"
            $ns = str_replace(' ', '', ucwords(str_replace('-', ' ', $this->slug)));
        } else {
            $ns = $this->namespace;
        }

        // Derive class name from entrypoint filename: "Plugin.php" → "Plugin"
        $className = pathinfo($this->entrypoint, PATHINFO_FILENAME);

        return "OwnPayPlugin\\{$ns}\\{$className}";
    }

    /**
     * Compute the SHA-256 hash of the raw manifest file contents.
     */
    public function computeHash(): string
    {
        if ($this->sourcePath === '' || !is_file($this->sourcePath)) {
            return hash('sha256', json_encode($this->toArray()));
        }
        return hash_file('sha256', $this->sourcePath);
    }

    /**
     * Serialize the manifest back to a plain array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'slug'         => $this->slug,
            'version'      => $this->version,
            'type'         => $this->type,
            'description'  => $this->description,
            'author'       => $this->author,
            'author_url'   => $this->authorUrl,
            'license'      => $this->license,
            'entrypoint'   => $this->entrypoint,
            'namespace'    => $this->namespace,
            'min_php'      => $this->minPhp,
            'min_app'      => $this->minApp,
            'capabilities' => $this->capabilities,
            'dependencies' => $this->dependencies,
            'hooks'        => $this->hooks,
            'admin_menu'   => $this->adminMenu,
            'cron'         => $this->cron,
            'migrations'   => $this->migrations,
        ];
    }
}
