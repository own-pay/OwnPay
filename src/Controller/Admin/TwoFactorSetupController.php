<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\MerchantUserRepository;

/**
 * 2FA Setup Controller for managing profile-level TOTP enable/disable configurations.
 * Works with any RFC 6238 authenticator (Google Authenticator, Authy, etc.).
 * Stores totp_secret in op_merchant_users.
 */
final class TwoFactorSetupController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The merchant user repository instance.
     */
    private MerchantUserRepository $userRepo;

    /**
     * TwoFactorSetupController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param MerchantUserRepository $userRepo The merchant user repository instance.
     */
    public function __construct(Container $c, AdminSession $session, MerchantUserRepository $userRepo)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->userRepo = $userRepo;
    }

    /**
     * Show 2FA setup details page with QR Code.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with setup page or redirect.
     * @throws \Exception If QR uri generation or page rendering fails.
     */
    public function index(Request $req): Response
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return Response::redirect('/admin');
        }
        $user   = $this->userRepo->findById($userId);

        if (!$user) {
            return Response::redirect('/admin');
        }

        // Use decrypted secret for QR code, not raw encrypted column.
        $secret  = $this->userRepo->getTotpSecret($userId);
        $enabled = (bool) ($user['two_factor_enabled'] ?? false);
        $qrUri   = null;

        if (!$enabled) {
            if (empty($secret)) {
                $secret = $this->generateBase32Secret();
                $this->userRepo->setTotpSecret($userId, $secret);
            }
            $configApp = $this->c->get('config.app');
            $configAppName = is_array($configApp) && isset($configApp['name']) && is_string($configApp['name']) ? $configApp['name'] : 'OwnPay';
            $appName = rawurlencode($configAppName);
            $userEmail = is_string($user['email'] ?? null) ? $user['email'] : '';
            $email   = rawurlencode($userEmail);
            $qrUri   = "otpauth://totp/{$appName}:{$email}?secret={$secret}&issuer={$appName}&algorithm=SHA1&digits=6&period=30";
        }

        return $this->renderAdminPage('admin/my-account-2fa.twig', [
            'user'        => $user,
            'totp_secret' => $secret,
            'totp_enabled'=> $enabled,
            'qr_uri'      => $qrUri,
            'active_page' => 'profile',
        ]);
    }

    /**
     * Verify the code submitted by the user and enable 2FA on successful match.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If lookup or saving fails.
     */
    public function enable(Request $req): Response
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return Response::redirect('/admin/my-account/2fa');
        }
        $codeRaw = $req->post('code', '');
        $code   = (string) preg_replace('/\D/', '', is_string($codeRaw) ? $codeRaw : '');
        $secret = $this->userRepo->getTotpSecret($userId);

        if (!$secret) {
            $this->session->flashError('2FA setup not initialized. Please try again.');
            return Response::redirect('/admin/my-account/2fa');
        }

        if (!$this->verifyTotp($secret, $code)) {
            $this->session->flashError('Invalid code. Please try again.');
            return Response::redirect('/admin/my-account/2fa');
        }

        $this->userRepo->enableTotp($userId);
        $this->session->set('two_fa_enabled', true);
        $this->session->flashSuccess('2FA has been enabled on your account.');
        return Response::redirect('/admin/my-account');
    }

    /**
     * Disable 2FA on user account after verifying their current password.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If lookup or saving fails.
     */
    public function disable(Request $req): Response
    {
        $userId   = $this->session->userId();
        if ($userId === null) {
            return Response::redirect('/admin/my-account/2fa');
        }
        $passwordRaw = $req->post('password', '');
        $password = is_string($passwordRaw) ? $passwordRaw : '';

        $hash = $this->userRepo->getPasswordHash($userId);
        if (!is_string($hash) || !password_verify($password, $hash)) {
            $this->session->flashError('Incorrect password.');
            return Response::redirect('/admin/my-account/2fa');
        }

        $this->userRepo->disableTotp($userId);
        $this->session->set('two_fa_enabled', false);
        $this->session->flashSuccess('2FA has been disabled.');
        return Response::redirect('/admin/my-account');
    }

    /**
     * Generate a cryptographically secure random base32 encoded secret.
     *
     * @param int $bytes The length of binary secret before base32 encoding.
     * @return string The base32 secret.
     * @throws \Exception If random_bytes fails.
     */
    private function generateBase32Secret(int $bytes = 20): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $length = max(1, $bytes);
        $binary = random_bytes($length);
        $bits   = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $chars[(int) bindec(str_pad($chunk, 5, '0'))];
        }
        return $result;
    }

    /**
     * Verify user TOTP code within a drift window.
     *
     * @param string $secret The decoded base32 secret.
     * @param string $code The 6-digit TOTP code.
     * @param int $window The drift counter window (default = 1, +/- 30 seconds).
     * @return bool True if valid, false otherwise.
     */
    private function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        $time = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if ($this->generateTotp($secret, $time + $i) === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate a TOTP code for a given secret and counter.
     *
     * @param string $secret The base32 secret.
     * @param int $counter The counter value.
     * @return string The 6-digit code.
     */
    private function generateTotp(string $secret, int $counter): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits  = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $pos  = strpos($chars, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $key .= chr((int) bindec($chunk));
            }
        }

        $msg  = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $msg, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $code   = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
            (ord($hash[$offset + 3])  & 0xFF)
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }
}
