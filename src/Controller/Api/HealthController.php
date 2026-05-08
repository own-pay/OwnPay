<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Support\DateHelper;

/**
 * Health check â€” no auth required.
 * Returns version, DB status, uptime.
 */
final class HealthController
{
    private \OwnPay\Container $c;
    public function __construct(\OwnPay\Container $c) { $this->c = $c; }

    public function check(Request $req): Response
    {
        $dbOk = false;
        try {
            $db = $this->c->get(\OwnPay\Core\Database::class);
            $db->fetchOne("SELECT 1 as ping");
            $dbOk = true;
        } catch (\Throwable $e) {
                $this->c->get(\OwnPay\Service\System\Logger::class)->warning('Health check DB ping failed: ' . $e->getMessage());
            }

        $status = $dbOk ? 'healthy' : 'degraded';
        $code = $dbOk ? 200 : 503;

        return Response::json([
            'status'  => $status,
            'version' => $this->c->get('config.app')['version'] ?? '0.1.0',
            'db'      => $dbOk ? 'connected' : 'error',
            'time'    => DateHelper::iso(),
        ], $code, [
            'X-API-Version' => $this->c->get('config.app')['version'] ?? '0.1.0',
        ]);
    }
}
