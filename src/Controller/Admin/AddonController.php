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
 * Addon admin controller â€” filtered view of addon-type plugins.
 */
final class AddonController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private PluginManager $manager;
    private PluginRepository $repo;

    public function __construct(Container $c, AdminSession $session, PluginManager $manager, PluginRepository $repo)
    {
        $this->c = $c;
        $this->session = $session;
        $this->manager = $manager;
        $this->repo = $repo;
    }

    public function index(Request $request): Response
    {
        $addons = $this->repo->listByType('addon');

        // Enrich DB rows with manifest data
        foreach ($addons as &$addon) {
            $m = json_decode($addon['manifest'] ?? '{}', true) ?: [];
            $addon['description'] = $addon['description'] ?? $m['description'] ?? '';
            $addon['author'] = $addon['author'] ?? $m['author'] ?? 'Unknown';
        }
        unset($addon);
        /** @var \OwnPay\Plugin\PluginLoader $loader */
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        $discovered = $loader->discover();
        foreach ($discovered as $manifest) {
            if ($manifest->type === 'addon') {
                $found = false;
                foreach ($addons as $p) {
                    if ($p['slug'] === $manifest->slug) {
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
        }

        return $this->renderAdminPage('admin/addons/index.twig', [
            'addons'      => $addons,
            'active_page' => 'addons',
        ]);
    }
}
