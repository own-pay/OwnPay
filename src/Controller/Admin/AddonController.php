<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Plugin\PluginManager;
use OwnPay\Repository\PluginRepository;

/**
 * Controller orchestrating administrative management of system addon plugins.
 */
final class AddonController
{
    use AdminPageTrait;

    private Container $c;

    /**
     * Session wrapper service for authenticated administrative operations.
     *
     * @var AdminSession
     */
    private AdminSession $session;

    /**
     * Manager facilitating plugin lifecycles, sandbox setups, and activations.
     *
     * @var PluginManager
     */
    /** @phpstan-ignore property.onlyWritten */
    private PluginManager $manager;

    /**
     * Database repository for installed plugins.
     *
     * @var PluginRepository
     */
    private PluginRepository $repo;

    /**
     * Initialises the AddonController.
     *
     * @param Container $c Dependency injection container instance.
     * @param AdminSession $session Active admin session service.
     * @param PluginManager $manager Plugin manager service.
     * @param PluginRepository $repo Plugin database repository.
     */
    public function __construct(Container $c, AdminSession $session, PluginManager $manager, PluginRepository $repo)
    {
        $this->c = $c;
        $this->session = $session;
        $this->manager = $manager;
        $this->repo = $repo;
    }

    /**
     * Displays a list of installed and discovered addon plugins.
     *
     * Enriches plugin database records with local manifest details read directly
     * from the filesystem discovery pipeline.
     *
     * @param Request $request Outbound HTTP request instance.
     * @return Response HTTP response wrapper.
     */
    public function index(Request $request): Response
    {
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        $addons = $this->repo->listByType('addon');

        // Enrich database plugin models with manifest parameters
        foreach ($addons as &$addon) {
            $manifestStr = isset($addon['manifest']) && is_string($addon['manifest']) ? $addon['manifest'] : '{}';
            $m = json_decode($manifestStr, true);
            if (!is_array($m)) {
                $m = [];
            }
            $mName = $m['name'] ?? null;
            if (is_string($mName) && $mName !== '') {
                $addon['name'] = $mName; // Prefer manifest name over database slug representation
            }
            $mDesc = $m['description'] ?? '';
            $addon['description'] = $addon['description'] ?? (is_string($mDesc) ? $mDesc : '');
            $mAuthor = $m['author'] ?? 'Unknown';
            $addon['author']      = $addon['author']      ?? (is_string($mAuthor) ? $mAuthor : 'Unknown');

            $addonSlug = isset($addon['slug']) && is_string($addon['slug']) ? $addon['slug'] : '';
            // Local active/inactive status override if brand context is active
            if ($brandId !== null && $brandId > 0 && !in_array($addon['status'], ['uninstalled', 'trashed'], true)) {
                $addon['status'] = $this->repo->isPluginActiveForBrand($addonSlug, $brandId) ? 'active' : 'inactive';
            }
        }
        unset($addon);

        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        if (!$loader instanceof \OwnPay\Plugin\PluginLoader) {
            throw new \RuntimeException('PluginLoader service unavailable');
        }
        $discovered = $loader->discover();

        // Map discovered filesystem manifests to active lookup table
        $manifestLookup = [];
        foreach ($discovered as $manifest) {
            if ($manifest->type === 'addon') {
                $manifestLookup[$manifest->slug] = $manifest;
            }
        }

        // Align DB stored metadata with latest filesystem configurations
        foreach ($addons as &$addon) {
            $addonSlug = isset($addon['slug']) && is_string($addon['slug']) ? $addon['slug'] : '';
            if (isset($manifestLookup[$addonSlug])) {
                $fsManifest = $manifestLookup[$addonSlug];
                $addon['name']        = $fsManifest->name;
                $addon['description'] = $addon['description'] ?: ($fsManifest->description ?? '');
                $addon['author']      = $addon['author']      ?: ($fsManifest->author      ?? 'Unknown');
            }
        }
        unset($addon);

        // Include uninstalled addons present on filesystem
        foreach ($manifestLookup as $slug => $manifest) {
            $found = false;
            foreach ($addons as $p) {
                if ($p['slug'] === $slug) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $addons[] = [
                    'slug'        => $manifest->slug,
                    'name'        => $manifest->name,
                    'description' => $manifest->description,
                    'version'     => $manifest->version,
                    'status'      => 'uninstalled',
                    'author'      => $manifest->author,
                    'logo_path'   => null,
                ];
            }
        }

        return $this->renderAdminPage('admin/addons/index.twig', [
            'addons'      => $addons,
            'active_page' => 'addons',
        ]);
    }
}
