<?php
declare(strict_types=1);

namespace OwnPay\Service\Device;

use OwnPay\Event\EventManager;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Auth\JwtService;
use OwnPay\Support\DateHelper;

/**
 * Service managing the lifecycle, registration, and authentication of companion mobile devices.
 *
 * Implements OTP-based pairing, JWT token issuance and validation, device fingerprint checking,
 * and AES key distribution. Integrates with system-wide event hooks.
 */
final class DevicePairingService
{
    /**
     * @var PairedDeviceRepository Repository interface for managing device persistence.
     */
    private PairedDeviceRepository $devices;

    /**
     * @var FieldEncryptor|null Cryptographic service to encrypt device keys at rest.
     */
    private ?FieldEncryptor $encryptor;

    /**
     * @var JwtService Service managing stateless mobile authentication tokens.
     */
    private JwtService $jwt;

    /**
     * @var EventManager Event dispatcher system.
     */
    private EventManager $events;

    /**
     * Constructs a new DevicePairingService instance.
     *
     * @param PairedDeviceRepository $devices Repository for device database records.
     * @param FieldEncryptor|null $encryptor Cryptographic helper for PII and credentials.
     * @param JwtService $jwt JSON Web Token service provider.
     * @param EventManager|null $events Optional custom event dispatcher instance.
     */
    public function __construct(
        PairedDeviceRepository $devices,
        ?FieldEncryptor $encryptor,
        JwtService $jwt,
        ?EventManager $events = null
    ) {
        $this->devices   = $devices;
        $this->encryptor = $encryptor;
        $this->jwt       = $jwt;
        $this->events    = $events ?? EventManager::getInstance();
    }

    /**
     * Generates a UUID v4 string.
     *
     * Provides compatibility fallback generation for UUID identifiers.
     *
     * @return string Valid UUID v4 representation.
     */
    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generates a 6-digit one-time pairing OTP for a brand.
     *
     * Applies rate limiting checks for individual administrators to prevent abuse.
     *
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param int|null $adminId Identifier of the admin initiating the request.
     * @return array{otp: string, expires_in: int}|array{error: string} Pairing results or error.
     */
    public function generatePairingOtp(int $merchantId, ?int $adminId = null): array
    {
        $db = $this->devices->getDatabase();
        if ($adminId !== null) {
            $count = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM op_device_pairing_tokens WHERE created_by = :admin AND created_at > DATE_SUB(NOW(6), INTERVAL 300 SECOND)",
                [
                    'admin' => $adminId
                ]
            );
            $cntVal = $count['cnt'] ?? 0;
            if ($count && is_scalar($cntVal) && (int)$cntVal >= 5) {
                return ['error' => 'Too many pairing attempts. Please try again later.'];
            }
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = hash('sha256', $otp);
        $expiresAt = (new \DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s.u');

        $db->execute(
            "UPDATE op_device_pairing_tokens SET is_used = 1 WHERE merchant_id = :mid AND is_used = 0",
            ['mid' => $merchantId]
        );
        $db->execute(
            "INSERT INTO op_device_pairing_tokens (otp_hash, merchant_id, expires_at, is_used, created_by) VALUES (:hash, :mid, :exp, 0, :admin)",
            [
                'hash' => $otpHash,
                'mid' => $merchantId,
                'exp' => $expiresAt,
                'admin' => $adminId
            ]
        );

        return ['otp' => $otp, 'expires_in' => 300];
    }

    /**
     * Validates a client pairing OTP.
     *
     * Uses a row-locking transactional pattern (SELECT ... FOR UPDATE) to prevent
     * concurrent token reuse or replay attacks.
     *
     * @param string $otp The plain pairing OTP code.
     * @return array{valid: true, merchant_id: int, created_by?: int|null}|array{valid: false, error: string} Validation outcome payload.
     */
    public function validatePairingOtp(string $otp): array
    {
        $otpHash = hash('sha256', $otp);
        $db = $this->devices->getDatabase();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $matchedRow = null;
        $db->transaction(function () use ($db, $otpHash, $now, &$matchedRow) {
            $row = $db->fetchOne(
                "SELECT * FROM op_device_pairing_tokens WHERE otp_hash = :hash AND is_used = 0 AND expires_at > :now FOR UPDATE",
                ['hash' => $otpHash, 'now' => $now]
            );

            if (!$row) {
                return;
            }

            $db->execute(
                "UPDATE op_device_pairing_tokens SET is_used = 1 WHERE id = :id",
                ['id' => $row['id']]
            );

            $matchedRow = $row;
        });

        if ($matchedRow === null) {
            return ['valid' => false, 'error' => 'Invalid or expired OTP'];
        }

        $midVal = $matchedRow['merchant_id'] ?? 0;
        $createdByVal = $matchedRow['created_by'] ?? null;
        return [
            'valid' => true,
            'merchant_id' => is_scalar($midVal) ? (int) $midVal : 0,
            'created_by' => is_scalar($createdByVal) ? (int) $createdByVal : null,
        ];
    }

    /**
     * Registers and pairs a new device directly without OTP checking.
     *
     * @param int $userId Identifer of the user pairing the device.
     * @param int $merchantId Unique identifier of the merchant/brand.
     * @param string $deviceName Client-provided identifier label of the device.
     * @param string $pushToken Optional device push notification token.
     * @return array{device_uuid: string, access_token: string, refresh_token: string} Generated device credentials.
     */
    public function pair(int $userId, int $merchantId, string $deviceName, string $pushToken = ''): array
    {
        $deviceUuid = bin2hex(random_bytes(16));
        $accessToken  = $this->jwt->issue($userId, $merchantId, $deviceUuid);
        $refreshToken = $this->jwt->issueRefreshToken($userId, $merchantId, $deviceUuid);
        $jwtFingerprint = hash('sha256', $deviceUuid . $merchantId);

        $this->devices->forTenant($merchantId)->createScoped([
            'device_id'       => $deviceUuid,
            'device_name'     => $deviceName,
            'platform'        => '',
            'jwt_fingerprint' => $jwtFingerprint,
            'status'          => 'active',
            'last_heartbeat'  => DateHelper::nowMicro(),
        ]);

        $this->events->doAction('mobile.device.paired', $deviceUuid, $merchantId, $userId);

        return [
            'device_uuid'   => $deviceUuid,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * Executes client pairing workflow with prior OTP validation.
     *
     * Verifies the OTP, resolves the authenticating administrative user context,
     * invalidates matching duplicate client footprints, and registers credentials.
     *
     * @param string $otp Pairing OTP.
     * @param string $deviceName Friendly identifier label for the device.
     * @param string $fingerprint Client cryptographic hardware fingerprint.
     * @param string $version Client application version.
     * @param string $platform OS platform string of the paired device.
     * @return array{success: true, access_token: string, refresh_token: string, aes_key: string, device_id: string, expires_in: int}|array{success: false, error: string} Pairing payload or error response.
     */
    public function pairDevice(string $otp, string $deviceName, string $fingerprint, string $version = '', string $platform = ''): array
    {
        $valid = $this->validatePairingOtp($otp);
        if (!$valid['valid']) {
            return ['success' => false, 'error' => 'INVALID_OTP'];
        }
        $merchantId = $valid['merchant_id'];
        $createdBy = $valid['created_by'] ?? null;
        $userId = is_scalar($createdBy) ? (int) $createdBy : 0;
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'PAIRING_CONTEXT_UNRESOLVED'];
        }

        $fpHash = hash('sha256', $fingerprint);
        $existing = $this->devices->findByFingerprintHash($fpHash, $merchantId);
        if ($existing !== null) {
            $existingUuid = $existing['device_uuid'] ?? $existing['device_id'] ?? '';
            if ($existingUuid !== '') {
                $existingId = $existing['id'] ?? 0;
                if (is_scalar($existingId)) {
                    $this->devices->forTenant($merchantId)->revoke((int) $existingId);
                }
            }
        }

        $deviceUuid = $this->generateUuidV4();
        $aesKey     = bin2hex(random_bytes(32));

        $accessToken = $this->jwt->issue($userId, $merchantId, $deviceUuid);
        $refreshToken = $this->jwt->issueRefreshToken($userId, $merchantId, $deviceUuid);

        if ($this->encryptor === null) {
            return ['success' => false, 'error' => 'ENCRYPTOR_UNAVAILABLE'];
        }
        $aesKeyEncrypted = $this->encryptor->encrypt($aesKey);

        $data = [
            'device_id'          => $deviceUuid,
            'device_uuid'        => $deviceUuid,
            'merchant_id'        => $merchantId,
            'device_name'        => $deviceName,
            'platform'           => $platform,
            'fingerprint_hash'   => $fpHash,
            'jwt_fingerprint'    => $fpHash,
            'aes_key_encrypted'  => $aesKeyEncrypted,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'refresh_expires_at' => date('Y-m-d H:i:s', time() + 2592000),
            'status'             => 'active',
            'last_heartbeat'     => date('Y-m-d H:i:s'),
        ];

        $this->devices->forTenant($merchantId)->createScoped($data);

        return [
            'success'       => true,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'aes_key'       => $aesKey,
            'device_id'     => $deviceUuid,
            'expires_in'    => 900,
        ];
    }

    /**
     * Issues new credentials using a cryptographically verified refresh token.
     *
     * Validates matching device configuration fingerprints and handles JTI blacklist logging.
     *
     * @param string $refreshToken Cryptographically signed refresh JWT.
     * @param string $fingerprint Device identity fingerprint.
     * @return array{success: true, access_token: string, refresh_token: string, expires_in: int}|array{success: false, error: string} Refreshed token payload or error message.
     */
    public function refreshAccessToken(string $refreshToken, string $fingerprint): array
    {
        $device = null;
        $isStateless = false;
        $jti = null;
        $userId = 0;
        try {
            $claims = $this->jwt->verify($refreshToken);
            $deviceIdVal = $claims['did'] ?? '';
            $deviceId = is_string($deviceIdVal) ? $deviceIdVal : '';
            $midVal = $claims['mid'] ?? 1;
            $mid = is_scalar($midVal) ? (int) $midVal : 1;
            $jtiVal = $claims['jti'] ?? null;
            $jti = is_scalar($jtiVal) ? (string) $jtiVal : null;
            $subVal = $claims['sub'] ?? 0;
            $userId = is_scalar($subVal) ? (int) $subVal : 0;
            if ($deviceId !== '') {
                $device = $this->devices->forTenant($mid)->findByDeviceId($deviceId);
                $isStateless = true;
            }
        } catch (\Throwable) {

        }

        if ($jti !== null) {
            $cacheKey = 'blacklist_jti_' . $jti;
            $exists = $this->devices->getDatabase()->exists('op_cache', 'key_name = :key', ['key' => $cacheKey]);
            if ($exists) {
                return ['success' => false, 'error' => 'INVALID_REFRESH_TOKEN'];
            }
        }

        if (!$isStateless) {
            $hash = hash('sha256', $refreshToken);
            if (method_exists($this->devices, 'findByRefreshTokenHash')) {
                $device = $this->devices->findByRefreshTokenHash($hash);
            }
        }

        if ($device === null) {
            return ['success' => false, 'error' => 'INVALID_REFRESH_TOKEN'];
        }

        if (($device['status'] ?? '') === 'revoked') {
            return ['success' => false, 'error' => 'DEVICE_REVOKED'];
        }

        $fpHash = hash('sha256', $fingerprint);
        $dbFingerprint = $device['jwt_fingerprint'] ?? $device['fingerprint_hash'] ?? '';
        if ($dbFingerprint !== $fpHash) {
            return ['success' => false, 'error' => 'FINGERPRINT_MISMATCH'];
        }

        $brandId = (int) ($device['merchant_id'] ?? 1);
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'INVALID_REFRESH_TOKEN'];
        }

        $accessToken = $this->jwt->issue($userId, $brandId, $device['device_uuid'] ?? $device['device_id']);
        $newRefreshToken = $this->jwt->issueRefreshToken($userId, $brandId, $device['device_uuid'] ?? $device['device_id']);

        $newRefreshHash = hash('sha256', $newRefreshToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 2592000);

        if (!$isStateless && method_exists($this->devices, 'updateRefreshToken')) {
            $this->devices->updateRefreshToken($device['device_uuid'] ?? $device['device_id'], $newRefreshHash, $expiresAt);
        }

        if ($jti !== null) {
            $cacheKey = 'blacklist_jti_' . $jti;
            $expiration = time() + 2592000;
            $this->devices->getDatabase()->insert(
                "INSERT INTO op_cache (key_name, value, expires_at) VALUES (:key, :val, :exp) ON DUPLICATE KEY UPDATE expires_at = :exp_update",
                ['key' => $cacheKey, 'val' => '1', 'exp' => $expiration, 'exp_update' => $expiration]
            );
        }

        return [
            'success'       => true,
            'access_token'  => $accessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in'    => 900,
        ];
    }

    /**
     * Verifies the cryptographic authenticity of a client request JWT.
     *
     * Validates the JWT signature first to reject modified payloads, then verifies
     * device registration metadata and client fingerprint hashes.
     *
     * @param string $token Cryptographically signed access JWT.
     * @param string $fingerprint Device signature fingerprint.
     * @return array{valid: true, device: array{device_uuid: string, brand_id: int}, error: null}|array{valid: false, error: string} Validation status wrapper.
     */
    public function validateRequest(string $token, string $fingerprint): array
    {
        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable) {
            return ['valid' => false, 'error' => 'INVALID_TOKEN'];
        }

        $deviceUuid = '';
        if (isset($claims['did']) && is_scalar($claims['did'])) {
            $deviceUuid = (string) $claims['did'];
        } elseif (isset($claims['sub'])) {
            $sub = $claims['sub'];
            if (is_string($sub) && str_starts_with($sub, 'device:')) {
                $deviceUuid = substr($sub, 7);
            } elseif (is_scalar($sub)) {
                $deviceUuid = (string) $sub;
            }
        }

        if ($deviceUuid === '') {
            return ['valid' => false, 'error' => 'INVALID_TOKEN'];
        }

        $device = $this->devices->findByUuid($deviceUuid);

        if ($device === null) {
            return ['valid' => false, 'error' => 'DEVICE_REVOKED'];
        }

        if (!empty($device['revoked_at']) || ($device['status'] ?? '') === 'revoked') {
            return ['valid' => false, 'error' => 'DEVICE_REVOKED'];
        }

        $fpHash = hash('sha256', $fingerprint);
        $dbFingerprint = $device['jwt_fingerprint'] ?? $device['fingerprint_hash'] ?? '';
        if ($dbFingerprint !== $fpHash) {
            return ['valid' => false, 'error' => 'FINGERPRINT_MISMATCH'];
        }

        $uuidVal = $device['device_uuid'] ?? $device['device_id'] ?? '';
        $merchantIdVal = $device['merchant_id'] ?? 0;
        return [
            'valid'  => true,
            'device' => [
                'device_uuid' => is_scalar($uuidVal) ? (string) $uuidVal : '',
                'brand_id'    => is_scalar($merchantIdVal) ? (int) $merchantIdVal : 0,
            ],
            'error'  => null,
        ];
    }

    /**
     * Revokes a companion device.
     *
     * @param string $deviceUuid Cryptographic identifier of the device.
     * @param int|null $merchantId Unique identifier of the merchant/brand.
     * @return bool True if device was successfully revoked; false otherwise.
     */
    public function revoke(string $deviceUuid, ?int $merchantId): bool
    {
        $repo = ($merchantId === null || $merchantId === 0)
            ? $this->devices->forAllTenants()
            : $this->devices->forTenant($merchantId);

        $device = $repo->findByDeviceId($deviceUuid);
        if ($device === null) {
            return false;
        }

        $deviceIdVal = $device['id'] ?? 0;
        if (!is_scalar($deviceIdVal)) {
            return false;
        }

        $deviceMerchantId = null;
        if (isset($device['merchant_id']) && is_scalar($device['merchant_id'])) {
            $deviceMerchantId = (int) $device['merchant_id'];
        }

        $updateRepo = ($deviceMerchantId === null)
            ? $this->devices->forAllTenants()
            : $this->devices->forTenant($deviceMerchantId);

        $updateRepo->updateScoped((int) $deviceIdVal, ['status' => 'revoked']);

        $this->events->doAction('mobile.device.revoked', $deviceUuid, $deviceMerchantId ?? 0);
        return true;
    }

    /**
     * Records a client activity heartbeat timestamp.
     *
     * @param string $deviceUuid Cryptographic identifier of the device.
     * @return void
     */
    public function heartbeat(string $deviceUuid): void
    {
        $this->devices->updateHeartbeat($deviceUuid);
    }

    /**
     * @param int|null $merchantId Unique identifier of the merchant/brand.
     * @return array<int, array<string, mixed>> List of active devices.
     */
    public function listDevices(?int $merchantId): array
    {
        if ($merchantId === null || $merchantId === 0) {
            return $this->devices->forAllTenants()->listActive();
        }
        return $this->devices->forTenant($merchantId)->listActive();
    }

    /**
     * Returns the most recently paired ACTIVE device for the brand (or all brands) since a baseline
     * timestamp - used by the admin pairing screen to detect a device that just connected.
     *
     * @param int|null $merchantId Brand id, or null/0 for the All-Brands scope.
     * @param string $since Baseline timestamp (the OTP-generation time).
     * @return array<string, mixed>|null The newly paired device, or null if none yet.
     */
    public function findNewlyPairedSince(?int $merchantId, string $since): ?array
    {
        $repo = ($merchantId === null || $merchantId === 0)
            ? $this->devices->forAllTenants()
            : $this->devices->forTenant($merchantId);
        return $repo->findNewestActiveSince($since);
    }

    /**
     * Lists devices (any status) with a derived live `online` flag for the brand (or all brands).
     *
     * @param int|null $merchantId Brand id, or null/0 for the All-Brands scope.
     * @return array<int, array<string, mixed>> Devices, each with an `online` column.
     */
    public function listDeviceStatuses(?int $merchantId): array
    {
        $repo = ($merchantId === null || $merchantId === 0)
            ? $this->devices->forAllTenants()
            : $this->devices->forTenant($merchantId);
        return $repo->listWithLiveStatus();
    }

    /**
     * Device statuses a specific BRAND should see: its own devices PLUS the global All-Brands (platform)
     * devices, which serve every brand. Mirrors how filter rules and manual gateways expose platform-owned
     * records to each brand, so a device paired under All Brands also shows under each brand (issue #3).
     *
     * @param int $brandId    The brand's own merchant id (must be > 0).
     * @param int $platformId The reserved All-Brands (platform) merchant id.
     * @return array<int, array<string, mixed>> Devices, each with a derived `online` column.
     */
    public function listDeviceStatusesForBrand(int $brandId, int $platformId): array
    {
        return $this->devices->listWithLiveStatusForBrand($brandId, $platformId);
    }
}
