<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\DevicePairingTokenRepository;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\Auth\JwtService;

class DevicePairingIntegrationTest extends IntegrationTestCase
{
    private DevicePairingService $pairingService;
    private PairedDeviceRepository $deviceRepo;
    private DevicePairingTokenRepository $tokenRepo;
    private JwtService $jwt;

    private array $createdDeviceUuids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::getInstance();
        if (static::$dbAvailable) {
            $merchant = $db->fetchOne("SELECT * FROM op_merchants WHERE id = 12 LIMIT 1");
            if ($merchant === null) {
                $db->execute(
                    "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
                     VALUES (12, 'merchant-uuid-12', 'Test Merchant 12', 'test-merchant-12', 'test12@example.com', 'active', '{}')"
                );
            }

            $db->pdo()->exec("DELETE FROM op_device_pairing_tokens WHERE created_by = 12 OR merchant_id = 1 OR merchant_id = 12");
        }
        $this->tokenRepo = (new DevicePairingTokenRepository($db))->forTenant(12);
        $this->deviceRepo = (new PairedDeviceRepository($db))->forTenant(12);
        $this->jwt = new JwtService();
        $this->pairingService = new DevicePairingService(
            $this->deviceRepo,
            new \OwnPay\Security\FieldEncryptor('test-key-32-chars-long-placeholder'),
            $this->jwt,
            null
        );
    }

    protected function tearDown(): void
    {
        if (!static::$dbAvailable) {
            return;
        }

        $pdo = Database::getInstance()->pdo();

        foreach ($this->createdDeviceUuids as $uuid) {
            $pdo->prepare("DELETE FROM op_paired_devices WHERE device_id = ?")->execute([$uuid]);
        }

        $pdo->exec("DELETE FROM op_device_pairing_tokens WHERE created_by = 12 OR merchant_id = 1 OR merchant_id = 12");
        $pdo->exec("DELETE FROM op_merchants WHERE id = 12");
    }

    public function testFullPairingLifecycle(): void
    {
        $otpResult = $this->pairingService->generatePairingOtp(12, 12);

        $this->assertArrayHasKey('otp', $otpResult, 'OTP generation should return an OTP');
        $this->assertSame(300, $otpResult['expires_in']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otpResult['otp']);

        $otp = $otpResult['otp'];

        $fingerprint = 'integration_test_android_id:integration_test_cert';
        $pairResult = $this->pairingService->pairDevice(
            $otp,
            'Integration Test Device',
            $fingerprint,
            '1.0.0-test',
            'android'
        );

        $this->assertTrue($pairResult['success'], 'Pairing should succeed with valid OTP');
        $this->assertNotEmpty($pairResult['access_token']);
        $this->assertNotEmpty($pairResult['refresh_token']);
        $this->assertNotEmpty($pairResult['aes_key']);
        $this->assertNotEmpty($pairResult['device_id']);
        $this->assertSame(900, $pairResult['expires_in']);

        $deviceUuid = $pairResult['device_id'];
        $this->createdDeviceUuids[] = $deviceUuid;

        $device = $this->deviceRepo->findByUuid($deviceUuid);
        $this->assertNotNull($device, 'Device should exist in DB after pairing');
        $this->assertSame('Integration Test Device', $device['device_name']);
        $this->assertSame('android', $device['platform']);
        $this->assertSame('active', $device['status']);
        $this->assertTrue($this->deviceRepo->isActive($deviceUuid));

        $rePairResult = $this->pairingService->pairDevice($otp, 'Attacker', 'bad_fp');
        $this->assertFalse($rePairResult['success'], 'Used OTP should be rejected');
        $this->assertSame('INVALID_OTP', $rePairResult['error']);

        $validateResult = $this->pairingService->validateRequest(
            $pairResult['access_token'],
            $fingerprint
        );
        $this->assertTrue($validateResult['valid'], 'JWT should validate successfully');
        $this->assertSame($deviceUuid, $validateResult['device']['device_uuid']);
        $this->assertSame(12, $validateResult['device']['brand_id']);
        $this->assertNull($validateResult['error']);

        $wrongFpResult = $this->pairingService->validateRequest(
            $pairResult['access_token'],
            'wrong_fingerprint'
        );
        $this->assertFalse($wrongFpResult['valid']);
        $this->assertSame('FINGERPRINT_MISMATCH', $wrongFpResult['error']);

        $refreshResult = $this->pairingService->refreshAccessToken(
            $pairResult['refresh_token'],
            $fingerprint
        );

        $this->assertTrue($refreshResult['success'], 'Token refresh should succeed');
        $this->assertNotEmpty($refreshResult['access_token']);
        $this->assertNotEmpty($refreshResult['refresh_token']);
        $this->assertNotSame($pairResult['refresh_token'], $refreshResult['refresh_token'],
            'Refresh token should be rotated');

        // Access tokens CAN be identical if generated within the same second
        // (same iat/exp + same secret = deterministic HMAC). The critical assertion
        // is that the refresh token was rotated (tested above).

        $oldRefreshResult = $this->pairingService->refreshAccessToken(
            $pairResult['refresh_token'],
            $fingerprint
        );
        $this->assertFalse($oldRefreshResult['success'],
            'Old refresh token should be invalid after rotation');

        $newValidate = $this->pairingService->validateRequest(
            $refreshResult['access_token'],
            $fingerprint
        );
        $this->assertTrue($newValidate['valid'], 'New JWT from refresh should validate');

        $this->pairingService->revoke($deviceUuid, 12);

        $revokedDevice = $this->deviceRepo->findByUuid($deviceUuid);
        $this->assertSame('revoked', $revokedDevice['status'], 'Device should be marked revoked');
        $this->assertFalse($this->deviceRepo->isActive($deviceUuid));

        $postRevokeValidate = $this->pairingService->validateRequest(
            $refreshResult['access_token'],
            $fingerprint
        );
        $this->assertFalse($postRevokeValidate['valid']);
        $this->assertSame('DEVICE_REVOKED', $postRevokeValidate['error']);

        $postRevokeRefresh = $this->pairingService->refreshAccessToken(
            $refreshResult['refresh_token'],
            $fingerprint
        );
        $this->assertFalse($postRevokeRefresh['success']);
    }

    public function testRepairingRevokesOldDevice(): void
    {
        $fingerprint = 'repairing_test_fp:cert_sha';

        $otp1 = $this->pairingService->generatePairingOtp(12, 12);
        $pair1 = $this->pairingService->pairDevice($otp1['otp'], 'Phone v1', $fingerprint, '1.0');
        $this->assertTrue($pair1['success']);
        $this->createdDeviceUuids[] = $pair1['device_id'];

        $oldUuid = $pair1['device_id'];

        $otp2 = $this->pairingService->generatePairingOtp(12, 12);
        $pair2 = $this->pairingService->pairDevice($otp2['otp'], 'Phone v2', $fingerprint, '2.0');
        $this->assertTrue($pair2['success']);
        $this->createdDeviceUuids[] = $pair2['device_id'];

        $oldDevice = $this->deviceRepo->findByUuid($oldUuid);
        $this->assertSame('revoked', $oldDevice['status'], 'Old device should be auto-revoked on re-pair');

        $this->assertTrue($this->deviceRepo->isActive($pair2['device_id']));
    }

    public function testOtpRateLimiting(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $result = $this->pairingService->generatePairingOtp(12, 12);
            $this->assertArrayHasKey('otp', $result, "OTP #{$i} should succeed");
        }

        $result = $this->pairingService->generatePairingOtp(12, 12);
        $this->assertArrayHasKey('error', $result, 'Should be rate limited after 5 OTPs');
        $this->assertStringContainsString('Too many', $result['error']);
    }
}
