<?php
declare(strict_types=1);

namespace OwnPay\Service\Device;

use OwnPay\Event\EventManager;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Auth\JwtService;
use OwnPay\Support\DateHelper;

/**
 * Device pairing service â€” manages companion app device lifecycle.
 *
 * Fires: mobile.device.paired, mobile.device.revoked
 * Per PCI-DSS: device AES keys encrypted at rest.
 */
final class DevicePairingService
{
    private PairedDeviceRepository $devices;
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
     * @return array{device_uuid: string, access_token: string, refresh_token: string, aes_key: string}
     */
    public function pair(int $userId, int $merchantId, string $deviceName, string $pushToken = ''): array
    {
        // Generate device UUID and AES key
        $deviceUuid = bin2hex(random_bytes(16));
        $aesKey = bin2hex(random_bytes(32)); // 256-bit AES key

        // Encrypt AES key at rest
        $aesKeyEncrypted = $this->encryptor->encrypt($aesKey);

        $this->devices->forTenant($merchantId)->createScoped([
            'device_id'         => $deviceUuid,
            'user_id'           => $userId,
            'device_name'       => $deviceName,
            'push_token'        => $pushToken,
            'aes_key_encrypted' => $aesKeyEncrypted,
            'status'            => 'active',
            'last_heartbeat'    => DateHelper::now(),
        ]);

        // Issue JWT tokens
        $accessToken = $this->jwt->issue($userId, $merchantId, $deviceUuid);
        $refreshToken = $this->jwt->issueRefreshToken($userId, $merchantId, $deviceUuid);

        $this->events->doAction('mobile.device.paired', $deviceUuid, $merchantId, $userId);

        return [
            'device_uuid'   => $deviceUuid,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'aes_key'       => $aesKey, // Sent only once, stored on device
        ];
    }

    /**
     * Revoke device â€” deactivate and invalidate.
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
