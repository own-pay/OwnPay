<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Service\Auth\AuthService;
use OwnPay\Service\Auth\TwoFactorService;

/**
 * Auth controller — login, logout, forgot password, 2FA.
 * Fires: auth.login.attempt, auth.login.success, auth.login.failed, auth.logout
 */
final class AuthController
{
    private Container $c;
    private AuthService $auth;
    private EventManager $events;

    public function __construct(Container $c, AuthService $auth, EventManager $events)
    {
        $this->c = $c;
        $this->auth = $auth;
        $this->events = $events;
    }

    public function loginForm(Request $req): Response
    {
        return $this->render('page/login.twig', [
            'login_url' => $this->c->get('config.app')['login_url'] ?? '/login',
        ]);
    }

    public function login(Request $req): Response
    {
        $email = $req->post('email', '');
        $password = $req->post('password', '');

        $this->events->doAction('auth.login.attempt', ['email' => $email, 'ip' => $req->ip()]);

        $user = $this->auth->attempt($email, $password);
        if ($user === null) {
            $this->events->doAction('auth.login.failed', ['email' => $email, 'ip' => $req->ip()]);
            return $this->render('page/login.twig', [
                'error' => 'Invalid credentials',
                'old_email' => $email,
            ]);
        }

        // Check 2FA
        if (!empty($user['two_fa_secret'])) {
            $_SESSION['2fa_user_id'] = $user['id'];
            return Response::redirect('/2fa');
        }

        $this->auth->loginUser($user);
        $this->events->doAction('auth.login.success', $user);

        return Response::redirect('/admin');
    }

    public function twoFactorForm(Request $req): Response
    {
        if (empty($_SESSION['2fa_user_id'])) {
            return Response::redirect('/login');
        }
        return $this->render('page/2fa.twig');
    }

    public function twoFactorVerify(Request $req): Response
    {
        $userId = $_SESSION['2fa_user_id'] ?? null;
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $code = $req->post('code', '');
        /** @var TwoFactorService $tfa */
        $tfa = $this->c->get(TwoFactorService::class);
        $user = $this->auth->findById((int) $userId);

        if ($user === null || !$tfa->verify($user['two_fa_secret'], $code)) {
            return $this->render('page/2fa.twig', ['error' => 'Invalid code']);
        }

        unset($_SESSION['2fa_user_id']);
        $this->auth->loginUser($user);
        $this->events->doAction('auth.login.success', $user);

        return Response::redirect('/admin');
    }

    public function forgotForm(Request $req): Response
    {
        return $this->render('page/forgot.twig');
    }

    public function forgotSubmit(Request $req): Response
    {
        $email = $req->post('email', '');
        $this->auth->sendPasswordReset($email);
        return $this->render('page/forgot.twig', [
            'success' => 'If that email exists, a reset link has been sent.',
        ]);
    }

    public function logout(Request $req): Response
    {
        $this->events->doAction('auth.logout', $_SESSION['user'] ?? []);
        $this->auth->logout();
        return Response::redirect('/login');
    }

    private function render(string $tpl, array $data = []): Response
    {
        $twig = $this->c->get(\Twig\Environment::class);
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        $data['app_name'] = $this->c->get('config.app')['name'] ?? 'Own Pay';
        return Response::html($twig->render($tpl, $data));
    }
}
