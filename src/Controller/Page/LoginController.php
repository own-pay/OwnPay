<?php
declare(strict_types=1);

namespace OwnPay\Controller\Page;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Class LoginController
 *
 * Handles HTTP requests for the administrative login page and submission flows.
 * Delegates actual form rendering and authentication handling to AuthController.
 *
 * @package OwnPay\Controller\Page
 */
final class LoginController
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * LoginController constructor.
     *
     * @param Container $c The DI container.
     */
    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    /**
     * Renders the administrative login form.
     *
     * GET /login
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with the login form.
     */
    public function show(Request $req): Response
    {
        $auth = $this->c->get(\OwnPay\Controller\Admin\AuthController::class);
        return $auth->loginForm($req);
    }

    /**
     * Handles submission of the login credentials.
     *
     * POST /login
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response indicating authentication status or redirect.
     */
    public function submit(Request $req): Response
    {
        $auth = $this->c->get(\OwnPay\Controller\Admin\AuthController::class);
        return $auth->login($req);
    }
}
