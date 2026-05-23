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
 * Class AuthController
 *
 * Coordinates administrative authentication flows including standard logins, multi-factor (2FA) verification,
 * password resets, and session terminations.
 *
 * @package OwnPay\Controller\Admin
 */
final class AuthController
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
     * @var AuthSessionService The authentication session service.
     */
    private AuthSessionService $auth;

    /**
     * @var EventManager The system hook and filter event manager.
     */
    private EventManager $events;

    /**
     * @var MerchantUserRepository The repository for merchant user accounts.
     */
    private MerchantUserRepository $userRepo;

    /**
     * @var AuditService The system auditing service.
     */
    private AuditService $audit;

    /**
     * @var SettingsRepository The repository for system settings.
     */
    private SettingsRepository $settings;

    /**
     * AuthController constructor.
     *
     * @param Container              $c        The dependency injection container.
     * @param AdminSession           $session  The administrative session service.
     * @param AuthSessionService     $auth     The authentication session service.
     * @param EventManager           $events   The system hook and filter event manager.
     * @param MerchantUserRepository $userRepo The repository for merchant user accounts.
     * @param AuditService           $audit    The system auditing service.
     * @param SettingsRepository     $settings The repository for system settings.
     */
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

    /**
     * Renders the administrative login form.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The login page template or redirect to dashboard.
     */
    public function loginForm(Request $req): Response
    {
        if ($this->auth->isAuthenticated()) {
            return Response::redirect('/admin');
        }

        $loginSlug = $this->resolveLoginSlug();

        return $this->renderAdminPage('page/login.twig', [
            'login_url' => '/' . $loginSlug,
            'error'     => null,
            'old_email' => null,
        ]);
    }

    /**
     * Authenticates credentials and routes requests to either the dashboard or a 2FA challenge.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response Redirect or render response based on authentication results.
     */
    public function login(Request $req): Response
    {
        $email    = $req->post('email', '');
        $password = $req->post('password', '');

        $this->events->doAction('auth.login.attempt', ['email' => $email, 'ip' => $req->ip()]);

        $result = $this->auth->login($email, $password, $req->ip(), $req->userAgent());
        if (!$result['success']) {
            $this->audit->log('login.failed', 'user', null, null, ['email' => $email]);
            $loginSlug = $this->resolveLoginSlug();
            return $this->renderAdminPage('page/login.twig', [
                'login_url' => '/' . $loginSlug,
                'error'     => $result['error'] ?? 'Invalid credentials',
                'old_email' => $email,
            ]);
        }

        if (!isset($result['user'])) {
            $loginSlug = $this->resolveLoginSlug();
            return $this->renderAdminPage('page/login.twig', [
                'login_url' => '/' . $loginSlug,
                'error'     => 'Invalid user account',
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

    /**
     * Renders the 2FA verification code input form.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The 2FA input form or redirect to login.
     */
    public function twoFactorForm(Request $req): Response
    {
        if ($this->session->get('2fa_user_id') === null) {
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect('/' . $loginSlug);
        }
        return $this->renderAdminPage('page/2fa.twig');
    }

    /**
     * Verifies the submitted TOTP 2FA code and bootstraps the authenticated session.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response Redirect response or error presentation response.
     */
    public function twoFactorVerify(Request $req): Response
    {
        $userId = $this->session->get('2fa_user_id');
        if ($userId === null) {
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect('/' . $loginSlug);
        }

        $codeRaw = $req->post('code', '');
        $code = preg_replace('/\D/', '', is_string($codeRaw) ? $codeRaw : '');
        $user = $this->userRepo->findActiveByEmail(
            $this->userRepo->findById((int) $userId)['email'] ?? ''
        );

        if (!$user || empty($user['totp_secret_enc'])) {
            return $this->renderAdminPage('page/2fa.twig', ['error' => '2FA is not properly configured on this account.']);
        }

        // BUG-40 FIX: Decrypt the TOTP secret before verification.
        // totp_secret_enc is AES-256-GCM encrypted — passing it raw to verifyTotp()
        // computes HMAC on ciphertext, causing all codes to fail.
        $decryptedSecret = $this->userRepo->getTotpSecret((int) $user['id']);
        if ($decryptedSecret === null) {
            return $this->renderAdminPage('page/2fa.twig', ['error' => '2FA secret could not be decrypted.']);
        }

        if (!\OwnPay\Middleware\TwoFactorMiddleware::verifyTotp($decryptedSecret, $code)) {
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

    /**
     * Renders the password reset form with contact details of the system superadmin.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The static forgot password presentation layout.
     */
    public function forgotForm(Request $req): Response
    {
        // AUD-03 FIX: OwnPay is single-owner system.
        // Password resets are done by superadmin, not self-service email.
        $supportEmail = $this->settings->get('general', 'support_email', '');
        return $this->renderAdminPage('page/forgot.twig', [
            'support_email' => $supportEmail,
        ]);
    }

    /**
     * Logs the request to reset a password and provides administrative instructions.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The password reset submission response.
     */
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

    /**
     * Logs out the active user, terminating sessions and dynamic redirects based on settings.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response to the landing or login route.
     */
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

    /**
     * Resolves the dynamic login slug from the storage cache or settings.
     *
     * @return string
     */
    private function resolveLoginSlug(): string
    {
        $cacheFile = dirname(__DIR__, 3) . '/storage/cache/login_slug.cache';
        if (file_exists($cacheFile)) {
            $slug = @file_get_contents($cacheFile);
            if ($slug !== false && $slug !== '') {
                $slug = trim($slug);
                if (preg_match('/^[a-z0-9\-]+$/', $slug)) {
                    return $slug;
                }
            }
        }

        try {
            return $this->settings->get('landing', 'admin_login_slug', 'login');
        } catch (\Throwable) {
            return 'login';
        }
    }
}
