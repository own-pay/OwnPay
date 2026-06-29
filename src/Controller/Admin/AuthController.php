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
        $emailVal = $req->post('email', '');
        $email = is_string($emailVal) ? $emailVal : '';
        $passwordVal = $req->post('password', '');
        $password = is_string($passwordVal) ? $passwordVal : '';

        $this->events->doAction('auth.login.attempt', ['email' => $email, 'ip' => $req->ip()]);

        $result = $this->auth->login($email, $password, $req->ip(), $req->userAgent());
        if (!$result['success']) {
            $this->audit->log('login.failed', 'user', null, null, ['email' => $email]);
            $loginSlug = $this->resolveLoginSlug();
            $errorMsg = $result['error'] ?? 'Invalid credentials';
            return $this->renderAdminPage('page/login.twig', [
                'login_url' => '/' . $loginSlug,
                'error'     => $errorMsg,
                'old_email' => $email,
            ]);
        }

        $resUser = $result['user'] ?? null;
        if (!is_array($resUser)) {
            $loginSlug = $this->resolveLoginSlug();
            return $this->renderAdminPage('page/login.twig', [
                'login_url' => '/' . $loginSlug,
                'error'     => 'Invalid user account',
                'old_email' => $email,
            ]);
        }

        $userIdVal = $resUser['id'] ?? 0;
        $userId = is_int($userIdVal) || is_string($userIdVal) ? (int)$userIdVal : 0;

        // Check 2FA
        if (!empty($result['requires_2fa'])) {
            $this->session->set('2fa_user_id', $userId);
            return Response::redirect('/2fa');
        }

        $this->audit->log('login.success', 'user', $userId);
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
        return $this->renderAdminPage('page/2fa.twig', [
            'error' => null,
        ]);
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
        $userIdVal = $this->session->get('2fa_user_id');
        if ($userIdVal === null) {
            $loginSlug = $this->resolveLoginSlug();
            return Response::redirect('/' . $loginSlug);
        }
        $userId = is_int($userIdVal) || is_string($userIdVal) ? (int)$userIdVal : 0;

        $codeRaw = $req->post('code', '');
        $code = (string) preg_replace('/\D/', '', is_string($codeRaw) ? $codeRaw : '');
        
        $userEmailVal = $this->userRepo->findById($userId)['email'] ?? '';
        $userEmail = is_string($userEmailVal) ? $userEmailVal : '';
        $user = $this->userRepo->findActiveByEmail($userEmail);

        if (!is_array($user) || empty($user['totp_secret_enc'])) {
            return $this->renderAdminPage('page/2fa.twig', ['error' => '2FA is not properly configured on this account.']);
        }

        $userIdFromDbVal = $user['id'] ?? 0;
        $userIdFromDb = is_int($userIdFromDbVal) || is_string($userIdFromDbVal) ? (int)$userIdFromDbVal : 0;

        // Decrypt the TOTP secret before verification.
        $decryptedSecret = $this->userRepo->getTotpSecret($userIdFromDb);
        if ($decryptedSecret === null) {
            return $this->renderAdminPage('page/2fa.twig', ['error' => '2FA secret could not be decrypted.']);
        }
        $replayKey = 'totp_replay_' . $userIdFromDb;
        $cache = $this->c->has(\OwnPay\Cache\CacheInterface::class)
            ? $this->c->get(\OwnPay\Cache\CacheInterface::class)
            : null;
        $lastUsedWindow = 0;
        if ($cache instanceof \OwnPay\Cache\CacheInterface) {
            $storedWindow = $cache->get($replayKey);
            $lastUsedWindow = is_numeric($storedWindow) ? (int) $storedWindow : 0;
        }

        if (!\OwnPay\Middleware\TwoFactorMiddleware::verifyTotp($decryptedSecret, $code, 1, $lastUsedWindow)) {
            return $this->renderAdminPage('page/2fa.twig', ['error' => 'Invalid or expired 2FA code. Please try again.']);
        }

        // Persist the consumed time slice so the same code cannot be replayed elsewhere.
        // TTL comfortably exceeds the ±1 step (30s) drift window.
        if ($cache instanceof \OwnPay\Cache\CacheInterface) {
            $cache->set($replayKey, $lastUsedWindow, 120);
        }

        // Full session bootstrap via Authenticator
        $authenticator = $this->c->get(\OwnPay\Security\Authenticator::class);
        if ($authenticator instanceof \OwnPay\Security\Authenticator) {
            $authenticator->startSession($user);
        } else {
            return $this->renderAdminPage('page/2fa.twig', ['error' => 'Authenticator service unavailable']);
        }
        $this->userRepo->updateLastLogin($userIdFromDb, $req->ip());

        // 2FA-specific session flags
        $_SESSION['two_fa_enabled'] = true;
        $_SESSION['2fa_verified'] = true;
        unset($_SESSION['2fa_user_id']);

        $this->events->doAction('auth.login.success', $user);
        $this->audit->log('login.2fa_verified', 'user', $userIdFromDb);

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
        $supportEmail = $this->settings->get('general', 'support_email', '');
        $supportEmailStr = is_string($supportEmail) ? $supportEmail : '';
        return $this->renderAdminPage('page/forgot.twig', [
            'support_email' => $supportEmailStr,
        ]);
    }

    /**
     * Issues a self-service password reset link to the submitted email (if it maps to an account).
     *
     * The response is identical whether or not the account exists, to prevent account enumeration.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The password reset submission response.
     */
    public function forgotSubmit(Request $req): Response
    {
        $emailVal = $req->post('email', '');
        $email = trim(is_string($emailVal) ? $emailVal : '');

        if ($email === '') {
            return $this->renderAdminPage('page/forgot.twig', [
                'error' => 'Please enter your email address.',
            ]);
        }

        // Send the reset link only if the email maps to an active account; the message below is shown
        // either way so an attacker cannot probe which emails are registered.
        $this->passwordReset()->requestReset($email);
        $this->audit->log('password_reset.requested', 'user', null, null, ['email' => $email]);
        $this->events->doAction('auth.forgot_password', ['email' => $email]);

        return $this->renderAdminPage('page/forgot.twig', [
            'success' => 'If an account exists for that email, a password reset link has been sent. It expires in 1 hour.',
        ]);
    }

    /**
     * Renders the new-password form for a valid reset token (or an invalid-link notice).
     *
     * @param Request $req The incoming HTTP request (expects ?token=...).
     *
     * @return Response The reset page response.
     */
    public function resetForm(Request $req): Response
    {
        $tokenVal = $req->query('token', '');
        $token = is_string($tokenVal) ? $tokenVal : '';

        if (!$this->passwordReset()->tokenIsValid($token)) {
            return $this->renderAdminPage('page/reset.twig', [
                'invalid' => true,
                'error'   => 'This password reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        return $this->renderAdminPage('page/reset.twig', ['token' => $token]);
    }

    /**
     * Validates the token + new password and applies the password change.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response Redirect to login on success, or the form re-rendered with an error.
     */
    public function resetSubmit(Request $req): Response
    {
        $tokenVal = $req->post('token', '');
        $token = is_string($tokenVal) ? $tokenVal : '';
        $pwVal = $req->post('password', '');
        $password = is_string($pwVal) ? $pwVal : '';
        $confirmVal = $req->post('password_confirm', '');
        $confirm = is_string($confirmVal) ? $confirmVal : '';

        $result = $this->passwordReset()->resetPassword($token, $password, $confirm);

        if ($result['success'] !== true) {
            // If the token is dead, drop to the invalid-link state (no form); otherwise re-show the form.
            // resetPassword() always returns 'error' on failure, so it is safe to read directly here.
            $tokenAlive = $this->passwordReset()->tokenIsValid($token);
            return $this->renderAdminPage('page/reset.twig', [
                'token'   => $tokenAlive ? $token : '',
                'invalid' => !$tokenAlive,
                'error'   => $result['error'],
            ]);
        }

        $this->audit->log('password_reset.completed', 'user', null);
        $this->session->flashSuccess('Your password has been reset. Please sign in with your new password.');
        return Response::redirect('/login');
    }

    /**
     * Resolves the password reset service from the container.
     *
     * @return \OwnPay\Service\Auth\PasswordResetService The password reset orchestrator.
     */
    private function passwordReset(): \OwnPay\Service\Auth\PasswordResetService
    {
        $svc = $this->c->get(\OwnPay\Service\Auth\PasswordResetService::class);
        if (!$svc instanceof \OwnPay\Service\Auth\PasswordResetService) {
            throw new \RuntimeException('PasswordResetService not available.');
        }
        return $svc;
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
        $sessionAuthUserId = $_SESSION['auth_user_id'] ?? null;
        $sessionAuthUserIdInt = (is_int($sessionAuthUserId) || is_string($sessionAuthUserId)) ? (int)$sessionAuthUserId : null;
        $this->audit->log('logout', 'user', $sessionAuthUserIdInt);
        $this->events->doAction('auth.logout', $this->session->currentUser());
        $this->auth->logout();

        // Resolve dynamic login slug instead of hardcoded /login
        $loginSlug = 'login';
        try {
            $slug = $this->settings->get('landing', 'admin_login_slug', 'login');
            if (is_string($slug) && $slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug)) {
                $loginSlug = $slug;
            }
        } catch (\Throwable) {
            // Settings unavailable - keep the safe default 'login' slug.
        }
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
             $slugSetting = $this->settings->get('landing', 'admin_login_slug', 'login');
             return is_string($slugSetting) ? $slugSetting : 'login';
         } catch (\Throwable) {
             return 'login';
         }
     }
}
