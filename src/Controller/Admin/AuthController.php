<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Event\EventManager;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Auth\AuthSessionService;
use OwnPay\Service\System\AuditService;
use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Repository\SettingsRepository;

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
    private AuditService $audit;
    private SettingsRepository $settings;

    public function __construct(
        Container $c,
        AdminSession $session,
        AuthSessionService $auth,
        EventManager $events,
        MerchantUserRepository $userRepo,
        AuditService $audit,
        SettingsRepository $settings
    ) {
        $this->c        = $c;
        $this->session  = $session;
        $this->auth     = $auth;
        $this->events   = $events;
        $this->userRepo = $userRepo;
        $this->audit    = $audit;
        $this->settings = $settings;
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
            $this->audit->log('login.failed', 'user', null, null, ['email' => $email]);
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

        $this->audit->log('login.success', 'user', (int) $result['user']['id']);
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
        $this->audit->log('login.2fa_verified', 'user', (int) $user['id']);

        return Response::redirect('/admin');
    }

    public function forgotForm(Request $req): Response
    {
        // AUD-03 FIX: OwnPay is single-owner system.
        // Password resets are done by superadmin, not self-service email.
        $supportEmail = $this->settings->get('general', 'support_email', '');
        return $this->renderAdminPage('page/forgot.twig', [
            'support_email' => $supportEmail,
        ]);
    }

    public function forgotSubmit(Request $req): Response
    {
        $email = trim($req->post('email', ''));

        if ($email === '') {
            return $this->renderAdminPage('page/forgot.twig', [
                'error' => 'Please enter your email address.',
            ]);
        }

        // AUD-03 FIX: Log the request and show honest message.
        // In a single-owner system, the admin resets passwords manually.
        $this->audit->log('password_reset.requested', 'user', null, null, ['email' => $email]);
        $this->events->doAction('auth.forgot_password', ['email' => $email]);

        return $this->renderAdminPage('page/forgot.twig', [
            'success' => 'Your password reset request has been logged. Please contact your system administrator to reset your password.',
        ]);
    }

    public function logout(Request $req): Response
    {
        $this->audit->log('logout', 'user', $_SESSION['auth_user_id'] ?? null);
        $this->events->doAction('auth.logout', $this->session->currentUser());
        $this->auth->logout();

        // AUD-07 FIX: Resolve dynamic login slug instead of hardcoded /login
        $loginSlug = 'login';
        try {
            $slug = $this->settings->get('landing', 'admin_login_slug', 'login');
            if (!empty($slug) && preg_match('/^[a-z0-9\-]+$/', $slug)) {
                $loginSlug = $slug;
            }
        } catch (\Throwable) {}
        return Response::redirect('/' . $loginSlug);
    }
}
