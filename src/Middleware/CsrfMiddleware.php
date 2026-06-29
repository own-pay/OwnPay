<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware responsible for validating Cross-Site Request Forgery (CSRF) protection.
 *
 * Implements the Synchronizer Token Pattern (STP) using a session-bound token pool
 * to support multi-tab operations. State-changing HTTP methods (POST, PUT, DELETE, PATCH)
 * are validated, while safe methods (GET, HEAD, OPTIONS) and stateless API routes
 * or public webhooks are bypassed.
 */
final class CsrfMiddleware
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $container;

    /**
     * Constructs a new instance of CsrfMiddleware.
     *
     * @param Container|null $container Optional dependency injection container.
     */
    public function __construct(?Container $container = null)
    {
        if ($container !== null) {
            $this->container = $container;
        }
    }

    /**
     * Handles verification of the CSRF token on incoming request payloads.
     *
     * @param Request $request The incoming HTTP request instance.
     * @param callable(Request): Response $next Next middleware/handler in the execution stack.
     * @return Response The HTTP response instance.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Safe methods - no CSRF check needed
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

        if (!is_string($sessionToken) || !is_string($submittedToken)) {
            return $this->forbidden($request, 'Invalid CSRF token type');
        }

        if ($sessionToken === '' || $submittedToken === '') {
            return $this->forbidden($request, 'CSRF token missing');
        }

        // Support token pool for multi-tab usage.
        // Check current token + recent pool of old tokens.
        $tokenPool = $_SESSION['_csrf_token_pool'] ?? [];
        if (!is_array($tokenPool)) {
            $tokenPool = [];
        }
        $valid = hash_equals($sessionToken, $submittedToken);
        if (!$valid) {
            foreach ($tokenPool as $oldToken) {
                if (is_string($oldToken) && hash_equals($oldToken, $submittedToken)) {
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid) {
            return $this->forbidden($request, 'CSRF token mismatch');
        }

        // Rotate token - keep pool of last 5 tokens for multi-tab
        $tokenPool[] = $sessionToken;
        $_SESSION['_csrf_token_pool'] = array_slice($tokenPool, -10);
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        // Stash new token on request so JSON responses can include it for AJAX callers.
        $request->setAttribute('_new_csrf_token', $_SESSION['_csrf_token']);

        return $next($request);
    }

    /**
     * Generates a forbidden response due to a CSRF check failure.
     *
     * @param Request $request The incoming HTTP request instance.
     * @param string $reason Brief details on the cause of the failure.
     * @return Response The forbidden response or redirect response.
     */
    private function forbidden(Request $request, string $reason): Response
    {
        $sessionToken = 'none';
        if (isset($_SESSION['_csrf_token']) && is_string($_SESSION['_csrf_token'])) {
            $sessionToken = $_SESSION['_csrf_token'];
        }

        $submittedToken = $request->post('_csrf_token');
        if (!is_string($submittedToken)) {
            $submittedToken = $request->header('X-CSRF-Token');
            if ($submittedToken === '') {
                $submittedToken = 'none';
            }
        }

        $logMsg = "[CSRF Failure] Path: " . $request->path() . " | Reason: " . $reason;
        $logMsg .= " | Session Token: " . $sessionToken;
        $logMsg .= " | Submitted Token: " . $submittedToken;

        try {
            if (isset($this->container) && $this->container->has(\OwnPay\Service\System\Logger::class)) {
                $logger = $this->container->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->warning($logMsg);
                }
            } else {
                error_log($logMsg);
            }
        } catch (\Throwable) {
            error_log($logMsg);
        }

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

        return Response::html("<h1>403 Forbidden</h1><p>CSRF validation failed: " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . ". Please refresh and try again.</p>", 403);
    }

    /**
     * Validates a given token via the shared SecurityHelpers comparison and HMAC token validation.
     *
     * @param string $token The token value to validate.
     * @param Request|null $request The request context when available.
     * @return array{valid: bool, error: string|null, newToken?: string} Authentication status and errors.
     */
    public function validate(string $token, ?Request $request = null): array
    {
        $secretVal = $_ENV['APP_HMAC_SECRET'] ?? '';
        $secret = is_string($secretVal) ? $secretVal : '';
        if ($secret !== '') {
            // HMAC mode - read from Request if available, else $_POST
            $appId = $request !== null ? ($request->post('op-app-id') ?? '') : ($_POST['op-app-id'] ?? '');
            $timestampRaw = $request !== null ? ($request->post('op-app-timestamp') ?? '') : ($_POST['op-app-timestamp'] ?? '');
            $action = $request !== null ? ($request->post('action') ?? '') : ($_POST['action'] ?? '');

            if (!is_string($appId)) {
                $appId = '';
            }
            if (!is_string($timestampRaw)) {
                $timestampRaw = '';
            }
            if (!is_string($action)) {
                $action = '';
            }

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

        // Canonical key is '_csrf_token' ONLY.
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request !== null
            ? ($request->post('_csrf_token') ?? '')
            : ($_POST['_csrf_token'] ?? '');

        if (
            !is_string($sessionToken) ||
            !is_string($submittedToken) ||
            $sessionToken === '' ||
            $submittedToken === '' ||
            !hash_equals($sessionToken, $submittedToken)
        ) {
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
