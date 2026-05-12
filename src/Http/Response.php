<?php
declare(strict_types=1);

namespace OwnPay\Http;

/**
 * HTTP response object.
 *
 * Supports HTML, JSON, redirect, and file download responses.
 * All controller methods return a Response instance â€” never echo directly.
 */
final class Response
{
    private string $body;
    private int $statusCode;

    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(string $body = '', int $statusCode = 200)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
    }

    // â”€â”€â”€ Factory Methods â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * HTML response.
     */
    public static function html(string $html, int $status = 200): self
    {
        $response = new self($html, $status);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $response;
    }

    /**
     * JSON response.
     *
     * @param array|object $data
     */
    public static function json(array|object $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = new self($json, $status);
        $response->headers['Content-Type'] = 'application/json; charset=UTF-8';
        $response->headers['X-API-Version'] = '1.0';
        return $response;
    }

    /**
     * Redirect response.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $url;
        return $response;
    }

    /**
     * Empty response (e.g., 204 No Content).
     */
    public static function empty(int $status = 204): self
    {
        return new self('', $status);
    }

    /**
     * Plain text response.
     */
    public static function text(string $text, int $status = 200): self
    {
        $response = new self($text, $status);
        $response->headers['Content-Type'] = 'text/plain; charset=UTF-8';
        return $response;
    }

    /**
     * File download response.
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
     * Maintenance mode response (503).
     */
    public static function maintenance(string $message = 'System under maintenance, please try after sometime or contact support.', int $retryAfter = 600): self
    {
        return self::json([
            'status'  => 503,
            'message' => $message,
            'retry_after' => $retryAfter,
        ], 503)->withHeader('Retry-After', (string)$retryAfter);
    }

    // â”€â”€â”€ Fluent Modifiers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Add or replace a response header.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set the HTTP status code.
     */
    public function withStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Set a cookie header.
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

        // Append â€” allows multiple Set-Cookie headers
        $this->headers['Set-Cookie'] = $cookie;
        return $this;
    }

    /**
     * Add X-API-Version header.
     */
    public function withApiVersion(string $version = '1.0'): self
    {
        $this->headers['X-API-Version'] = $version;
        return $this;
    }

    // â”€â”€â”€ Send â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Send the response to the client.
     */
    public function send(): void
    {
        // Status line
        http_response_code($this->statusCode);

        // Headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }

        // Body
        if ($this->body !== '') {
            echo $this->body;
        }
    }

    // â”€â”€â”€ Accessors â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

}
