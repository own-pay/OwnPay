<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Middleware handling authentication of mobile/companion app requests using JSON Web Tokens (JWT).
 *
 * Validates expiration (exp), issuer (iss), audience (aud), and checks for device revocation
 * against the database prior to granting access.
 */
final class JwtAuthMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new JwtAuthMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Authenticates and processes the JWT from the incoming request bearer token.
     *
     * Parses payload, validates claim targets, queries the database for revocation checks,
     * and maps user attributes directly into the Request.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return Response::json([
                'success' => false,
                'message' => 'JWT token required',
            ], 401);
        }

        // Use $_ENV fallback chain (phpdotenv may not populate getenv)
        $secretVal = $_ENV['JWT_SECRET'] ?? $_SERVER['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';
        $secret = is_string($secretVal) ? $secretVal : '';
        if ($secret === '') {
            return Response::json([
                'success' => false,
                'message' => 'JWT not configured',
            ], 500);
        }

        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Firebase\JWT\ExpiredException $e) {
            return Response::json([
                'success' => false,
                'message' => 'JWT token expired',
            ], 401);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid JWT token',
            ], 401);
        }

        // Validate required claims
        if (!isset($payload->sub, $payload->mid, $payload->did) ||
            !is_scalar($payload->mid) || !is_scalar($payload->did)) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid JWT claims',
            ], 401);
        }

        $mid = (int) $payload->mid;
        $did = (string) $payload->did;

        $expectedIss = \OwnPay\Service\Auth\JwtService::ISSUER;
        $expectedAud = 'ownpay-mobile';

        if (!isset($payload->iss) || $payload->iss !== $expectedIss) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid JWT issuer',
            ], 401);
        }
        if (!isset($payload->aud) || $payload->aud !== $expectedAud) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid JWT audience',
            ], 401);
        }

        // Check device not revoked before granting access
        $deviceRepo = $this->container->get(\OwnPay\Repository\PairedDeviceRepository::class);
        if (!$deviceRepo instanceof \OwnPay\Repository\PairedDeviceRepository) {
            throw new \RuntimeException("PairedDeviceRepository not found in container");
        }
        $device = $deviceRepo->forTenant($mid)->findByDeviceId($did);
        if ($device === null || ($device['status'] ?? '') === 'revoked') {
            return Response::json([
                'success' => false,
                'message' => 'Device revoked or not found',
            ], 401);
        }

        $deviceMid = isset($device['merchant_id']) && is_numeric($device['merchant_id']) ? (int) $device['merchant_id'] : 0;
        if ($deviceMid !== $mid) {
            return Response::json([
                'success' => false,
                'message' => 'Device merchant mismatch',
            ], 401);
        }

        // Inject into request
        $request->setAttribute('jwt_payload', (array) $payload);
        $request->setAttribute('merchant_id', $mid);
        $request->setAttribute('device_id', $did);

        return $next($request);
    }
}
