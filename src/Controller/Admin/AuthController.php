<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Auth\AuthSessionService;
use OwnPay\Repository\MerchantUserRepository;

/**
 * Auth controller — login, logout, forgot password, 2FA.
 * Fires: auth.login.attempt, auth.login.success, auth.login.failed, auth.logout
 */
final class AuthController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private AuthSessionService $auth;
    private EventManager $events;
    private MerchantUserRepository $userRepo;

    public function __construct(
        Container $c,
        AdminSession $session,
        AuthSessionService $auth,
        EventManager $events,
        MerchantUserRepository $userRepo
    ) {
        $this->c        = $c;
        $this->session  = $session;
        $this->auth     = $auth;
        $this->events   = $events;
        $this->userRepo = $userRepo;
    }

    public function loginForm(Request $req): Response
    {
        return $this->renderAdminPage('page/login.twig', [
            'login_url' => $this->c->get('config.app')['login_url'] ?? '/login',
            'error'     => null,
            'old_email' => null,
        ]);
    }

    public function login(Request $req): Response
    {
        $email    = $req->post('email', '');
        $password = $req->post('password', '');

        $this->events->doAction('auth.login.attempt', ['email' => $email, 'ip' => $req->ip()]);

        $result = $this->auth->login($email, $password, $req->ip(), $req->userAgent());
        if (!$result['success']) {
            return $this->renderAdminPage('page/login.twig', [
                'error'     => $result['error'] ?? 'Invalid credentials',
                'old_email' => $email,
            ]);
        }

        // Check 2FA
        if (!empty($result['requires_2fa'])) {
            $this->session->set('2fa_user_id', $result['user']['id']);
            return Response::redirect('/2fa');
        }

        return Response::redirect('/admin');
    }

    public function twoFactorForm(Request $req): Response
    {
        if ($this->session->get('2fa_user_id') === null) {
            return Response::redirect('/login');
        }
        return $this->renderAdminPage('page/2fa.twig');
    }

    public function twoFactorVerify(Request $req): Response
    {
        $userId = $this->session->get('2fa_user_id');
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $code = preg_replace('/\D/', '', $req->post('code', ''));
        $user = $this->userRepo->findActiveByEmail(
            $this->userRepo->findById((int) $userId)['email'] ?? ''
        );

        if (!$user || empty($user['totp_secret_enc'])) {
            return $this->renderAdminPage('page/2fa.twig', ['error' => '2FA is not properly configured on this account.']);
        }

        if (!\OwnPay\Middleware\TwoFactorMiddleware::verifyTotp($user['totp_secret_enc'], $code)) {
            return $this->renderAdminPage('page/2fa.twig', ['error' => 'Invalid or expired 2FA code. Please try again.']);
        }

        // Full session bootstrap via Authenticator
        $authenticator = $this->c->get(\OwnPay\Security\Authenticator::class);
        $authenticator->startSession($user);
        $this->userRepo->updateLastLogin((int) $user['id'], $req->ip());

        // 2FA-specific session flags
        $_SESSION['two_fa_enabled'] = true;
        $_SESSION['2fa_verified'] = true;
        unset($_SESSION['2fa_user_id']);

        $this->events->doAction('auth.login.success', $user);

        return Response::redirect('/admin');
    }

    public function forgotForm(Request $req): Response
    {
        return $this->renderAdminPage('page/forgot.twig');
    }

    public function forgotSubmit(Request $req): Response
    {
        $email = trim($req->post('email', ''));

        if ($email === '') {
            return $this->renderAdminPage('page/forgot.twig', [
                'error' => 'Please enter your email address.',
            ]);
        }

        // Always show success to prevent email enumeration (OWASP best practice)
        $this->events->doAction('auth.forgot_password', ['email' => $email]);

        return $this->renderAdminPage('page/forgot.twig', [
            'success' => 'If that email exists in our system, a password reset link has been sent.',
        ]);
    }

    public function logout(Request $req): Response
    {
        $this->events->doAction('auth.logout', $this->session->currentUser());
        $this->auth->logout();
        return Response::redirect('/login');
    }
}
