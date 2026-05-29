<?php
declare(strict_types=1);

namespace OwnPay\Http;

/**
 * Class Response
 *
 * Represents an HTTP response sent back to the client, supporting HTML, JSON,
 * redirect, text, and file download response formats with fluent modifier structures.
 *
 * @package OwnPay\Http
 */
final class Response
{
    /**
     * @var string The response body content.
     */
    private string $body;

    /**
     * @var int The HTTP status code.
     */
    private int $statusCode;

    /**
     * @var array<string, string> The HTTP headers map.
     */
    private array $headers = [];

    /**
     * @var array<int, string> Stored Set-Cookie header payloads.
     */
    private array $cookies = [];

    /**
     * Response constructor.
     *
     * @param string $body The response body content.
     * @param int $statusCode The HTTP status code.
     */
    public function __construct(string $body = '', int $statusCode = 200)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
    }

    /**
     * Creates an HTML response instance.
     *
     * @param string $html The HTML content payload.
     * @param int $status The HTTP status code. Defaults to 200.
     * @return self The populated Response instance.
     */
    public static function html(string $html, int $status = 200): self
    {
        $response = new self($html, $status);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $response;
    }

    /**
     * Creates a JSON response instance.
     *
     * @param array<string, mixed>|object $data The dataset to encode.
     * @param int $status The HTTP status code. Defaults to 200.
     * @param array<string, string> $headers Additional custom headers.
     * @return self The populated Response instance.
     * @throws \JsonException If encoding the data payload fails.
     */
    public static function json(array|object $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = new self($json, $status);
        $response->headers['Content-Type'] = 'application/json; charset=UTF-8';
        $response->headers['X-API-Version'] = '1.0';
        foreach ($headers as $key => $value) {
            $response->headers[$key] = $value;
        }
        return $response;
    }

    /**
     * Creates a standardized JSON success response.
     *
     * @param mixed $data The success data payload.
     * @param array<string, mixed>|null $meta Optional pagination/meta payload.
     * @param int $status The HTTP status code. Defaults to 200.
     * @param array<string, string> $headers Additional custom headers.
     * @return self The populated Response instance.
     */
    public static function apiSuccess(mixed $data = null, ?array $meta = null, int $status = 200, array $headers = []): self
    {
        $payload = ['success' => true];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, $status, $headers);
    }

    /**
     * Creates a standardized JSON single error response.
     *
     * @param string $code The machine-readable error code.
     * @param string $message The human-readable error message.
     * @param string|null $field The optional input field scope causing the error.
     * @param int $status The HTTP status code. Defaults to 400.
     * @param array<string, string> $headers Additional custom headers.
     * @return self The populated Response instance.
     */
    public static function apiError(string $code, string $message, ?string $field = null, int $status = 400, array $headers = []): self
    {
        $error = [
            'code'    => $code,
            'message' => $message,
        ];
        if ($field !== null) {
            $error['field'] = $field;
        }
        
        $requestId = null;
        if (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            $requestId = $apacheHeaders['X-Request-ID'] ?? $apacheHeaders['x-request-id'] ?? null;
        }
        if (!$requestId && isset($_SERVER['HTTP_X_REQUEST_ID']) && is_scalar($_SERVER['HTTP_X_REQUEST_ID'])) {
            $requestId = (string)$_SERVER['HTTP_X_REQUEST_ID'];
        }
        if (!$requestId) {
            try {
                $requestId = bin2hex(random_bytes(16));
            } catch (\Throwable) {
                $requestId = uniqid('req_', true);
            }
        }

        $payload = [
            'success'    => false,
            'error'      => $message,
            'errors'     => [$error],
            'request_id' => $requestId,
        ];
        return self::json($payload, $status, $headers);
    }

    /**
     * Creates a standardized JSON multiple error response.
     *
     * @param array<mixed> $errors List of structured errors.
     * @param int $status The HTTP status code. Defaults to 422.
     * @param array<string, string> $headers Additional custom headers.
     * @return self The populated Response instance.
     */
    public static function apiErrors(array $errors, int $status = 422, array $headers = []): self
    {
        $requestId = null;
        if (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            $requestId = $apacheHeaders['X-Request-ID'] ?? $apacheHeaders['x-request-id'] ?? null;
        }
        if (!$requestId && isset($_SERVER['HTTP_X_REQUEST_ID']) && is_scalar($_SERVER['HTTP_X_REQUEST_ID'])) {
            $requestId = (string)$_SERVER['HTTP_X_REQUEST_ID'];
        }
        if (!$requestId) {
            try {
                $requestId = bin2hex(random_bytes(16));
            } catch (\Throwable) {
                $requestId = uniqid('req_', true);
            }
        }

        $formatted = [];
        foreach ($errors as $err) {
            if (is_array($err)) {
                $codeVal = $err['code'] ?? 'VALIDATION_FAILED';
                $codeStr = is_scalar($codeVal) ? (string)$codeVal : 'VALIDATION_FAILED';

                $msgVal = $err['message'] ?? 'Invalid input';
                $msgStr = is_scalar($msgVal) ? (string)$msgVal : 'Invalid input';

                $item = [
                    'code'    => $codeStr,
                    'message' => $msgStr,
                ];

                if (array_key_exists('field', $err)) {
                    $fld = $err['field'];
                    if (is_scalar($fld) || $fld === null) {
                        $item['field'] = $fld !== null ? (string)$fld : null;
                    }
                }
                $formatted[] = $item;
            }
        }

        $firstMessage = $formatted[0]['message'] ?? 'Validation failed';

        $payload = [
            'success'    => false,
            'error'      => $firstMessage,
            'errors'     => $formatted,
            'request_id' => $requestId,
        ];
        return self::json($payload, $status, $headers);
    }

    /**
     * Creates a redirect response instance.
     *
     * @param string $url The destination URL.
     * @param int $status The HTTP redirection status code. Defaults to 302.
     * @return self The populated Response instance.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $url;
        return $response;
    }

    /**
     * Creates an empty response instance.
     *
     * @param int $status The HTTP status code. Defaults to 204.
     * @return self The populated Response instance.
     */
    public static function empty(int $status = 204): self
    {
        return new self('', $status);
    }

    /**
     * Creates a plain text response instance.
     *
     * @param string $text The text content payload.
     * @param int $status The HTTP status code. Defaults to 200.
     * @return self The populated Response instance.
     */
    public static function text(string $text, int $status = 200): self
    {
        $response = new self($text, $status);
        $response->headers['Content-Type'] = 'text/plain; charset=UTF-8';
        return $response;
    }

    /**
     * Creates a file download attachment response instance.
     *
     * @param string $filePath The absolute filesystem path to the file.
     * @param string $filename The recommended filename for client download.
     * @param string $contentType The HTTP Content-Type header value. Defaults to 'application/octet-stream'.
     * @return self The populated Response instance.
     */
    public static function download(string $filePath, string $filename, string $contentType = 'application/octet-stream'): self
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return self::json(['error' => 'File not found'], 404);
        }
        $body = (string) file_get_contents($filePath);
        $response = new self($body, 200);
        $response->headers['Content-Type'] = $contentType;
        $response->headers['Content-Disposition'] = 'attachment; filename="' . addslashes($filename) . '"';
        $response->headers['Content-Length'] = (string) strlen($body);
        return $response;
    }

    /**
     * Creates a maintenance mode response instance (status 503).
     *
     * @param string $message The maintenance notice message.
     * @param int $retryAfter The Retry-After header interval in seconds. Defaults to 600.
     * @return self The populated Response instance.
     */
    public static function maintenance(string $message = 'System under maintenance, please try after sometime or contact support.', int $retryAfter = 600): self
    {
        return self::json([
            'status'  => 503,
            'message' => $message,
            'retry_after' => $retryAfter,
        ], 503)->withHeader('Retry-After', (string)$retryAfter);
    }

    /**
     * Fluent modifier to add or replace an HTTP header.
     *
     * @param string $name The header name.
     * @param string $value The header value.
     * @return self The Response instance with the header set.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Fluent modifier to set the HTTP status code.
     *
     * @param int $code The HTTP status code.
     * @return self The Response instance with the status code set.
     */
    public function withStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Fluent modifier to append a Set-Cookie header.
     *
     * Appends to cookies to avoid overriding other cookies during the response stream.
     *
     * @param string $name The cookie name.
     * @param string $value The cookie value.
     * @param int $expires The UNIX expiration timestamp. Defaults to 0 (session).
     * @param string $path The cookie path. Defaults to '/'.
     * @param string $domain The cookie domain. Defaults to empty.
     * @param bool $secure If true, sets the Secure flag. Defaults to true.
     * @param bool $httponly If true, sets the HttpOnly flag. Defaults to true.
     * @param string $samesite SameSite policy constraint ('Lax', 'Strict', 'None'). Defaults to 'Lax'.
     * @return self The Response instance with the cookie configuration added.
     */
    public function withCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httponly = true,
        string $samesite = 'Lax'
    ): self {
        $cookie = urlencode($name) . '=' . urlencode($value);
        if ($expires > 0) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $expires);
        }
        $cookie .= '; Path=' . $path;
        if ($domain !== '') {
            $cookie .= '; Domain=' . $domain;
        }
        if ($secure) {
            $cookie .= '; Secure';
        }
        if ($httponly) {
            $cookie .= '; HttpOnly';
        }
        $cookie .= '; SameSite=' . $samesite;

        $this->cookies[] = $cookie;
        return $this;
    }

    /**
     * Fluent modifier to configure the custom API version header.
     *
     * @param string $version The API version string. Defaults to '1.0'.
     * @return self The Response instance with the API version header set.
     */
    public function withApiVersion(string $version = '1.0'): self
    {
        $this->headers['X-API-Version'] = $version;
        return $this;
    }

    /**
     * Emits headers, status code, cookies, and body output streams to the HTTP client.
     *
     * @return void
     */
    public function send(): void
    {
        // Prevent PHP runtime version leak
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        // Emit status response code.
        http_response_code($this->statusCode);

        // Emit response headers.
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }

        // Emit Set-Cookie headers.
        foreach ($this->cookies as $cookie) {
            header("Set-Cookie: {$cookie}", false);
        }

        // Emit body contents.
        if ($this->body !== '') {
            echo $this->body;
        }
    }

    /**
     * Retrieves the HTTP status code.
     *
     * @return int The status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Retrieves the response body string.
     *
     * @return string The response body content.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Retrieves the response headers map.
     *
     * @return array<string, string> The headers map.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Alias method for plain text response creation.
     *
     * @param string $text The text content payload.
     * @param int $status The HTTP status code. Defaults to 200.
     * @return self The populated Response instance.
     */
    public static function plain(string $text, int $status = 200): self
    {
        return self::text($text, $status);
    }
}
