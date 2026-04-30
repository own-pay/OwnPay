<?php
declare(strict_types=1);

namespace OwnPay\Controller\Page;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Login page controller — delegates to AuthController for POST.
 * This handles custom login URL routing.
 */
final class LoginController
{
    private Container $c;

    public function __construct(Container $c) { $this->c = $c; }

    public function show(Request $req): Response
    {
        $auth = $this->c->get(\OwnPay\Controller\Admin\AuthController::class);
        return $auth->loginForm($req);
    }

    public function submit(Request $req): Response
    {
        $auth = $this->c->get(\OwnPay\Controller\Admin\AuthController::class);
        return $auth->login($req);
    }
}
