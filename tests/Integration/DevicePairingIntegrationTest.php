<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\DevicePairingTokenRepository;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\Auth\JwtService;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration test: Full device pairing lifecycle against live DB.
 *
 * Flow: Generate OTP → Pair device → Get JWT → Refresh token → Validate → Revoke
 *
 * Requires: DB tables from migration 008_mobile_companion_tables.sql
 */
class DevicePairingIntegrationTest extends IntegrationTestCase
{
    private DevicePairingService $pairingService;
    private PairedDeviceRepository $deviceRepo;
    private DevicePairingTokenRepository $tokenRepo;
    private JwtService $jwt;

    /** Track created records for cleanup */
    private array $createdDeviceUuids = [];

    protected function setUp(): void
    {
        parent::setUp(); // triggers DB-available skip check

        $db = Database::getInstance();
        if (static::$dbAvailable) {
            // Ensure merchant 12 exists
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

        // Clean up test data
        $pdo = Database::getInstance()->pdo();

        foreach ($this->createdDeviceUuids as $uuid) {
            $pdo->prepare("DELETE FROM op_paired_devices WHERE device_id = ?")->execute([$uuid]);
        }

        // Clean up test tokens
        $pdo->exec("DELETE FROM op_device_pairing_tokens WHERE created_by = 12 OR merchant_id = 1 OR merchant_id = 12");

        // Clean up merchant 12
        $pdo->exec("DELETE FROM op_merchants WHERE id = 12");
    }

    // ═══════════════════════════════════════════════════════════════
    //  Full Pairing Lifecycle
    // ═══════════════════════════════════════════════════════════════

    public function testFullPairingLifecycle(): void
    {
        // -- Step 1: Admin generates OTP --------------------------
        $otpResult = $this->pairingService->generatePairingOtp(12, 12);

        $this->assertArrayHasKey('otp', $otpResult, 'OTP generation should return an OTP');
        $this->assertSame(300, $otpResult['expires_in']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otpResult['otp']);

        $otp = $otpResult['otp'];

        // -- Step 2: Mobile app pairs with OTP --------------------
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

        // Verify device record exists in DB
        $device = $this->deviceRepo->findByUuid($deviceUuid);
        $this->assertNotNull($device, 'Device should exist in DB after pairing');
        $this->assertSame('Integration Test Device', $device['device_name']);
        $this->assertSame('android', $device['platform']);
        $this->assertSame('active', $device['status']);
        $this->assertTrue($this->deviceRepo->isActive($deviceUuid));

        // -- Step 3: Verify the OTP is consumed (cannot reuse) ---
        $rePairResult = $this->pairingService->pairDevice($otp, 'Attacker', 'bad_fp');
        $this->assertFalse($rePairResult['success'], 'Used OTP should be rejected');
        $this->assertSame('INVALID_OTP', $rePairResult['error']);

        // -- Step 4: Validate JWT on a subsequent API call --------
        $validateResult = $this->pairingService->validateRequest(
            $pairResult['access_token'],
            $fingerprint
        );
        $this->assertTrue($validateResult['valid'], 'JWT should validate successfully');
        $this->assertSame($deviceUuid, $validateResult['device']['device_uuid']);
        $this->assertSame(12, $validateResult['device']['brand_id']);
        $this->assertNull($validateResult['error']);

        // -- Step 5: Validate fails with wrong fingerprint --------
        $wrongFpResult = $this->pairingService->validateRequest(
            $pairResult['access_token'],
            'wrong_fingerprint'
        );
        $this->assertFalse($wrongFpResult['valid']);
        $this->assertSame('FINGERPRINT_MISMATCH', $wrongFpResult['error']);

        // -- Step 6: Refresh the access token ---------------------
        $refreshResult = $this->pairingService->refreshAccessToken(
            $pairResult['refresh_token'],
            $fingerprint
        );

        $this->assertTrue($refreshResult['success'], 'Token refresh should succeed');
        $this->assertNotEmpty($refreshResult['access_token']);
        $this->assertNotEmpty($refreshResult['refresh_token']);
        $this->assertNotSame($pairResult['refresh_token'], $refreshResult['refresh_token'],
            'Refresh token should be rotated');
        // Note: access tokens CAN be identical if generated within the same second
        // (same iat/exp + same secret = deterministic HMAC). The critical assertion
        // is that the refresh token was rotated (tested above).

        // -- Step 7: Old refresh token is now invalid -------------
        $oldRefreshResult = $this->pairingService->refreshAccessToken(
            $pairResult['refresh_token'],
            $fingerprint
        );
        $this->assertFalse($oldRefreshResult['success'],
            'Old refresh token should be invalid after rotation');

        // -- Step 8: New access token works -----------------------
        $newValidate = $this->pairingService->validateRequest(
            $refreshResult['access_token'],
            $fingerprint
        );
        $this->assertTrue($newValidate['valid'], 'New JWT from refresh should validate');

        // -- Step 9: Admin revokes the device ---------------------
        $this->pairingService->revoke($deviceUuid, 12);

        $revokedDevice = $this->deviceRepo->findByUuid($deviceUuid);
        $this->assertSame('revoked', $revokedDevice['status'], 'Device should be marked revoked');
        $this->assertFalse($this->deviceRepo->isActive($deviceUuid));

        // -- Step 10: JWT now fails validation (revoked) ----------
        $postRevokeValidate = $this->pairingService->validateRequest(
            $refreshResult['access_token'],
            $fingerprint
        );
        $this->assertFalse($postRevokeValidate['valid']);
        $this->assertSame('DEVICE_REVOKED', $postRevokeValidate['error']);

        // -- Step 11: Refresh also fails after revocation ---------
        $postRevokeRefresh = $this->pairingService->refreshAccessToken(
            $refreshResult['refresh_token'],
            $fingerprint
        );
        $this->assertFalse($postRevokeRefresh['success']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Re-pairing: same fingerprint auto-revokes old device
    // ═══════════════════════════════════════════════════════════════

    public function testRepairingRevokesOldDevice(): void
    {
        $fingerprint = 'repairing_test_fp:cert_sha';

        // First pairing
        $otp1 = $this->pairingService->generatePairingOtp(12, 12);
        $pair1 = $this->pairingService->pairDevice($otp1['otp'], 'Phone v1', $fingerprint, '1.0');
        $this->assertTrue($pair1['success']);
        $this->createdDeviceUuids[] = $pair1['device_id'];

        $oldUuid = $pair1['device_id'];

        // Second pairing with same fingerprint
        $otp2 = $this->pairingService->generatePairingOtp(12, 12);
        $pair2 = $this->pairingService->pairDevice($otp2['otp'], 'Phone v2', $fingerprint, '2.0');
        $this->assertTrue($pair2['success']);
        $this->createdDeviceUuids[] = $pair2['device_id'];

        // Old device should be revoked
        $oldDevice = $this->deviceRepo->findByUuid($oldUuid);
        $this->assertSame('revoked', $oldDevice['status'], 'Old device should be auto-revoked on re-pair');

        // New device should be active
        $this->assertTrue($this->deviceRepo->isActive($pair2['device_id']));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Rate limiting: OTP generation
    // ═══════════════════════════════════════════════════════════════

    public function testOtpRateLimiting(): void
    {
        // Generate 5 OTPs (the limit)
        for ($i = 0; $i < 5; $i++) {
            $result = $this->pairingService->generatePairingOtp(12, 12);
            $this->assertArrayHasKey('otp', $result, "OTP #{$i} should succeed");
        }

        // 6th should be rate limited
        $result = $this->pairingService->generatePairingOtp(12, 12);
        $this->assertArrayHasKey('error', $result, 'Should be rate limited after 5 OTPs');
        $this->assertStringContainsString('Too many', $result['error']);
    }
}
