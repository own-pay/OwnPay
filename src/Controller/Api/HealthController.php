<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Support\DateHelper;

/**
 * Health check — requires Bearer auth.
 * Returns: version, DB status, mobile app connection, gateway count.
 */
final class HealthController
{
    private Container $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function check(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);

        // DB ping
        $dbOk = false;
        try {
            $db->fetchOne("SELECT 1 as ping");
            $dbOk = true;
        } catch (\Throwable $e) {
            try {
                $this->c->get(\OwnPay\Service\System\Logger::class)->warning('Health check DB ping failed: ' . $e->getMessage());
            } catch (\Throwable) {
                // Logger may not be available
            }
        }

        // Mobile device status — check op_paired_devices for this merchant
        $mobileConnected = false;
        $mobileDevices = 0;
        try {
            $devices = $db->fetchAll(
                "SELECT id, device_name, status, last_heartbeat FROM op_paired_devices
                 WHERE merchant_id = :mid AND status = 'active'",
                ['mid' => $mid]
            );
            $mobileDevices = count($devices);
            // A device is "connected" if last heartbeat was within 10 minutes
            $threshold = date('Y-m-d H:i:s', time() - 600);
            foreach ($devices as $device) {
                if (!empty($device['last_heartbeat']) && $device['last_heartbeat'] >= $threshold) {
                    $mobileConnected = true;
                    break;
                }
            }
        } catch (\Throwable) {
            // Table may not exist in edge cases
        }

        // Active gateways count
        $gatewayCount = 0;
        try {
            $row = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM op_gateway_configs WHERE merchant_id = :mid AND status = 'active'",
                ['mid' => $mid]
            );
            $gatewayCount = (int) ($row['cnt'] ?? 0);
        } catch (\Throwable) {
        }

        // Customer count
        $customerCount = 0;
        try {
            $row = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM op_customers WHERE merchant_id = :mid",
                ['mid' => $mid]
            );
            $customerCount = (int) ($row['cnt'] ?? 0);
        } catch (\Throwable) {
        }

        $status = $dbOk ? 'healthy' : 'degraded';
        $code = $dbOk ? 200 : 503;
        $version = $this->c->get('config.app')['version'] ?? '0.1.0';

        return Response::json([
            'status'  => $status,
            'version' => $version,
            'db'      => $dbOk ? 'connected' : 'error',
            'mobile'  => [
                'connected'     => $mobileConnected,
                'active_devices' => $mobileDevices,
            ],
            'gateways'  => $gatewayCount,
            'customers' => $customerCount,
            'time'      => DateHelper::iso(),
        ], $code, [
            'X-API-Version' => $version,
        ]);
    }
}
