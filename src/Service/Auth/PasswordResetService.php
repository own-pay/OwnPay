<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Repository\PasswordResetRepository;
use OwnPay\Service\Communication\CommunicationService;
use OwnPay\Service\Domain\DomainUrlService;
use OwnPay\Service\System\Logger;
use OwnPay\Security\Authenticator;
use OwnPay\View\FragmentRenderer;

/**
 * Self-service password reset orchestration.
 *
 * Security model:
 * - Tokens are 256-bit random values; only their SHA-256 hash is stored (DB read ≠ usable link).
 * - Single-use ({@see PasswordResetRepository::markUsed}) and time-limited (1h).
 * - requestReset() is constant in observable behaviour for known vs unknown emails (no account
 *   enumeration) and never throws (a mail failure must not leak existence via an error page).
 * - A new request invalidates prior tokens; a successful reset invalidates all remaining tokens.
 * - New password uses the same Argon2id hash as login ({@see Authenticator::hashPassword}).
 *
 * @package OwnPay\Service\Auth
 */
final class PasswordResetService
{
    /**
     * @var int Minimum new-password length (mirrors staff creation / change-password policy).
     */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * @param MerchantUserRepository $users User lookup + password update.
     * @param PasswordResetRepository $tokens Reset-token store.
     * @param CommunicationService $comm Unified mail dispatcher.
     * @param FragmentRenderer $renderer Twig renderer for the reset email body.
     * @param DomainUrlService $urls Brand-aware base URL resolver for the reset link.
     * @param Logger $logger System logger for non-fatal failures.
     */
    public function __construct(
        private readonly MerchantUserRepository $users,
        private readonly PasswordResetRepository $tokens,
        private readonly CommunicationService $comm,
        private readonly FragmentRenderer $renderer,
        private readonly DomainUrlService $urls,
        private readonly Logger $logger
    ) {
    }

    /**
     * Issues a reset token for an active account and emails the link. Silent on unknown email.
     *
     * @param string $email The submitted email address.
     * @return void
     */
    public function requestReset(string $email): void
    {
        $email = trim($email);
        if ($email === '') {
            return;
        }

        try {
            $user = $this->users->findActiveByEmail($email);
            if ($user === null) {
                return; // no account enumeration - caller shows the same message regardless
            }

            $userId = is_scalar($user['id'] ?? null) ? (int) $user['id'] : 0;
            if ($userId <= 0) {
                return;
            }
            $merchantId = is_scalar($user['merchant_id'] ?? null) ? (int) $user['merchant_id'] : 0;

            $token = bin2hex(random_bytes(32));
            $this->tokens->invalidateForUser($userId);
            $this->tokens->createToken($userId, hash('sha256', $token));

            $base = rtrim($this->urls->resolveBaseUrl($merchantId), '/');
            $resetUrl = $base . '/reset-password?token=' . $token;

            $html = $this->renderer->render('email/password_reset.twig', ['reset_url' => $resetUrl]);
            $this->comm->sendEmail($merchantId, [
                'to'      => $email,
                'subject' => 'Reset your password',
                'body'    => 'Use this link to reset your password (valid for 1 hour): ' . $resetUrl,
                'html'    => $html,
            ]);
        } catch (\Throwable $e) {
            // Never surface failure to the caller - that would leak account existence / internals.
            $this->logger->error('Password reset request failed: ' . $e->getMessage());
        }
    }

    /**
     * Returns whether a plaintext token currently maps to a usable (unused, unexpired) record.
     *
     * @param string $token The plaintext token from the reset link.
     * @return bool True when the token can still be used.
     */
    public function tokenIsValid(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        return $this->tokens->findValidByHash(hash('sha256', $token)) !== null;
    }

    /**
     * Validates a token + new password, sets the password, and consumes the token.
     *
     * @param string $token The plaintext token from the reset link.
     * @param string $newPassword The new password.
     * @param string $confirm The confirmation of the new password.
     * @return array{success: bool, error: string} Outcome; error is '' on success, else generic text.
     */
    public function resetPassword(string $token, string $newPassword, string $confirm): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'error' => 'Invalid or expired reset link.'];
        }
        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if (!hash_equals($newPassword, $confirm)) {
            return ['success' => false, 'error' => 'Passwords do not match.'];
        }

        $record = $this->tokens->findValidByHash(hash('sha256', $token));
        if ($record === null) {
            return ['success' => false, 'error' => 'Invalid or expired reset link.'];
        }

        $userId = is_scalar($record['user_id'] ?? null) ? (int) $record['user_id'] : 0;
        $tokenId = is_scalar($record['id'] ?? null) ? (int) $record['id'] : 0;
        if ($userId <= 0 || $tokenId <= 0) {
            return ['success' => false, 'error' => 'Invalid or expired reset link.'];
        }

        $this->users->updatePassword($userId, Authenticator::hashPassword($newPassword));
        $this->tokens->markUsed($tokenId);
        $this->tokens->invalidateForUser($userId); // burn any other outstanding links

        return ['success' => true, 'error' => ''];
    }
}
