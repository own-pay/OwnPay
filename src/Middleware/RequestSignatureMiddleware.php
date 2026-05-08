<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Request signature middleware â€” validates HMAC signatures on webhook/IPN callbacks.
 *
 * Per security skill: timing-safe compare, reject replay attacks.
 * Used for incoming gateway callbacks (Stripe, SSLCommerz, etc).
 */
final class RequestSignatureMiddleware
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, callable $next): Response
    {
        $signature = $request->header('X-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->query('signature');

        if ($signature === null || $signature === '') {
            return Response::json([
                'success' => false,
                'message' => 'Missing request signature',
            ], 401);
        }

        $body = $request->rawBody();
        $secret = $this->resolveSecret($request);

        if ($secret === null) {
            return Response::json([
                'success' => false,
                'message' => 'Signature verification not configured',
            ], 500);
        }

        // Support both raw and "sha256=..." prefixed signatures
        $algo = 'sha256';
        $sigValue = $signature;
        if (str_contains($signature, '=')) {
            [$algo, $sigValue] = explode('=', $signature, 2);
        }

        $expected = hash_hmac($algo, $body, $secret);

        // Timing-safe comparison (per OWASP)
        if (!hash_equals($expected, $sigValue)) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid request signature',
            ], 401);
        }

        // Replay protection: check timestamp if provided
        $timestamp = $request->header('X-Timestamp');
        if ($timestamp !== null) {
            $requestTime = (int) $timestamp;
            $tolerance = 300; // 5 minutes
            if (abs(time() - $requestTime) > $tolerance) {
                return Response::json([
                    'success' => false,
                    'message' => 'Request timestamp too old (replay rejected)',
                ], 401);
            }
        }

        return $next($request);
    }

    /**
     * Resolve signing secret from merchant webhook config or env.
     */
    private function resolveSecret(Request $request): ?string
    {
        // Try merchant webhook secret from route params
        $merchantId = $request->getAttribute('merchant_id');
        if ($merchantId !== null) {
            try {
                $repo = $this->container->get(\OwnPay\Repository\MerchantRepository::class);
                $merchant = $repo->find($merchantId);
                if ($merchant !== null && !empty($merchant['webhook_secret'])) {
                    return $merchant['webhook_secret'];
                }
            } catch (\Throwable $e) {
                if ($this->container->has(\OwnPay\Service\System\Logger::class)) {
                    $this->container->get(\OwnPay\Service\System\Logger::class)->warning('Signature secret resolve failed: ' . $e->getMessage());
                }
            }
        }

        // Fallback to env
        $secret = getenv('WEBHOOK_SIGNING_SECRET') ?: null;
        return $secret ?: null;
    }
}
