<?php

declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\Request;
use OwnPay\Http\RequestContext;
use OwnPay\Plugin\PluginInstaller;
use OwnPay\Plugin\PluginLoader;
use OwnPay\Plugin\PluginManifest;
use OwnPay\Plugin\PluginMigrator;
use OwnPay\Service\System\EnvironmentService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\Auth\PermissionGuard;

/**
 * Admin controller for plugin management.
 *
 * Actions:
 *   plugins-list        — List all installed plugins with pagination
 *   plugins-install     — Upload and install a plugin ZIP
 *   plugins-activate    — Activate an installed plugin
 *   plugins-deactivate  — Deactivate an active plugin
 *   plugins-delete      — Uninstall and remove a plugin
 *   plugins-settings    — Get/save plugin settings fields
 *   plugins-scan        — Scan filesystem for unregistered plugins
 */
class PluginController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $controller = new self();

        switch ($action) {
            case 'plugins-list':
                $controller->listPlugins($ctx);
                break;
            case 'plugins-install':
                $controller->installPlugin($ctx);
                break;
            case 'plugins-activate':
                $controller->activatePlugin($ctx);
                break;
            case 'plugins-deactivate':
                $controller->deactivatePlugin($ctx);
                break;
            case 'plugins-delete':
                $controller->deletePlugin($ctx);
                break;
            case 'plugins-settings-get':
                $controller->getPluginSettings($ctx);
                break;
            case 'plugins-settings-save':
                $controller->savePluginSettings($ctx);
                break;
            case 'plugins-scan':
                $controller->scanPlugins($ctx);
                break;
        }
    }

    // ── List ────────────────────────────────────────────────────────

    private function listPlugins(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'view')) {
            return;
        }

        $request = Request::createFromGlobals();
        $search = InputSanitizer::trim($request->post('search_input', ''));
        $limit = max(1, min(100, (int) $request->post('show_limit', '10')));
        $page = max(1, (int) $request->post('page', '1'));
        $filterType = InputSanitizer::trim($request->post('filter_type', ''));
        $filterStatus = InputSanitizer::trim($request->post('filter_status', ''));

        $prefix = $ctx->dbPrefix;
        $db = \OwnPay\Core\Database::getInstance();

        // Build query
        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= " AND (name LIKE :search OR slug LIKE :search2)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
        }

        if ($filterType !== '' && in_array($filterType, ['plugin', 'gateway', 'theme'], true)) {
            $where .= " AND type = :type";
            $params['type'] = $filterType;
        }

        if ($filterStatus !== '' && in_array($filterStatus, ['installed', 'active', 'inactive'], true)) {
            $where .= " AND status = :status";
            $params['status'] = $filterStatus;
        }

        // Count
        $total = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM `{$prefix}plugins` WHERE {$where}",
            $params,
        );

        // Paginate
        $offset = ($page - 1) * $limit;
        $rows = $db->fetchAll(
            "SELECT id, slug, name, type, version, status, capabilities, load_order, activated_at, installed_at, updated_at
               FROM `{$prefix}plugins`
              WHERE {$where}
              ORDER BY load_order ASC, name ASC
              LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        $totalPages = (int) ceil($total / $limit);

        echo json_encode([
            'status'     => 'true',
            'response'   => $rows,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => $totalPages,
            ],
            'csrf_token' => $token,
        ]);
    }

    // ── Install ─────────────────────────────────────────────────────

    private function installPlugin(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'create')) {
            return;
        }

        if (!isset($_FILES['plugin_file'])) {
            echo json_encode(['status' => 'false', 'title' => 'Upload Failed', 'message' => 'No file was uploaded.', 'csrf_token' => $token]);
            return;
        }

        $result = PluginInstaller::installFromUpload($_FILES['plugin_file']);

        echo json_encode([
            'status'     => $result['success'] ? 'true' : 'false',
            'title'      => $result['success'] ? 'Plugin Installed' : 'Installation Failed',
            'message'    => $result['message'],
            'slug'       => $result['slug'] ?? null,
            'csrf_token' => $token,
        ]);
    }

    // ── Activate ────────────────────────────────────────────────────

    private function activatePlugin(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'edit')) {
            return;
        }

        $request = Request::createFromGlobals();
        $slug = InputSanitizer::trim($request->post('slug', ''));

        if ($slug === '') {
            echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Plugin slug is required.', 'csrf_token' => $token]);
            return;
        }

        // Run migrations first
        $migrationResult = PluginMigrator::migrate($slug);
        if (!$migrationResult['success']) {
            echo json_encode(['status' => 'false', 'title' => 'Migration Failed', 'message' => $migrationResult['message'], 'csrf_token' => $token]);
            return;
        }

        // Activate
        $result = PluginLoader::activatePlugin($slug);

        $message = $result['message'];
        if ($migrationResult['applied'] > 0) {
            $message .= " ({$migrationResult['applied']} migration(s) applied)";
        }

        echo json_encode([
            'status'     => $result['success'] ? 'true' : 'false',
            'title'      => $result['success'] ? 'Plugin Activated' : 'Activation Failed',
            'message'    => $message,
            'csrf_token' => $token,
        ]);
    }

    // ── Deactivate ──────────────────────────────────────────────────

    private function deactivatePlugin(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'edit')) {
            return;
        }

        $request = Request::createFromGlobals();
        $slug = InputSanitizer::trim($request->post('slug', ''));

        if ($slug === '') {
            echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Plugin slug is required.', 'csrf_token' => $token]);
            return;
        }

        $result = PluginLoader::deactivatePlugin($slug);

        echo json_encode([
            'status'     => $result['success'] ? 'true' : 'false',
            'title'      => $result['success'] ? 'Plugin Deactivated' : 'Deactivation Failed',
            'message'    => $result['message'],
            'csrf_token' => $token,
        ]);
    }

    // ── Delete ──────────────────────────────────────────────────────

    private function deletePlugin(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'delete')) {
            return;
        }

        $request = Request::createFromGlobals();
        $slug = InputSanitizer::trim($request->post('slug', ''));

        if ($slug === '') {
            echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Plugin slug is required.', 'csrf_token' => $token]);
            return;
        }

        // Rollback all migrations
        PluginMigrator::rollbackAll($slug);

        // Uninstall (calls deactivate + uninstall + removes DB record)
        $result = PluginLoader::uninstallPlugin($slug);

        // Remove files
        if ($result['success']) {
            PluginInstaller::removeFiles($slug);
        }

        echo json_encode([
            'status'     => $result['success'] ? 'true' : 'false',
            'title'      => $result['success'] ? 'Plugin Deleted' : 'Delete Failed',
            'message'    => $result['message'],
            'csrf_token' => $token,
        ]);
    }

    // ── Settings (Get) ──────────────────────────────────────────────

    private function getPluginSettings(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'view')) {
            return;
        }

        $request = Request::createFromGlobals();
        $slug = InputSanitizer::trim($request->post('slug', ''));

        if ($slug === '') {
            echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Plugin slug is required.', 'csrf_token' => $token]);
            return;
        }

        // Load plugin info from manifest
        $root = dirname(__DIR__, 2);
        $manifest = $this->findManifest($root, $slug);
        if ($manifest === null) {
            echo json_encode(['status' => 'false', 'title' => 'Not Found', 'message' => "Plugin '{$slug}' not found.", 'csrf_token' => $token]);
            return;
        }

        // Get fields from the plugin class
        $fields = [];
        $entrypoint = $root . '/' . $this->typeDir($manifest->type) . '/' . $slug . '/' . $manifest->entrypoint;
        if (is_file($entrypoint)) {
            require_once $entrypoint;
            $className = $manifest->getFullyQualifiedClassName();
            if (class_exists($className)) {
                $plugin = new $className();
                if (method_exists($plugin, 'fields')) {
                    $fields = $plugin->fields();
                }
            }
        }

        // Load current values from op_env
        foreach ($fields as &$field) {
            $envKey = $slug . '-' . $field['name'];
            $field['value'] = $field['value'] ?? EnvironmentService::get($envKey, 'both') ?: '';
        }
        unset($field);

        echo json_encode([
            'status'     => 'true',
            'info'       => $manifest->toArray(),
            'fields'     => $fields,
            'csrf_token' => $token,
        ]);
    }

    // ── Settings (Save) ─────────────────────────────────────────────

    private function savePluginSettings(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'edit')) {
            return;
        }

        $request = Request::createFromGlobals();
        $slug = InputSanitizer::trim($request->post('slug', ''));

        if ($slug === '') {
            echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Plugin slug is required.', 'csrf_token' => $token]);
            return;
        }

        // Load manifest to get field definitions
        $root = dirname(__DIR__, 2);
        $manifest = $this->findManifest($root, $slug);
        if ($manifest === null) {
            echo json_encode(['status' => 'false', 'title' => 'Not Found', 'message' => "Plugin '{$slug}' not found.", 'csrf_token' => $token]);
            return;
        }

        // Get field definitions
        $fields = [];
        $entrypoint = $root . '/' . $this->typeDir($manifest->type) . '/' . $slug . '/' . $manifest->entrypoint;
        if (is_file($entrypoint)) {
            require_once $entrypoint;
            $className = $manifest->getFullyQualifiedClassName();
            if (class_exists($className)) {
                $plugin = new $className();
                if (method_exists($plugin, 'fields')) {
                    $fields = $plugin->fields();
                }
            }
        }

        // Save each field value using set_env
        $prefix = $ctx->dbPrefix;
        $brandId = $ctx->brandResponse['response'][0]['brand_id'] ?? '';
        $saved = 0;

        foreach ($fields as $field) {
            $fieldName = $field['name'] ?? '';
            if ($fieldName === '') {
                continue;
            }

            $envKey = $slug . '-' . $fieldName;
            $value = InputSanitizer::trim($request->post("field_{$fieldName}", ''));

            EnvironmentService::set($envKey, $value);
            $saved++;
        }

        echo json_encode([
            'status'     => 'true',
            'title'      => 'Settings Saved',
            'message'    => "{$saved} setting(s) updated for '{$manifest->name}'.",
            'csrf_token' => $token,
        ]);
    }

    // ── Scan filesystem ─────────────────────────────────────────────

    private function scanPlugins(RequestContext $ctx): void
    {
        $token = $ctx->csrfToken;

        if (!$ctx->isLoggedIn) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'Please login.', 'csrf_token' => $token]);
            return;
        }

        if (!$this->checkPermission($ctx, 'plugins', 'create')) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $prefix = $ctx->dbPrefix;
        $db = \OwnPay\Core\Database::getInstance();
        $typeDirs = [
            'plugin'  => 'app/modules/plugins',
            'gateway' => 'app/modules/gateways',
            'theme'   => 'app/modules/themes',
        ];

        $discovered = [];
        $registered = 0;

        foreach ($typeDirs as $type => $dir) {
            $fullDir = $root . '/' . $dir;
            if (!is_dir($fullDir)) {
                continue;
            }

            $entries = scandir($fullDir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $manifestPath = $fullDir . '/' . $entry . '/manifest.json';
                if (!is_file($manifestPath)) {
                    continue;
                }

                try {
                    $manifest = PluginManifest::fromFile($manifestPath);
                } catch (\Throwable) {
                    continue;
                }

                // Check if already in DB
                $existing = $db->fetchOne(
                    "SELECT id FROM `{$prefix}plugins` WHERE slug = :slug",
                    ['slug' => $manifest->slug],
                );

                if ($existing === null) {
                    // Register it
                    $db->execute(
                        "INSERT INTO `{$prefix}plugins`
                            (slug, name, type, version, status, entrypoint, capabilities, manifest_hash, load_order, installed_at)
                         VALUES
                            (:slug, :name, :type, :version, 'installed', :entrypoint, :capabilities, :hash, 100, NOW())",
                        [
                            'slug'         => $manifest->slug,
                            'name'         => $manifest->name,
                            'type'         => $manifest->type,
                            'version'      => $manifest->version,
                            'entrypoint'   => $manifest->entrypoint,
                            'capabilities' => json_encode($manifest->capabilities),
                            'hash'         => $manifest->computeHash(),
                        ],
                    );
                    $registered++;
                }

                $discovered[] = $manifest->slug;
            }
        }

        echo json_encode([
            'status'     => 'true',
            'title'      => 'Scan Complete',
            'message'    => count($discovered) . " plugin(s) found, {$registered} newly registered.",
            'discovered' => $discovered,
            'csrf_token' => $token,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function checkPermission(RequestContext $ctx, string $module, string $action): bool
    {
        $token = $ctx->csrfToken;

        if (!PermissionGuard::canAccess($ctx, $module)) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action.', 'csrf_token' => $token]);
            return false;
        }

        if (!PermissionGuard::has($ctx, $module, $action)) {
            echo json_encode(['status' => 'false', 'title' => 'Access denied', 'message' => 'You need permission to perform this action.', 'csrf_token' => $token]);
            return false;
        }

        return true;
    }

    private function findManifest(string $root, string $slug): ?PluginManifest
    {
        $typeDirs = [
            'plugin'  => 'app/modules/plugins',
            'gateway' => 'app/modules/gateways',
            'theme'   => 'app/modules/themes',
        ];

        foreach ($typeDirs as $type => $dir) {
            $path = $root . '/' . $dir . '/' . $slug . '/manifest.json';
            if (is_file($path)) {
                try {
                    return PluginManifest::fromFile($path);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        return null;
    }

    private function typeDir(string $type): string
    {
        return match ($type) {
            'gateway' => 'app/modules/gateways',
            'theme'   => 'app/modules/themes',
            default   => 'app/modules/plugins',
        };
    }
}
