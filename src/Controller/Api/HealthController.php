<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Support\DateHelper;

/**
 * Controller for performing system health check operations.
 */
final class HealthController
{
    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * HealthController constructor.
     *
     * @param Container $c The dependency injection container.
     */
    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    /**
     * Execute a system health check and return detailed statuses (DB connectivity, paired devices, gateways, customer metrics).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response with health status parameters.
     */
    public function check(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $db = $this->c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            throw new \RuntimeException('Database not found');
        }

        // DB ping
        $dbOk = false;
        try {
            $db->fetchOne("SELECT 1 as ping");
            $dbOk = true;
        } catch (\Throwable $e) {
            try {
                $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->warning('Health check DB ping failed: ' . $e->getMessage());
                }
            } catch (\Throwable) {
                // Logger may not be available
            }
        }

        // Mobile device status - check op_paired_devices for this merchant
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
            if (is_array($row)) {
                $cntVal = $row['cnt'] ?? 0;
                $gatewayCount = is_int($cntVal) || is_string($cntVal) || is_float($cntVal) ? (int) $cntVal : 0;
            }
        } catch (\Throwable) {
            // Best-effort metric: leave the count at 0 if the query/table is unavailable.
        }

        // Customer count
        $customerCount = 0;
        try {
            $row = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM op_customers WHERE merchant_id = :mid",
                ['mid' => $mid]
            );
            if (is_array($row)) {
                $cntVal = $row['cnt'] ?? 0;
                $customerCount = is_int($cntVal) || is_string($cntVal) || is_float($cntVal) ? (int) $cntVal : 0;
            }
        } catch (\Throwable) {
            // Best-effort metric: leave the count at 0 if the query/table is unavailable.
        }

        $status = $dbOk ? 'healthy' : 'degraded';
        $code = $dbOk ? 200 : 503;
        
        $appConfig = $this->c->get('config.app');
        $version = (is_array($appConfig) && isset($appConfig['version']) && is_string($appConfig['version'])) ? $appConfig['version'] : '0.1.0';

        $headers = [
            'X-API-Version' => $version,
        ];

        $data = [
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
        ];

        return Response::apiSuccess($data, null, $code, $headers);
    }
}
