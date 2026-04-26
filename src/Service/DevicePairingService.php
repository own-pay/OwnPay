<?php

declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Repository\DevicePairingTokenRepository;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Security\FieldEncryptor;
use Ramsey\Uuid\Uuid;

/**
 * DevicePairingService — Orchestrates the 5-minute OTP handshake.
 *
 * Flow:
 *   1. Admin generates OTP (generatePairingOtp)
 *   2. App scans QR → sends OTP + device info (pairDevice)
 *   3. Server validates OTP → creates device record → issues credentials
 *   4. App uses JWT for auth, refresh token for renewal
 *
 * Security:
 *   - OTP stored as SHA-256 hash, expires in 5 minutes
 *   - AES-256 key encrypted on server via FieldEncryptor
 *   - Per-device JWT signing secret (HMAC-SHA256)
 *   - Device fingerprint pinning
 *   - Refresh tokens are opaque, stored as SHA-256 hash
 */
final class DevicePairingService
{
    /** Max OTP generation requests per admin per 5-minute window */
    private const OTP_RATE_LIMIT = 5;

    /** Refresh token lifetime: 90 days */
    private const REFRESH_TOKEN_DAYS = 90;

    /** @var DevicePairingTokenRepository */
    private $tokenRepo;
    /** @var PairedDeviceRepository */
    private $deviceRepo;
    /** @var JwtService */
    private $jwt;
    /** @var FieldEncryptor */
    private $encryptor;

    public function __construct(
        /* DevicePairingTokenRepository */ $tokenRepo = null,
        /* PairedDeviceRepository */       $deviceRepo = null,
        ?JwtService $jwt = null,
        /* FieldEncryptor */               $encryptor = null
    ) {
        $this->tokenRepo = $tokenRepo ?? new DevicePairingTokenRepository();
        $this->deviceRepo = $deviceRepo ?? new PairedDeviceRepository();
        $this->jwt = $jwt ?? new JwtService();
        $this->encryptor = $encryptor ?? new FieldEncryptor();
    }

    /**
     * Generate a pairing OTP for admin display (QR code or manual entry).
     *
     * @param int $brandId   The brand ID
     * @param int $adminId   The admin user who requested pairing
     * @return array{otp: string, expires_in: int}|array{error: string}
     */
    public function generatePairingOtp(int $brandId, int $adminId): array
    {
        // Rate limit: max 5 OTPs per admin per 5 minutes
        $recentCount = $this->tokenRepo->countRecentByAdmin($adminId);
        if ($recentCount >= self::OTP_RATE_LIMIT) {
            return ['error' => 'Too many pairing requests. Please wait a few minutes.'];
        }

        // Generate 6-digit OTP
        $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = hash('sha256', $otp);

        $this->tokenRepo->create($otpHash, $brandId, $adminId);

        return [
            'otp'        => $otp,
            'expires_in' => 300, // 5 minutes
        ];
    }

    /**
     * Complete device pairing: validate OTP → create device → issue credentials.
     *
     * @param string $otp               The raw 6-digit OTP
     * @param string $deviceName        Device name (e.g. "Samsung Galaxy A54")
     * @param string $deviceFingerprint Raw fingerprint string (android_id:cert_sha256)
     * @param string $appVersion        App version string
     * @param string $platform          'android' or 'ios'
     * @return array Credentials on success, error on failure
     */
    public function pairDevice(
        string $otp,
        string $deviceName,
        string $deviceFingerprint,
        string $appVersion = '',
        string $platform = 'android'
    ): array {
        // 1. Validate + consume OTP
        $otpHash = hash('sha256', $otp);
        $token = $this->tokenRepo->validateAndConsume($otpHash);

        if ($token === null) {
            return [
                'success' => false,
                'error'   => 'INVALID_OTP',
                'message' => 'Invalid or expired pairing code. Please generate a new one.',
            ];
        }

        $brandId = (int) $token['brand_id'];
        $fingerprintHash = hash('sha256', $deviceFingerprint);

        // 2. Check for re-pairing (same fingerprint, same brand)
        $existingDevice = $this->deviceRepo->findByFingerprintHash($fingerprintHash, $brandId);
        if ($existingDevice) {
            // Re-pair: revoke old device, create new one
            $this->deviceRepo->revoke($existingDevice['device_uuid']);
        }

        // 3. Generate all cryptographic material
        $deviceUuid    = Uuid::uuid4()->toString();
        $jwtSecret     = JwtService::generateSecret();
        $aesKey        = bin2hex(random_bytes(32)); // 256-bit key as hex
        $refreshToken  = bin2hex(random_bytes(32)); // opaque refresh token

        // 4. Encrypt AES key for server-side storage
        $aesKeyEncrypted = $this->encryptor->encrypt($aesKey);

        // 5. Compute refresh token hash
        $refreshTokenHash = hash('sha256', $refreshToken);
        $refreshExpires = date('Y-m-d H:i:s', time() + (self::REFRESH_TOKEN_DAYS * 86400));

        // 6. Persist device record
        $this->deviceRepo->create([
            'device_uuid'               => $deviceUuid,
            'brand_id'                  => $brandId,
            'device_name'               => $deviceName,
            'fingerprint_hash'          => $fingerprintHash,
            'aes_key_encrypted'         => $aesKeyEncrypted,
            'refresh_token_hash'        => $refreshTokenHash,
            'refresh_token_expires_at'  => $refreshExpires,
            'jwt_secret'                => $jwtSecret,
            'platform'                  => $platform,
            'app_version'               => $appVersion,
        ]);

        // 7. Issue access token
        $accessToken = $this->jwt->encode($deviceUuid, $brandId, $jwtSecret);

        return [
            'success'        => true,
            'access_token'   => $accessToken['token'],
            'refresh_token'  => $refreshToken,
            'expires_in'     => $accessToken['expires_in'],
            'aes_key'        => $aesKey,
            'device_id'      => $deviceUuid,
            'filter_rules_url' => '/api/v1/config/filter-rules',
        ];
    }

    /**
     * Refresh an access token using a valid refresh token.
     *
     * @param string $refreshToken  The raw opaque refresh token
     * @param string $fingerprint   The device fingerprint for pinning validation
     * @return array New access token on success, error on failure
     */
    public function refreshAccessToken(string $refreshToken, string $fingerprint): array
    {
        $hash = hash('sha256', $refreshToken);
        $device = $this->deviceRepo->findByRefreshTokenHash($hash);

        if ($device === null) {
            return [
                'success' => false,
                'error'   => 'INVALID_REFRESH_TOKEN',
                'message' => 'Refresh token is invalid, expired, or revoked.',
            ];
        }

        // Validate device fingerprint pinning
        $fingerprintHash = hash('sha256', $fingerprint);
        if (!hash_equals($device['fingerprint_hash'], $fingerprintHash)) {
            return [
                'success' => false,
                'error'   => 'FINGERPRINT_MISMATCH',
                'message' => 'Device fingerprint does not match the paired device.',
            ];
        }

        // Rotate refresh token
        $newRefreshToken = bin2hex(random_bytes(32));
        $newRefreshHash = hash('sha256', $newRefreshToken);
        $newRefreshExpires = date('Y-m-d H:i:s', time() + (self::REFRESH_TOKEN_DAYS * 86400));

        $this->deviceRepo->updateRefreshToken(
            $device['device_uuid'],
            $newRefreshHash,
            $newRefreshExpires
        );

        // Issue new access token
        $accessToken = $this->jwt->encode(
            $device['device_uuid'],
            (int) $device['brand_id'],
            $device['jwt_secret']
        );

        // Update last seen
        $this->deviceRepo->touchLastSeen($device['device_uuid']);

        return [
            'success'       => true,
            'access_token'  => $accessToken['token'],
            'refresh_token' => $newRefreshToken,
            'expires_in'    => $accessToken['expires_in'],
        ];
    }

    /**
     * Validate a JWT and return the authenticated device context.
     * Used by JwtAuthMiddleware on every protected API call.
     *
     * @param string $jwt         The raw JWT from Authorization header
     * @param string $fingerprint The device fingerprint from X-Device-Fingerprint header
     * @return array{valid: bool, device: ?array, error: ?string}
     */
    public function validateRequest(string $jwt, string $fingerprint): array
    {
        // We need to find the device first to get its jwt_secret.
        // JWT sub contains device UUID, but we can't decode without the secret.
        // Solution: Extract payload without verification first to get sub,
        // then verify with the device's secret.

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return ['valid' => false, 'device' => null, 'error' => 'INVALID_TOKEN'];
        }

        // Decode payload (unverified) to extract device UUID
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
        if ($payloadJson === false) {
            return ['valid' => false, 'device' => null, 'error' => 'INVALID_TOKEN'];
        }

        $payload = json_decode($payloadJson, true);
        if (!$payload || empty($payload['sub'])) {
            return ['valid' => false, 'device' => null, 'error' => 'INVALID_TOKEN'];
        }

        $deviceUuid = $this->jwt->extractDeviceUuid($payload['sub']);
        if ($deviceUuid === null) {
            return ['valid' => false, 'device' => null, 'error' => 'INVALID_TOKEN'];
        }

        // Look up device
        $device = $this->deviceRepo->findByUuid($deviceUuid);
        if ($device === null || $device['revoked_at'] !== null) {
            return ['valid' => false, 'device' => null, 'error' => 'DEVICE_REVOKED'];
        }

        // Now verify JWT with the device's secret
        $result = $this->jwt->decode($jwt, $device['jwt_secret']);
        if (!$result['valid']) {
            return ['valid' => false, 'device' => null, 'error' => $result['error']];
        }

        // Validate device fingerprint pinning
        $fingerprintHash = hash('sha256', $fingerprint);
        if (!hash_equals($device['fingerprint_hash'], $fingerprintHash)) {
            return ['valid' => false, 'device' => null, 'error' => 'FINGERPRINT_MISMATCH'];
        }

        // Touch last seen (non-blocking, best-effort)
        try {
            $this->deviceRepo->touchLastSeen($deviceUuid);
        } catch (\Throwable) {
            // Non-critical, log and continue
        }

        return [
            'valid'  => true,
            'device' => [
                'device_uuid' => $device['device_uuid'],
                'brand_id'    => (int) $device['brand_id'],
                'scopes'      => (array) ($result['payload']->scopes ?? []),
            ],
            'error'  => null,
        ];
    }
}
