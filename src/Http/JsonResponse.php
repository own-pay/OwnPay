<?php

declare(strict_types=1);

namespace OwnPay\Http;

use OwnPay\Security\PiiMasker;

/**
 * Consistent JSON response builder for the Own Pay API.
 *
 * Envelope format:
 *   Success: { "success": true, "data": {...}, "meta": {...} }
 *   Error:   { "success": false, "error": { "code": "...", "message": "..." } }
 */
final class JsonResponse
{
    /**
     * Send a success response.
     *
     * @param mixed $data    Response payload
     * @param int   $status  HTTP status code
     * @param array $meta    Optional metadata (pagination, etc.)
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): void
    {
        self::cors();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        $body = ['success' => true];

        if ($data !== null) {
            $body['data'] = $data;
        }

        if (!empty($meta)) {
            $body['meta'] = $meta;
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send a paginated success response.
     */
    public static function paginated(array $items, int $page, int $perPage, int $total): void
    {
        self::success($items, 200, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / max($perPage, 1)),
        ]);
    }

    /**
     * Send an error response.
     *
     * @param string $code    Machine-readable error code
     * @param string $message Human-readable error description
     * @param int    $status  HTTP status code
     */
    public static function error(string $code, string $message, int $status = 400): void
    {
        self::cors();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send a success response with PII fields masked.
     *
     * @param array $data   Response payload to mask
     * @param int   $status HTTP status code
     */
    public static function maskedSuccess(array $data, int $status = 200, array $meta = []): void
    {
        $masker = new PiiMasker();
        $masked = is_array($data) ? $masker->mask($data) : $data;
        self::success($masked, $status, $meta);
    }

    /**
     * Send a paginated success response with PII masking.
     */
    public static function maskedPaginated(array $items, int $page, int $perPage, int $total): void
    {
        $masker = new PiiMasker();
        $masked = $masker->maskArray($items);
        self::success($masked, 200, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / max($perPage, 1)),
        ]);
    }

    /**
     * Send a 201 Created response.
     */
    public static function created(mixed $data = null): void
    {
        self::success($data, 201);
    }

    /**
     * Send a 204 No Content response.
     */
    public static function noContent(): void
    {
        self::cors();
        http_response_code(204);
    }

    /**
     * Set security headers (CORS is now handled by CorsMiddleware in api.php).
     */
    public static function cors(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
        }
    }

    /**
     * Read and parse the JSON request body.
     *
     * @return array|null Parsed data or null on failure
     */
    public static function parseRequestBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Read query parameters with defaults.
     */
    public static function queryParam(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get pagination parameters from query string.
     *
     * @return array{page: int, per_page: int}
     */
    public static function paginationParams(int $maxPerPage = 100): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min($maxPerPage, max(1, (int) ($_GET['per_page'] ?? 20)));

        return ['page' => $page, 'per_page' => $perPage];
    }
}
