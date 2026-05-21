<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\MerchantUserRepository;

/**
 * 2FA Setup Controller — profile-level TOTP enable/disable.
 *
 * Stores totp_secret in op_merchant_users.
 * Works with any RFC 6238 authenticator (Google Authenticator, Authy, etc.)
 */
final class TwoFactorSetupController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private MerchantUserRepository $userRepo;

    public function __construct(Container $c, AdminSession $session, MerchantUserRepository $userRepo)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->userRepo = $userRepo;
    }

    /** GET /admin/my-account/2fa — show setup page */
    public function index(Request $req): Response
    {
        $userId = $this->session->userId();
        $user   = $this->userRepo->findById($userId);

        if (!$user) {
            return Response::redirect('/admin');
        }

        // BUG-46 FIX: Use decrypted secret for QR code, not raw encrypted column.
        // totp_secret_enc is AES-256-GCM encrypted — useless as a QR secret.
        $secret  = $this->userRepo->getTotpSecret($userId);
        $enabled = (bool) ($user['two_factor_enabled'] ?? false);
        $qrUri   = null;

        if (!$enabled) {
            if (empty($secret)) {
                $secret = $this->generateBase32Secret();
                $this->userRepo->setTotpSecret($userId, $secret);
            }
            $appName = rawurlencode($this->c->get('config.app')['name'] ?? 'OwnPay');
            $email   = rawurlencode($user['email']);
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

    /** POST /admin/my-account/2fa/enable — verify code and activate */
    public function enable(Request $req): Response
    {
        $userId = $this->session->userId();
        $code   = preg_replace('/\D/', '', $req->post('code', ''));
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

    /** POST /admin/my-account/2fa/disable — disable 2FA after password confirmation */
    public function disable(Request $req): Response
    {
        $userId   = $this->session->userId();
        $password = $req->post('password', '');

        $hash = $this->userRepo->getPasswordHash($userId);
        if (!$hash || !password_verify($password, $hash)) {
            $this->session->flashError('Incorrect password.');
            return Response::redirect('/admin/my-account/2fa');
        }

        $this->userRepo->disableTotp($userId);
        $this->session->set('two_fa_enabled', false);
        $this->session->flashSuccess('2FA has been disabled.');
        return Response::redirect('/admin/my-account');
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function generateBase32Secret(int $bytes = 20): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = random_bytes($bytes);
        $bits   = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $chars[bindec(str_pad($chunk, 5, '0'))];
        }
        return $result;
    }

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
                $key .= chr(bindec($chunk));
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
