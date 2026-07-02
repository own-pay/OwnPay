<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Service\Auth\JwtService;
use OwnPay\Service\Device\DevicePairingService;

/**
 * Security regression: a mobile refresh token whose subject does not resolve to a real user
 * (sub <= 0) MUST be rejected - it must NOT be silently upgraded to user 1 (the superadmin).
 * Legitimate device tokens (sub > 0) must still refresh.
 */
final class DeviceRefreshPrivilegeTest extends IntegrationTestCase
{
    private Database $db;
    private Container $c;
    private DevicePairingService $svc;
    private JwtService $jwt;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $this->c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->c);
        $this->c->instance(Database::class, $this->db);

        $svc = $this->c->get(DevicePairingService::class);
        $this->assertInstanceOf(DevicePairingService::class, $svc);
        $this->svc = $svc;

        $jwt = $this->c->get(JwtService::class);
        $this->assertInstanceOf(JwtService::class, $jwt);
        $this->jwt = $jwt;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->db->execute("DELETE FROM op_paired_devices WHERE device_id LIKE 'zztest-dev-%'");
    }

    private function insertDevice(string $deviceId, string $fingerprint): void
    {
        $this->db->execute(
            "INSERT INTO op_paired_devices (merchant_id, device_id, device_name, jwt_fingerprint, status)
             VALUES (1, :did, 'ZZ Test Device', :fp, 'active')",
            ['did' => $deviceId, 'fp' => hash('sha256', $fingerprint)]
        );
    }

    public function testZeroSubjectRefreshIsRejectedNotEscalated(): void
    {
        $this->insertDevice('zztest-dev-zero', 'fp-zero');
        $token = $this->jwt->issueRefreshToken(0, 1, 'zztest-dev-zero');

        $res = $this->svc->refreshAccessToken($token, 'fp-zero');

        $this->assertFalse($res['success'], 'sub<=0 refresh must be rejected, never escalated to superadmin');
        $this->assertSame('INVALID_REFRESH_TOKEN', $res['error'] ?? null);
    }

    public function testLegitRefreshStillSucceeds(): void
    {
        $this->insertDevice('zztest-dev-ok', 'fp-ok');
        $token = $this->jwt->issueRefreshToken(5, 1, 'zztest-dev-ok');

        $res = $this->svc->refreshAccessToken($token, 'fp-ok');

        $this->assertTrue($res['success'], 'a valid (sub>0) refresh must still succeed');
        $this->assertArrayHasKey('access_token', $res);
    }
}
