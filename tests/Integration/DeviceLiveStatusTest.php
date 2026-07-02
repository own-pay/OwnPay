<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Admin\DeviceController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;

final class DeviceLiveStatusTest extends IntegrationTestCase
{
    private Database $db;
    private DeviceController $controller;
    private int $brandId = 99995;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        $_ENV['JWT_SECRET'] = 'test-jwt-secret-for-device-live-status-suite-0123456789';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->db = Database::getInstance();
        $c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $this->db);

        $controller = $c->get(DeviceController::class);
        $this->assertInstanceOf(DeviceController::class, $controller);
        $this->controller = $controller;

        $this->cleanupData();
        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, is_platform, settings)
             VALUES (:mid, 'device-livestatus-uuid', 'Live Status Test', 'live-status-test', 'live@test.com', 'active', 0, '{}')",
            ['mid' => $this->brandId]
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['auth_user_id']     = 1;
        $_SESSION['auth_merchant_id'] = $this->brandId;
        $_SESSION['active_brand_id']  = $this->brandId;
        $_SESSION['brand_view_mode']  = 'single';
        $_SESSION['is_superadmin']    = true;
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->cleanupData();
        }
        unset(
            $_SESSION['auth_user_id'], $_SESSION['auth_merchant_id'],
            $_SESSION['active_brand_id'], $_SESSION['brand_view_mode'], $_SESSION['is_superadmin']
        );
        parent::tearDown();
    }

    private function cleanupData(): void
    {
        $this->db->execute("DELETE FROM op_paired_devices WHERE merchant_id = :mid", ['mid' => $this->brandId]);
        $this->db->execute("DELETE FROM op_device_pairing_tokens WHERE merchant_id = :mid", ['mid' => $this->brandId]);
        $this->db->execute("DELETE FROM op_merchants WHERE id = :mid", ['mid' => $this->brandId]);
    }

    private function seedDevice(string $deviceId, string $name, string $status, string $heartbeatExpr): void
    {
        $this->db->execute(
            "INSERT INTO op_paired_devices (merchant_id, device_id, device_name, platform, jwt_fingerprint, status, last_heartbeat, paired_at)
             VALUES (:mid, :did, :name, 'android', :fp, :status, {$heartbeatExpr}, NOW(6))",
            [
                'mid'    => $this->brandId,
                'did'    => $deviceId,
                'name'   => $name,
                'fp'     => 'fp-' . $deviceId,
                'status' => $status,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        return $data;
    }

    public function testStatusesEndpointReportsOnlineIdleAndRevoked(): void
    {
        $this->seedDevice('livestatus-online', 'Online Phone', 'active', 'NOW(6)');
        $this->seedDevice('livestatus-idle', 'Idle Phone', 'active', 'DATE_SUB(NOW(6), INTERVAL 1 HOUR)');
        $this->seedDevice('livestatus-revoked', 'Revoked Phone', 'revoked', 'NOW(6)');

        $resp = $this->controller->statuses(new Request());
        $this->assertSame(200, $resp->getStatusCode());

        $data = $this->decode($resp->getBody());
        $this->assertTrue($data['success'] ?? false);
        $this->assertIsArray($data['devices'] ?? null);

        $byId = [];
        foreach ($data['devices'] as $d) {
            $byId[$d['device_id']] = $d;
        }

        $this->assertArrayHasKey('livestatus-online', $byId);
        $this->assertSame('active', $byId['livestatus-online']['status']);
        $this->assertTrue($byId['livestatus-online']['online'], 'recent heartbeat -> online');

        $this->assertArrayHasKey('livestatus-idle', $byId);
        $this->assertFalse($byId['livestatus-idle']['online'], 'stale heartbeat -> not online');

        $this->assertArrayHasKey('livestatus-revoked', $byId, 'revoked devices must still be listed');
        $this->assertSame('revoked', $byId['livestatus-revoked']['status']);
        $this->assertFalse($byId['livestatus-revoked']['online'], 'revoked is frozen, never online');
    }

    public function testPairingStatusDetectsDevicePairedAfterBaseline(): void
    {
        $before = (string) $this->db->fetchColumn("SELECT NOW(6)");
        $this->seedDevice('livestatus-paired', 'Galaxy A54', 'active', 'NOW(6)');
        $future = (string) $this->db->fetchColumn("SELECT DATE_ADD(NOW(6), INTERVAL 1 MINUTE)");

        $connectedResp = $this->controller->pairingStatus(new Request(['since' => $before]));
        $this->assertSame(200, $connectedResp->getStatusCode());
        $connected = $this->decode($connectedResp->getBody());
        $this->assertTrue($connected['connected'] ?? false, 'a device paired after the baseline is detected');
        $this->assertSame('Galaxy A54', $connected['device_name'] ?? null);

        $notYetResp = $this->controller->pairingStatus(new Request(['since' => $future]));
        $notYet = $this->decode($notYetResp->getBody());
        $this->assertFalse($notYet['connected'] ?? true, 'no device paired after a future baseline');
    }

    public function testGenerateOtpReturnsGeneratedAtBaseline(): void
    {
        $resp = $this->controller->generateOtp(new Request());
        $data = $this->decode($resp->getBody());

        $this->assertTrue($data['success'] ?? false, 'OTP generation should succeed: ' . ($data['error'] ?? ''));
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertNotEmpty($data['generated_at']);
    }
}
