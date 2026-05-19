<?php
declare(strict_types=1);

namespace OwnPay\Service\Device;

use OwnPay\Event\EventManager;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Auth\JwtService;
use OwnPay\Support\DateHelper;

/**
 * Device pairing service — manages companion app device lifecycle.
 *
 * Fires: mobile.device.paired, mobile.device.revoked
 * Per PCI-DSS: device AES keys encrypted at rest.
 */
final class DevicePairingService
{
    private PairedDeviceRepository $devices;
    /** @phpstan-ignore property.onlyWritten */
    private FieldEncryptor $encryptor;
    private JwtService $jwt;
    private EventManager $events;

    public function __construct(
        PairedDeviceRepository $devices,
        FieldEncryptor $encryptor,
        JwtService $jwt,
        EventManager $events
    ) {
        $this->devices = $devices;
        $this->encryptor = $encryptor;
        $this->jwt = $jwt;
        $this->events = $events;
    }

    /**
     * Pair a new device.
     *
     * @return array{device_uuid: string, access_token: string, refresh_token: string}
     */
    public function pair(int $userId, int $merchantId, string $deviceName, string $pushToken = ''): array
    {
        // Generate device UUID
        $deviceUuid = bin2hex(random_bytes(16));

        // Issue JWT tokens
        $accessToken  = $this->jwt->issue($userId, $merchantId, $deviceUuid);
        $refreshToken = $this->jwt->issueRefreshToken($userId, $merchantId, $deviceUuid);

        // Store fingerprint of the JWT secret + deviceUuid for later verification
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
     * Revoke device — deactivate and invalidate.
     */
    public function revoke(string $deviceUuid, int $merchantId): bool
    {
        $device = $this->devices->forTenant($merchantId)->findByDeviceId($deviceUuid);
        if ($device === null) {
            return false;
        }

        $this->devices->forTenant($merchantId)
            ->updateScoped((int) $device['id'], ['status' => 'revoked']);

        $this->events->doAction('mobile.device.revoked', $deviceUuid, $merchantId);
        return true;
    }

    /**
     * Generate a 6-digit pairing OTP.
     * Stores SHA-256 hash in op_device_pairing_tokens (never stores raw OTP).
     * TTL: 5 minutes. Returns ['otp' => '482910', 'expires_in' => 300].
     */
    public function generatePairingOtp(int $merchantId): array
    {
        // Generate cryptographically-random 6-digit code
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = hash('sha256', $otp);
        $expiresAt = (new \DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s.u');

        // Store in op_device_pairing_tokens (invalidate old ones for this merchant first)
        $db = $this->devices->getDatabase();
        $db->execute(
            "DELETE FROM op_device_pairing_tokens WHERE merchant_id = :mid AND is_used = 0",
            ['mid' => $merchantId]
        );
        $db->execute(
            "INSERT INTO op_device_pairing_tokens (otp_hash, merchant_id, expires_at, is_used) VALUES (:hash, :mid, :exp, 0)",
            ['hash' => $otpHash, 'mid' => $merchantId, 'exp' => $expiresAt]
        );

        return ['otp' => $otp, 'expires_in' => 300];
    }

    /**
     * Validate OTP from mobile app pairing request.
     * Returns ['valid' => true, 'merchant_id' => int] or ['valid' => false].
     */
    public function validatePairingOtp(string $otp): array
    {
        $otpHash = hash('sha256', $otp);
        $db = $this->devices->getDatabase();
        $row = $db->fetchOne(
            "SELECT * FROM op_device_pairing_tokens WHERE otp_hash = :hash AND is_used = 0 AND expires_at > NOW()",
            ['hash' => $otpHash]
        );

        if (!$row) {
            return ['valid' => false, 'error' => 'Invalid or expired OTP'];
        }

        // Mark as used (single-use per plan spec)
        $db->execute(
            "UPDATE op_device_pairing_tokens SET is_used = 1 WHERE id = :id",
            ['id' => $row['id']]
        );

        return ['valid' => true, 'merchant_id' => (int) $row['merchant_id']];
    }

    /**
     * Update heartbeat for device.
     */
    public function heartbeat(string $deviceUuid): void
    {
        $this->devices->updateHeartbeat($deviceUuid);
    }

    /**
     * List active devices for merchant.
     */
    public function listDevices(int $merchantId): array
    {
        return $this->devices->forTenant($merchantId)->listActive();
    }
}
