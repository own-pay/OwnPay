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

    /**
     * Dependency injection container.
     *
     * @var Container
     */
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
     * @phpstan-ignore property.onlyWritten
     */
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
        $addons = $this->repo->listByType('addon');

        // Enrich database plugin models with manifest parameters
        foreach ($addons as &$addon) {
            $m = json_decode($addon['manifest'] ?? '{}', true) ?: [];
            if (!empty($m['name'])) {
                $addon['name'] = $m['name']; // Prefer manifest name over database slug representation
            }
            $addon['description'] = $addon['description'] ?? $m['description'] ?? '';
            $addon['author']      = $addon['author']      ?? $m['author']      ?? 'Unknown';
        }
        unset($addon);

        /** @var \OwnPay\Plugin\PluginLoader $loader */
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
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
            if (isset($manifestLookup[$addon['slug']])) {
                $fsManifest = $manifestLookup[$addon['slug']];
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
