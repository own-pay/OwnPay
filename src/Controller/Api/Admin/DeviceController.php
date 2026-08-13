<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Device\DevicePairingService;

/**
 * Controller for managing brand mobile companion devices via REST API endpoints.
 */
final class DeviceController
{
    use AdminScopeAwareTrait;

    /**
     * The dependency injection container.
     *
     */
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;

    /**
     * The device pairing service.
     */
    private DevicePairingService $devices;

    /**
     * DeviceController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param DevicePairingService $devices The device pairing service.
     */
    public function __construct(Container $c, DevicePairingService $devices)
    {
        $this->c = $c;
        $this->devices = $devices;
    }

    /**
     * List all paired devices for the active brand.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response listing devices.
     * @throws \Exception If lookup fails.
     */
    public function index(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $list = $this->devices->listDevices($mid);
        return Response::apiSuccess($list);
    }

    /**
     * Revoke a paired device by UUID.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON success response.
     * @throws \Exception If revocation fails.
     */
    public function revoke(Request $req): Response
    {
        $scopeErr = $this->requireAdminScope($req);
        if ($scopeErr !== null) {
            return $scopeErr;
        }

        // Params were swapped - revoke(string $deviceUuid, int $merchantId)
        $deviceUuid = (string) $req->param('id');
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        
        try {
            $this->devices->revoke($deviceUuid, $mid);
            return Response::apiSuccess(['message' => 'Device revoked successfully']);
        } catch (\Throwable $e) {
            // Don't leak raw exception messages (PDO errors, file paths, etc.) to
            // the API client — log the full exception internally and return a
            // generic, safe message.
            $this->logException('Device revocation failed', $e, ['device_uuid' => $deviceUuid, 'merchant_id' => $mid]);
            return Response::apiError('DEVICE_REVOCATION_FAILED', 'Device revocation failed. Please try again.', 'id', 400);
        }
    }

    /**
     * Logs a caught exception to the system logger if available.
     *
     * @param string $message Human-readable context message.
     * @param \Throwable $e The caught exception.
     * @param array<string, mixed> $context Additional structured context.
     */
    private function logException(string $message, \Throwable $e, array $context = []): void
    {
        if (!$this->c->has(\OwnPay\Service\System\Logger::class)) {
            return;
        }
        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
        if ($logger instanceof \OwnPay\Service\System\Logger) {
            $logger->error($message, $context + ['exception' => $e]);
        }
    }
}
