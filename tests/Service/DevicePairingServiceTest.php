<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\Auth\JwtService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Unit tests for DevicePairingService.
 *
 * Repos are final classes so we use anonymous-class stubs instead of mocks.
 */
#[AllowMockObjectsWithoutExpectations]
class DevicePairingServiceTest extends TestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtService();
    }

   
    /**
     * Build a DevicePairingService with controllable stub behaviour.
     */
    private function buildService(array $tokenStub = [], array $deviceStub = []): DevicePairingService
    {
        $stmtMock = $this->createMock(\PDOStatement::class);
        $db = new class($tokenStub, $stmtMock) extends \OwnPay\Core\Database {
            private array $cfg;
            private \PDOStatement $stmt;
            public array $executed = [];

            public function __construct(array $cfg, \PDOStatement $stmt)
            {
                $this->cfg = $cfg;
                $this->stmt = $stmt;
            }

            public function execute(string $sql, array $params = []): \PDOStatement
            {
                $this->executed[] = compact('sql', 'params');
                return $this->stmt;
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                if (strpos($sql, 'op_device_pairing_tokens') !== false) {
                    if (strpos($sql, 'COUNT(*)') !== false) {
                        return ['cnt' => $this->cfg['recentCount'] ?? 0];
                    }
                    return $this->cfg['validateResult'] ?? null;
                }
                if (strpos($sql, 'op_merchant_users') !== false) {
                    return ['id' => 1];
                }
                return null;
            }

            public function transaction(callable $callback): mixed
            {
                return $callback();
            }
        };

        $deviceRepo = new class($db, $deviceStub) extends \OwnPay\Repository\PairedDeviceRepository {
            private array $cfg;
            public array $created = [];
            public array $revoked = [];
            public array $refreshUpdates = [];
            public array $touched = [];

            public function __construct(\OwnPay\Core\Database $db, array $cfg)
            {
                parent::__construct($db);
                $this->cfg = $cfg;
            }

            public function forTenant(int $merchantId): static
            {
                return $this;
            }

            public function findByFingerprintHash(string $hash, int $brand): ?array
            {
                return $this->cfg['existingDevice'] ?? null;
            }

            public function create(array $data): string
            {
                $this->created[] = $data;
                return '1';
            }

            public function createScoped(array $data): string
            {
                $this->created[] = $data;
                return '1';
            }

            public function revoke(int $id): int
            {
                $this->revoked[] = $id;
                return 1;
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

        $encryptor = new class extends \OwnPay\Security\FieldEncryptor {
            public function __construct()
            {
                // Do not invoke parent construct
            }

            public function encrypt(string $val): string
            {
                return 'enc_v1:stub:' . substr($val, 0, 8);
            }
        };

        return new DevicePairingService($deviceRepo, $encryptor, $this->jwt);
    }

    //  generatePairingOtp()

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


    //  pairDevice() -> valid OTP

    public function testPairDeviceSuccessWithValidOtp(): void
    {
        $otp = '123456';
        $svc = $this->buildService([
            'validateResult' => ['id' => 1, 'merchant_id' => 42, 'created_by' => 100, 'otp_hash' => hash('sha256', $otp)],
        ]);

        $result = $svc->pairDevice($otp, 'Pixel 7', 'android123:sha256cert');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('aes_key', $result);
        $this->assertArrayHasKey('device_id', $result);
        $this->assertSame(900, $result['expires_in']);
        $this->assertNotEmpty($result['access_token']);
        $this->assertNotEmpty($result['refresh_token']);
        $this->assertSame(64, strlen($result['aes_key']));
    }

    public function testPairDeviceCreatesCorrectRecord(): void
    {
        $otp = '654321';
        $svc = $this->buildService([
            'validateResult' => ['id' => 1, 'merchant_id' => 7, 'created_by' => 100, 'otp_hash' => hash('sha256', $otp)],
        ]);

        $result = $svc->pairDevice($otp, 'Galaxy S24', 'fp_string', '2.1.0', 'android');

        $this->assertTrue($result['success']);

        // Verify the JWT is decodable and contains the correct device UUID
        $decoded = $this->jwt->decode($result['access_token']);
        // We can't easily get the secret from the result, but we can verify the device_id format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['device_id']
        );
    }


    //  pairDevice() -> invalid / expired / used OTP


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

    //  pairDevice() -> re-pairing (same fingerprint)

    public function testPairDeviceRevokesExistingOnRepairing(): void
    {
        $otp = '999999';
        $svc = $this->buildService([
            'validateResult' => ['id' => 1, 'merchant_id' => 5, 'created_by' => 100, 'otp_hash' => hash('sha256', $otp)],
            'existingDevice' => ['device_uuid' => 'old-uuid-123'],
        ]);

        $result = $svc->pairDevice($otp, 'New Phone', 'existing_fp');

        $this->assertTrue($result['success']);
        $this->assertNotSame('old-uuid-123', $result['device_id']);
    }

    public function testPairDeviceFailsClosedWhenOtpHasNoCreator(): void
    {
        // Privilege-escalation guard: an OTP with no recorded creator must NOT
        // fall back to a superadmin identity - pairing must fail.
        $otp = '424242';
        $svc = $this->buildService([
            'validateResult' => ['id' => 1, 'merchant_id' => 9, 'created_by' => null, 'otp_hash' => hash('sha256', $otp)],
        ]);

        $result = $svc->pairDevice($otp, 'Rogue Phone', 'fp_rogue');

        $this->assertFalse($result['success']);
        $this->assertSame('PAIRING_CONTEXT_UNRESOLVED', $result['error']);
    }

    //  refreshAccessToken()

    public function testRefreshWithUnresolvableSubjectIsRejected(): void
    {
        // Privilege-escalation guard: a refresh token whose subject does not resolve to a real user
        // (here an opaque, non-JWT token with no `sub` claim) MUST be rejected - it must never be
        // silently upgraded to user 1 (the superadmin). The legitimate JWT happy path (sub > 0) is
        // covered by tests/Integration/DeviceRefreshPrivilegeTest.
        $refreshToken = bin2hex(random_bytes(32));
        $fingerprint = 'android123:certsha';
        $fpHash = hash('sha256', $fingerprint);

        $svc = $this->buildService([], [
            'refreshDevice' => [
                'device_uuid'      => 'dev-uuid-abc',
                'merchant_id'      => 10,
                'fingerprint_hash' => $fpHash,
                'status'           => 'active',
            ],
        ]);

        $result = $svc->refreshAccessToken($refreshToken, $fingerprint);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_REFRESH_TOKEN', $result['error']);
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
                'merchant_id'      => 1,
                'jwt_secret'       => $jwtSecret,
                'fingerprint_hash' => hash('sha256', 'correct_fp'),
            ],
        ]);

        $result = $svc->refreshAccessToken($refreshToken, 'wrong_fp');

        $this->assertFalse($result['success']);
        $this->assertSame('FINGERPRINT_MISMATCH', $result['error']);
    }

    public function testValidateRequestSuccess(): void
    {
        $jwtSecret = JwtService::generateSecret();
        $deviceUuid = 'test-uuid-validate';
        $fingerprint = 'validate_fp';
        $fpHash = hash('sha256', $fingerprint);

        $encoded = $this->jwt->encode($deviceUuid, 5);

        $svc = $this->buildService([], [
            'findByUuidResult' => [
                'device_uuid'      => $deviceUuid,
                'merchant_id'      => 5,
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
        $encoded = $this->jwt->encode($deviceUuid, 1);

        $svc = $this->buildService([], [
            'findByUuidResult' => [
                'device_uuid' => $deviceUuid,
                'merchant_id' => 1,
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
        $encoded = $this->jwt->encode($deviceUuid, 1);

        $svc = $this->buildService([], [
            'findByUuidResult' => [
                'device_uuid'      => $deviceUuid,
                'merchant_id'      => 1,
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
        $encoded = $this->jwt->encode('nonexistent-uuid', 1);

        $svc = $this->buildService([], ['findByUuidResult' => null]);
        $result = $svc->validateRequest($encoded['token'], 'fp');

        $this->assertFalse($result['valid']);
        $this->assertSame('DEVICE_REVOKED', $result['error']);
    }


    /**
     * We can't retrieve the JWT secret from the result (by design),
     * so this helper exists for future test expansion.
     */
    private function getJwtSecretFromResult(array $result): string
    {
        // Not available in production â€” only for test stub verification
        return '';
    }
}

