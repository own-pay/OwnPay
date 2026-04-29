<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Service\Customer\ApiKeyService;

/**
 * BearerAuthMiddleware — extracts and validates Bearer tokens.
 *
 * Replaces the legacy X-API-Key header authentication.
 * Expected header format: Authorization: Bearer <TOKEN>
 */
final class BearerAuthMiddleware
{
    private ApiKeyService $apiKeyService;

    public function __construct(?ApiKeyService $apiKeyService = null)
    {
        $this->apiKeyService = $apiKeyService ?? new ApiKeyService();
    }

    /**
     * Authenticate the current request.
     *
     * @param string|null $requiredScope Optional scope check (e.g. 'create_payment')
     * @return array{
     *   authenticated: bool,
     *   merchant: ?array,
     *   error: ?array{code: string, message: string, httpStatus: int}
     * }
     */
    public function authenticate(?string $requiredScope = null): array
    {
        // 1. Extract token from Authorization header
        $token = $this->extractBearerToken();

        if ($token === null) {
            return [
                'authenticated' => false,
                'merchant' => null,
                'error' => [
                    'code' => 'MISSING_AUTHORIZATION',
                    'message' => 'Authorization header is missing or invalid. Expected: Authorization: Bearer <TOKEN>',
                    'httpStatus' => 401,
                ],
            ];
        }

        // 2. Authenticate the token
        $context = $this->apiKeyService->authenticate($token);

        if ($context === null) {
            return [
                'authenticated' => false,
                'merchant' => null,
                'error' => [
                    'code' => 'INVALID_API_KEY',
                    'message' => 'The API key is invalid, expired, or revoked.',
                    'httpStatus' => 401,
                ],
            ];
        }

        // 3. Check scope if required
        if ($requiredScope !== null && !in_array($requiredScope, $context['scopes'], true)) {
            return [
                'authenticated' => false,
                'merchant' => $context,
                'error' => [
                    'code' => 'INSUFFICIENT_SCOPE',
                    'message' => "The API key does not have the required permission: {$requiredScope}",
                    'httpStatus' => 403,
                ],
            ];
        }

        return [
            'authenticated' => true,
            'merchant' => $context,
            'error' => null,
        ];
    }

    /**
     * Convenience: authenticate and halt with JSON error if unauthorized.
     * Returns the merchant context array on success.
     */
    public function guard(?string $requiredScope = null): array
    {
        $result = $this->authenticate($requiredScope);

        if (!$result['authenticated']) {
            http_response_code($result['error']['httpStatus']);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'code' => $result['error']['code'],
                    'message' => $result['error']['message'],
                ],
            ]);
            exit;
        }

        return $result['merchant'];
    }

    /**
     * Extract Bearer token from the Authorization header.
     */
    private function extractBearerToken(): ?string
    {
        // Try getallheaders() first (Apache)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    return $this->parseBearerValue($value);
                }
            }
        }

        // Fallback: $_SERVER (Nginx / CGI)
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($header !== null) {
            return $this->parseBearerValue($header);
        }

        return null;
    }

    /**
     * Parse "Bearer <token>" value.
     */
    private function parseBearerValue(string $headerValue): ?string
    {
        if (str_starts_with($headerValue, 'Bearer ')) {
            $token = trim(substr($headerValue, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }
}
