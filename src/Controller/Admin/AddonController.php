<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\PluginManager;
use OwnPay\Repository\PluginRepository;

/**
 * Addon admin controller — filtered view of addon-type plugins.
 */
final class AddonController
{
    private Container $container;
    private PluginManager $manager;
    private PluginRepository $repo;

    public function __construct(Container $container, PluginManager $manager, PluginRepository $repo)
    {
        $this->container = $container;
        $this->manager = $manager;
        $this->repo = $repo;
    }

    public function index(Request $request): Response
    {
        $addons = $this->repo->listByType('addon');

        /** @var \Twig\Environment $twig */
        $twig = $this->container->get(\Twig\Environment::class);
        $data = [
            'addons' => $addons,
            'app_name' => $this->container->get('config.app')['name'] ?? 'Own Pay',
            'flash_success' => $_SESSION['flash_success'] ?? null,
            'flash_error' => $_SESSION['flash_error'] ?? null,
        ];
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return Response::html($twig->render('admin/addons/index.twig', $data));
    }
}
