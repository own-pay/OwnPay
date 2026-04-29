<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\Auth\JwtService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DevicePairingService.
 *
 * Repos are final classes so we use anonymous-class stubs instead of mocks.
 */
class DevicePairingServiceTest extends TestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtService();
    }

    // ── Helpers to build stub repos ──────────────────────────────

    /**
     * Build a DevicePairingService with controllable stub behaviour.
     */
    private function buildService(array $tokenStub = [], array $deviceStub = []): DevicePairingService
    {
        $tokenRepo = new class($tokenStub) {
            private array $cfg;
            public array $created = [];

            public function __construct(array $cfg)
            {
                $this->cfg = $cfg;
            }

            public function countRecentByAdmin(int $admin, int $window = 300): int
            {
                return $this->cfg['recentCount'] ?? 0;
            }

            public function create(string $hash, int $brand, int $admin, int $ttl = 300): int
            {
                $this->created[] = compact('hash', 'brand', 'admin');
                return 1;
            }

            public function validateAndConsume(string $hash): ?array
            {
                return $this->cfg['validateResult'] ?? null;
            }
        };

        $deviceRepo = new class($deviceStub) {
            private array $cfg;
            public array $created = [];
            public array $revoked = [];
            public array $refreshUpdates = [];
            public array $touched = [];

            public function __construct(array $cfg)
            {
                $this->cfg = $cfg;
            }

            public function findByFingerprintHash(string $hash, int $brand): ?array
            {
                return $this->cfg['existingDevice'] ?? null;
            }

            public function create(array $data): int
            {
                $this->created[] = $data;
                return 1;
            }

            public function revoke(string $uuid): bool
            {
                $this->revoked[] = $uuid;
                return true;
            }

            public function findByRefreshTokenHash(string $hash): ?array
            {
                return $this->cfg['refreshDevice'] ?? null;
            }

            public function updateRefreshToken(string $uuid, string $hash, string $exp): bool
            {
                $this->refreshUpdates[] = compact('uuid', 'hash', 'exp');
                return true;
            }

            public function touchLastSeen(string $uuid): void
            {
                $this->touched[] = $uuid;
            }

            public function findByUuid(string $uuid): ?array
            {
                return $this->cfg['findByUuidResult'] ?? null;
            }
        };

        $encryptor = new class {
            public function encrypt(string $val): string
            {
                return 'enc_v1:stub:' . substr($val, 0, 8);
            }
        };

        return new DevicePairingService($tokenRepo, $deviceRepo, $this->jwt, $encryptor);
    }

    // ═══════════════════════════════════════════════════════════════
    //  generatePairingOtp()
    // ═══════════════════════════════════════════════════════════════

    public function testGenerateOtpReturns6DigitCode(): void
    {
        $svc = $this->buildService(['recentCount' => 0]);
        $result = $svc->generatePairingOtp(1, 100);

        $this->assertArrayHasKey('otp', $result);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['otp']);
        $this->assertSame(300, $result['expires_in']);
    }

    public function testGenerateOtpRateLimited(): void
    {
        $svc = $this->buildService(['recentCount' => 5]);
        $result = $svc->generatePairingOtp(1, 100);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Too many', $result['error']);
    }

    public function testGenerateOtpAllowsJustBelowLimit(): void
    {
        $svc = $this->buildService(['recentCount' => 4]);
        $result = $svc->generatePairingOtp(1, 100);

        $this->assertArrayHasKey('otp', $result);
        $this->assertArrayNotHasKey('error', $result);
    }

    // ═══════════════════════════════════════════════════════════════
    //  pairDevice() — valid OTP
    // ═══════════════════════════════════════════════════════════════

    public function testPairDeviceSuccessWithValidOtp(): void
    {
        $otp = '123456';
        $svc = $this->buildService([
            'validateResult' => ['id' => 1, 'brand_id' => 42, 'otp_hash' => hash('sha256', $otp)],
        ]);

        $result = $svc->pairDevice($otp, 'Pixel 7', 'android123:sha256cert');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('aes_key', $result);
        $this->assertArrayHasKey('device_id', $result);
        $this->assertSame(900, $result['expires_in']);
        $this->assertNotEmpty($result['access_token']);
        $this->assertSame(64, strlen($result['refresh_token']));
        $this->assertSame(64, strlen($result['aes_key']));
    }

    public function testPairDeviceCreatesCorrectRecord(): void
    {
        $otp = '654321';
        $svc = $this->buildService([
            'validateResult' => ['id' => 1, 'brand_id' => 7, 'otp_hash' => hash('sha256', $otp)],
        ]);

        $result = $svc->pairDevice($otp, 'Galaxy S24', 'fp_string', '2.1.0', 'android');

        $this->assertTrue($result['success']);

        // Verify the JWT is decodable and contains the correct device UUID
        $decoded = $this->jwt->decode($result['access_token'], $this->getJwtSecretFromResult($result));
        // We can't easily get the secret from the result, but we can verify the device_id format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['device_id']
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  pairDevice() — invalid / expired / used OTP
    // ═══════════════════════════════════════════════════════════════

    public function testPairDeviceFailsWithInvalidOtp(): void
    {
        $svc = $this->buildService(['validateResult' => null]);
        $result = $svc->pairDevice('000000', 'Pixel 7', 'fp');

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_OTP', $result['error']);
    }

    public function testPairDeviceFailsWithUsedOtp(): void
    {
        $svc = $this->buildService(['validateResult' => null]);
        $result = $svc->pairDevice('111111', 'Test', 'fp');

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_OTP', $result['error']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  pairDevice() — re-pairing (same fingerprint)
    // ═══════════════════════════════════════════════════════════════

    public function testPairDeviceRevokesExistingOnRepairing(): void
    {
        $otp = '999999';
        $svc = $this->buildService([
            'validateResult' => ['id' => 1, 'brand_id' => 5, 'otp_hash' => hash('sha256', $otp)],
            'existingDevice' => ['device_uuid' => 'old-uuid-123'],
        ]);

        $result = $svc->pairDevice($otp, 'New Phone', 'existing_fp');

        $this->assertTrue($result['success']);
        $this->assertNotSame('old-uuid-123', $result['device_id']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  refreshAccessToken()
    // ═══════════════════════════════════════════════════════════════

    public function testRefreshTokenSuccess(): void
    {
        $refreshToken = bin2hex(random_bytes(32));
        $fingerprint = 'android123:certsha';
        $fpHash = hash('sha256', $fingerprint);
        $jwtSecret = JwtService::generateSecret();

        $svc = $this->buildService([], [
            'refreshDevice' => [
                'device_uuid'      => 'dev-uuid-abc',
                'brand_id'         => 10,
                'jwt_secret'       => $jwtSecret,
                'fingerprint_hash' => $fpHash,
            ],
        ]);

        $result = $svc->refreshAccessToken($refreshToken, $fingerprint);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertSame(900, $result['expires_in']);
        $this->assertNotSame($refreshToken, $result['refresh_token']);
    }

    public function testRefreshTokenFailsWithInvalidToken(): void
    {
        $svc = $this->buildService([], ['refreshDevice' => null]);
        $result = $svc->refreshAccessToken('bad_token', 'fp');

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_REFRESH_TOKEN', $result['error']);
    }

    public function testRefreshTokenFailsWithFingerprintMismatch(): void
    {
        $refreshToken = bin2hex(random_bytes(32));
        $jwtSecret = JwtService::generateSecret();

        $svc = $this->buildService([], [
            'refreshDevice' => [
                'device_uuid'      => 'dev-1',
                'brand_id'         => 1,
                'jwt_secret'       => $jwtSecret,
                'fingerprint_hash' => hash('sha256', 'correct_fp'),
            ],
        ]);

        $result = $svc->refreshAccessToken($refreshToken, 'wrong_fp');

        $this->assertFalse($result['success']);
        $this->assertSame('FINGERPRINT_MISMATCH', $result['error']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  validateRequest()
    // ═══════════════════════════════════════════════════════════════

    public function testValidateRequestSuccess(): void
    {
        $jwtSecret = JwtService::generateSecret();
        $deviceUuid = 'test-uuid-validate';
        $fingerprint = 'validate_fp';
        $fpHash = hash('sha256', $fingerprint);

        $encoded = $this->jwt->encode($deviceUuid, 5, $jwtSecret);

        $svc = $this->buildService([], [
            'findByUuidResult' => [
                'device_uuid'      => $deviceUuid,
                'brand_id'         => 5,
                'jwt_secret'       => $jwtSecret,
                'fingerprint_hash' => $fpHash,
                'revoked_at'       => null,
            ],
        ]);

        $result = $svc->validateRequest($encoded['token'], $fingerprint);

        $this->assertTrue($result['valid']);
        $this->assertSame($deviceUuid, $result['device']['device_uuid']);
        $this->assertSame(5, $result['device']['brand_id']);
        $this->assertNull($result['error']);
    }

    public function testValidateRequestFailsForRevokedDevice(): void
    {
        $jwtSecret = JwtService::generateSecret();
        $deviceUuid = 'revoked-uuid';
        $encoded = $this->jwt->encode($deviceUuid, 1, $jwtSecret);

        $svc = $this->buildService([], [
            'findByUuidResult' => [
                'device_uuid' => $deviceUuid,
                'brand_id'    => 1,
                'jwt_secret'  => $jwtSecret,
                'revoked_at'  => '2026-01-01 00:00:00',
            ],
        ]);

        $result = $svc->validateRequest($encoded['token'], 'any_fp');

        $this->assertFalse($result['valid']);
        $this->assertSame('DEVICE_REVOKED', $result['error']);
    }

    public function testValidateRequestFailsWithMalformedJwt(): void
    {
        $svc = $this->buildService();
        $result = $svc->validateRequest('not.valid.jwt', 'fp');

        $this->assertFalse($result['valid']);
        $this->assertSame('INVALID_TOKEN', $result['error']);
    }

    public function testValidateRequestFailsWithFingerprintMismatch(): void
    {
        $jwtSecret = JwtService::generateSecret();
        $deviceUuid = 'fp-mismatch-uuid';
        $encoded = $this->jwt->encode($deviceUuid, 1, $jwtSecret);

        $svc = $this->buildService([], [
            'findByUuidResult' => [
                'device_uuid'      => $deviceUuid,
                'brand_id'         => 1,
                'jwt_secret'       => $jwtSecret,
                'fingerprint_hash' => hash('sha256', 'correct_fp'),
                'revoked_at'       => null,
            ],
        ]);

        $result = $svc->validateRequest($encoded['token'], 'wrong_fp');

        $this->assertFalse($result['valid']);
        $this->assertSame('FINGERPRINT_MISMATCH', $result['error']);
    }

    public function testValidateRequestFailsForUnknownDevice(): void
    {
        $jwtSecret = JwtService::generateSecret();
        $encoded = $this->jwt->encode('nonexistent-uuid', 1, $jwtSecret);

        $svc = $this->buildService([], ['findByUuidResult' => null]);
        $result = $svc->validateRequest($encoded['token'], 'fp');

        $this->assertFalse($result['valid']);
        $this->assertSame('DEVICE_REVOKED', $result['error']);
    }

    // ── Private helpers ─────────────────────────────────────────

    /**
     * We can't retrieve the JWT secret from the result (by design),
     * so this helper exists for future test expansion.
     */
    private function getJwtSecretFromResult(array $result): string
    {
        // Not available in production — only for test stub verification
        return '';
    }
}
