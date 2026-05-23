<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware responsible for validating HMAC request signatures on webhook/IPN callbacks.
 *
 * Implements timing-safe comparisons and optional timestamp replay checks on incoming
 * gateway callbacks (e.g. Stripe, SSLCommerz) to guarantee request authenticity.
 */
final class RequestSignatureMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new RequestSignatureMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles signature validation checks on incoming webhook requests.
     *
     * Checks signature headers, extracts hashing algorithms, validates parameters timing-safely,
     * and performs timestamp tolerance validation if the timestamp header is provided.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Use explicit !== '' checks to avoid dropping valid-but-falsy values like '0'
        $signature = $request->header('X-Signature');
        if ($signature === '') {
            $signature = $request->header('X-Hub-Signature-256');
        }
        if ($signature === '') {
            $signature = $request->query('signature') ?? '';
        }

        if ($signature === '') {
            return Response::json([
                'success' => false,
                'message' => 'Missing request signature',
            ], 401);
        }

        $body = $request->rawBody() ?? '';
        $secret = $this->resolveSecret($request);

        if ($secret === null /** @phpstan-ignore identical.alwaysFalse */) {
            return Response::json([
                'success' => false,
                'message' => 'Signature verification not configured',
            ], 500);
        }

        // Support both raw and "sha256=..." prefixed signatures
        // Allowlist algorithms to prevent attacker-controlled weak hashing
        $algo = 'sha256';
        $sigValue = $signature;
        if (str_contains($signature, '=')) {
            [$algo, $sigValue] = explode('=', $signature, 2);
        }

        // Reject non-allowlisted algorithms
        if (!in_array($algo, ['sha256', 'sha512'], true)) {
            return Response::json([
                'success' => false,
                'message' => 'Unsupported signature algorithm',
            ], 401);
        }

        $expected = hash_hmac($algo, $body, $secret);

        // Timing-safe comparison (per OWASP)
        if (!hash_equals($expected, $sigValue)) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid request signature',
            ], 401);
        }

        // Replay protection via X-Timestamp header.
        // Made OPTIONAL — standard gateway webhooks (Stripe, PayPal,
        // SSLCommerz) don't send X-Timestamp. Blocking them breaks all payments.
        // Enforce replay check only when the header IS present.
        $timestamp = $request->header('X-Timestamp');
        if ($timestamp !== '') {
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
     * Resolves the signing secret from merchant webhook configuration or environment settings.
     *
     * @param Request $request The request context.
     * @return string|null The resolved secret key, or null if unresolved.
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
