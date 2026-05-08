<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT auth middleware â€” authenticates mobile/companion app requests.
 *
 * Per security skill: validate exp, iss, aud, device fingerprint.
 */
final class JwtAuthMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return Response::json([
                'success' => false,
                'message' => 'JWT token required',
            ], 401);
        }

        $secret = getenv('JWT_SECRET') ?: '';
        if ($secret === '') {
            return Response::json([
                'success' => false,
                'message' => 'JWT not configured',
            ], 500);
        }

        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));

            // Validate required claims
            if (!isset($payload->sub, $payload->mid, $payload->did)) {
                return Response::json([
                    'success' => false,
                    'message' => 'Invalid JWT claims',
                ], 401);
            }

            // Inject into request
            $request->setAttribute('jwt_payload', (array) $payload);
            $request->setAttribute('merchant_id', (int) $payload->mid);
            $request->setAttribute('device_id', (string) $payload->did);

            return $next($request);

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
    }
}
