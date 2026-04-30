<?php
declare(strict_types=1);

namespace OwnPay\Http;

/**
 * Immutable HTTP request wrapper.
 *
 * Encapsulates $_GET, $_POST, $_SERVER, $_FILES, $_COOKIE.
 * Controllers MUST use this — no direct superglobal access.
 */
final class Request
{
    private readonly string $method;
    private readonly string $uri;
    private readonly string $path;
    private readonly array $query;
    private readonly array $post;
    private readonly array $server;
    private readonly array $files;
    private readonly array $cookies;
    private readonly array $headers;
    private readonly ?string $rawBody;

    /** @var array<string, string> Route parameters (e.g., {id}, {token}) */
    private array $routeParams = [];

    /** @var array<string, mixed> Extra attributes set by middleware */
    private array $attributes = [];

    public function __construct(
        array $query = [],
        array $post = [],
        array $server = [],
        array $files = [],
        array $cookies = [],
        ?string $rawBody = null
    ) {
        $this->query   = $query;
        $this->post    = $post;
        $this->server  = $server;
        $this->files   = $files;
        $this->cookies = $cookies;
        $this->rawBody = $rawBody;

        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $this->uri    = $server['REQUEST_URI'] ?? '/';
        $this->path   = parse_url($this->uri, PHP_URL_PATH) ?: '/';
        $this->headers = $this->parseHeaders($server);
    }

    /**
     * Create Request from PHP superglobals.
     */
    public static function capture(): self
    {
        return new self(
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE,
            file_get_contents('php://input') ?: null
        );
    }

    // ─── Getters ───────────────────────────────────────────────

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'application/json');
    }

    public function expectsJson(): bool
    {
        return str_contains($this->header('Accept', ''), 'application/json')
            || $this->isAjax()
            || str_starts_with($this->path, '/api/');
    }

    // ─── Input Access ──────────────────────────────────────────

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post) || array_key_exists($key, $this->query);
    }

    /**
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->rawBody === null || $this->rawBody === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true);
        return is_array($data) ? $data : [];
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    // ─── Files ─────────────────────────────────────────────────

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key])
            && ($this->files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    // ─── Headers ───────────────────────────────────────────────

    public function header(string $key, string $default = ''): string
    {
        $normalized = strtolower($key);
        return $this->headers[$normalized] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    // ─── Server ────────────────────────────────────────────────

    public function server(string $key, string $default = ''): string
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function host(): string
    {
        return $this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost';
    }

    public function scheme(): string
    {
        $https = $this->server['HTTPS'] ?? '';
        return ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    // ─── Cookies ───────────────────────────────────────────────

    public function cookie(string $key, string $default = ''): string
    {
        return $this->cookies[$key] ?? $default;
    }

    // ─── Route Parameters ──────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, string $default = ''): string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    // ─── Attributes (middleware-injected) ───────────────────────

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    // ─── Private ───────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }
        return $headers;
    }
}
