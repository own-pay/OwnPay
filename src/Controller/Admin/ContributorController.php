<?php

declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Class ContributorController
 *
 * Renders the static list of contributors for the project.
 *
 * @package OwnPay\Controller\Admin
 */
final class ContributorController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * ContributorController constructor.
     *
     * @param Container    $c       The dependency injection container.
     * @param AdminSession $session The administrative session service.
     */
    public function __construct(Container $c, AdminSession $session)
    {
        $this->c = $c;
        $this->session = $session;
    }

    /**
     * Renders the contributors page.
     *
     * @param Request $req The incoming request.
     * @return Response The rendered page response.
     */
    public function index(Request $req): Response
    {
        $contributors = [
            [
                'initials'   => 'FN',
                'name'       => 'Fattain Naime',
                'role'       => 'Creator of OwnPay',
                'commits'    => 542,
                'avatar_url' => 'https://github.com/fattain-naime.png',
                'github_url' => 'https://github.com/fattain-naime'
            ]
        ];

        return $this->renderAdminPage('admin/contributors.twig', [
            'contributors' => $contributors,
            'active_page'  => 'contributors'
        ]);
    }
}
