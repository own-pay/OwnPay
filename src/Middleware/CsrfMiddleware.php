<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * CSRF middleware — validates token on state-changing requests.
 *
 * Per OWASP: synchronizer token pattern.
 * Skips GET/HEAD/OPTIONS. API routes use bearer auth instead.
 */
final class CsrfMiddleware
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $container;

    public function __construct(?Container $container = null)
    {
        if ($container !== null) {
            $this->container = $container;
        }
    }

    public function handle(Request $request, callable $next): Response
    {
        // Safe methods — no CSRF check needed
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Skip for API routes (authenticated via bearer/JWT, not cookies)
        if (str_starts_with($request->path(), '/api/')) {
            return $next($request);
        }

        // Skip webhook/IPN endpoints
        if (str_starts_with($request->path(), '/webhook/') || str_starts_with($request->path(), '/ipn/')) {
            return $next($request);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $this->forbidden($request, 'Session not active');
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request->post('_csrf_token')
            ?? $request->header('X-CSRF-Token');

        if ($sessionToken === '' || $submittedToken === '') {
            return $this->forbidden($request, 'CSRF token missing');
        }

        // AUD-09 FIX: Support token pool for multi-tab usage.
        // Check current token + recent pool of old tokens.
        $tokenPool = $_SESSION['_csrf_token_pool'] ?? [];
        $valid = hash_equals($sessionToken, $submittedToken);
        if (!$valid) {
            foreach ($tokenPool as $oldToken) {
                if (hash_equals($oldToken, $submittedToken)) {
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid) {
            return $this->forbidden($request, 'CSRF token mismatch');
        }

        // Rotate token — keep pool of last 5 tokens for multi-tab
        $tokenPool[] = $sessionToken;
        $_SESSION['_csrf_token_pool'] = array_slice($tokenPool, -10);
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        // Stash new token on request so JSON responses can include it for AJAX callers.
        $request->setAttribute('_new_csrf_token', $_SESSION['_csrf_token']);

        return $next($request);
    }

    private function forbidden(Request $request, string $reason): Response
    {
        if ($request->expectsJson()) {
            return Response::json([
                'success' => false,
                'message' => 'CSRF validation failed',
            ], 403);
        }

        // For checkout/payment pages, redirect back to retry with fresh token
        $path = $request->path();
        if (str_starts_with($path, '/pay/') || str_starts_with($path, '/checkout/')) {
            // Extract the base payment link URL from submit path
            $referer = $request->header('Referer');
            if ($referer !== '') {
                $refererPath = parse_url($referer, PHP_URL_PATH) ?: '/';
                return Response::redirect($refererPath);
            }
            // Fallback: strip /submit suffix to go back to the form
            $backPath = preg_replace('#/submit$#', '', $path) ?: '/';
            return Response::redirect($backPath);
        }

        return Response::html('<h1>403 Forbidden</h1><p>CSRF validation failed. Please refresh and try again.</p>', 403);
    }

    /**
     * Validate CSRF token (legacy helper).
     * DS-01 FIX: Aligned session key to '_csrf_token' (matching handle()).
     * DS-04 FIX: Reads from Request object when available, falls back to $_POST.
     */
    public function validate(string $token, ?Request $request = null): array
    {
        $secret = $_ENV['APP_HMAC_SECRET'] ?? '';
        if ($secret !== '') {
            // HMAC mode — read from Request if available, else $_POST
            $appId = $request !== null ? ($request->post('op-app-id') ?? '') : ($_POST['op-app-id'] ?? '');
            $timestampRaw = $request !== null ? ($request->post('op-app-timestamp') ?? '') : ($_POST['op-app-timestamp'] ?? '');
            $action = $request !== null ? ($request->post('action') ?? '') : ($_POST['action'] ?? '');

            if ($appId === '' || $timestampRaw === '' || !is_numeric($timestampRaw)) {
                return [
                    'valid' => false,
                    'error' => 'Request expired. Please try again.',
                ];
            }

            $timestamp = (int)$timestampRaw;
            if (abs(time() - $timestamp) > 300) {
                return [
                    'valid' => false,
                    'error' => 'Request expired. Please try again.',
                ];
            }

            $expected = hash_hmac('sha256', "{$appId}|{$timestamp}|{$action}", $secret);
            if (hash_equals($expected, $token)) {
                return [
                    'valid' => true,
                    'error' => null,
                ];
            }

            return [
                'valid' => false,
                'error' => 'Invalid request token',
            ];
        }

        // BUG-005 FIX: Canonical key is '_csrf_token' ONLY.
        // Removed all legacy 'csrf_token' (without underscore) references
        // to eliminate dual-key inconsistency.
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request !== null
            ? ($request->post('_csrf_token') ?? '')
            : ($_POST['_csrf_token'] ?? '');

        if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            $newToken = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $newToken;
            return [
                'valid' => false,
                'error' => 'Invalid request token',
                'newToken' => $newToken,
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'newToken' => $sessionToken,
        ];
    }
}
