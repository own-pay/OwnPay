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
                'initials' => 'FN',
                'name'     => 'Fattain Naime',
                'role'     => 'Founder & Lead Developer',
                'commits'  => 542
            ],
            [
                'initials' => 'TH',
                'name'     => 'Tahira Akter Hira',
                'role'     => 'Logo & Brand Design',
                'commits'  => 1
            ],
            [
                'initials' => 'AI',
                'name'     => 'M Azmain Israq',
                'role'     => 'UI/UX Designer',
                'commits'  => 45
            ],
            [
                'initials' => 'HI',
                'name'     => 'Hamidullah Ismail',
                'role'     => 'Features & Reviewer',
                'commits'  => 2
            ]
        ];

        return $this->renderAdminPage('admin/contributors.twig', [
            'contributors' => $contributors,
            'active_page'  => 'contributors'
        ]);
    }
}
