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
        $signatureVal = $request->header('X-Signature');
        if ($signatureVal === '') {
            $signatureVal = $request->header('X-Hub-Signature-256');
        }
        if ($signatureVal === '') {
            $querySig = $request->query('signature');
            $signatureVal = is_string($querySig) ? $querySig : '';
        }
        $signature = $signatureVal;

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
        $midVal = $request->getAttribute('merchant_id');
        if ($midVal !== null && is_scalar($midVal)) {
            $merchantId = (int) $midVal;
            try {
                $repo = $this->container->get(\OwnPay\Repository\MerchantRepository::class);
                if (!$repo instanceof \OwnPay\Repository\MerchantRepository) {
                    throw new \RuntimeException("MerchantRepository not found in container");
                }
                $merchant = $repo->find($merchantId);
                if ($merchant !== null && isset($merchant['webhook_secret']) && is_string($merchant['webhook_secret']) && $merchant['webhook_secret'] !== '') {
                    return $merchant['webhook_secret'];
                }
            } catch (\Throwable $e) {
                if ($this->container->has(\OwnPay\Service\System\Logger::class)) {
                    $logger = $this->container->get(\OwnPay\Service\System\Logger::class);
                    if ($logger instanceof \OwnPay\Service\System\Logger) {
                        $logger->warning('Signature secret resolve failed: ' . $e->getMessage());
                    }
                }
            }
        }

        // Fallback to env
        $secretVal = getenv('WEBHOOK_SIGNING_SECRET') ?: null;
        $secret = is_string($secretVal) ? $secretVal : null;
        return $secret ?: null;
    }
}
