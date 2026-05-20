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

    /** @var ?array<string, mixed> Cached decoded JSON body */
    private ?array $jsonCache = null;

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

    // ——— Getters ———————————————————————————————————————————————

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

    // ——— Input Access ——————————————————————————————————————————

    public function query(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }


    public function post(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    /**
     * Decode JSON request body (cached after first call).
     * Returns the full decoded array, or a single key if $key is provided.
     */
    public function json(string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonCache === null) {
            if ($this->rawBody !== null && $this->rawBody !== '') {
                $parsed = json_decode($this->rawBody, true);
                $this->jsonCache = is_array($parsed) ? $parsed : [];
            } else {
                $this->jsonCache = [];
            }
        }
        if ($key === null) {
            return $this->jsonCache;
        }
        return $this->jsonCache[$key] ?? $default;
    }

    /**
     * Unified input — checks POST body, then JSON body, then query string.
     * Use this when a route may receive either form-encoded or JSON data.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->json($key) ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->json() ?: [], $this->post);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post)
            || array_key_exists($key, $this->json() ?: [])
            || array_key_exists($key, $this->query);
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




    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    // ——— Files —————————————————————————————————————————————————

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key])
            && ($this->files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    // ——— Headers ———————————————————————————————————————————————

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

    // ——— Server ————————————————————————————————————————————————

    public function server(string $key, string $default = ''): string
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        // AUD-B3 fix: Trust proxy headers only from known proxy IPs
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($this->isTrustedProxy($remoteAddr)) {
            // X-Forwarded-For: client, proxy1, proxy2 — leftmost is original client
            $xff = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff !== '') {
                $ips = array_map('trim', explode(',', $xff));
                $clientIp = $ips[0];
                // Validate IP format
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }

            // Fallback: X-Real-IP (single IP, set by Nginx)
            $realIp = $this->server['HTTP_X_REAL_IP'] ?? '';
            if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
                return $realIp;
            }
        }

        return $remoteAddr;
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
        if ($https !== '' && $https !== 'off') {
            return 'https';
        }

        // AUD-B3 fix: Check X-Forwarded-Proto from trusted proxy
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->isTrustedProxy($remoteAddr)) {
            $proto = $this->server['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (strtolower($proto) === 'https') {
                return 'https';
            }
        }

        return 'http';
    }

    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    /**
     * Check if the remote address is a trusted reverse proxy.
     * Configure via TRUSTED_PROXIES env var (comma-separated IPs).
     * AUD-B3: Without this, X-Forwarded-For is trivially spoofable.
     */
    private function isTrustedProxy(string $ip): bool
    {
        static $trusted = null;
        if ($trusted === null) {
            $env = getenv('TRUSTED_PROXIES') ?: '';
            $trusted = $env !== '' ? array_map('trim', explode(',', $env)) : [];
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach ($trusted as $entry) {
            // Exact IP match
            if ($entry === $ip) {
                return true;
            }
            // CIDR range match (e.g. 172.16.0.0/12)
            if (str_contains($entry, '/')) {
                [$subnet, $bits] = explode('/', $entry, 2);
                $bits = (int) $bits;
                if ($bits < 0 || $bits > 32) {
                    continue;
                }
                $subnetLong = ip2long($subnet);
                if ($subnetLong === false) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
                if (($ipLong & $mask) === ($subnetLong & $mask)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ——— Cookies ———————————————————————————————————————————————

    public function cookie(string $key, string $default = ''): string
    {
        return $this->cookies[$key] ?? $default;
    }

    // ——— Route Parameters ——————————————————————————————————————

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



    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get all parsed request headers.
     *
     * @return array<string, string>
     */
    public function allHeaders(): array
    {
        return $this->headers;
    }



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

        // Apache mod_fcgid strips or doubles the Authorization header.
        // REDIRECT_HTTP_AUTHORIZATION (from .htaccess E= flag) is always clean.
        // apache_request_headers() returns the original untouched header.
        // ALWAYS prefer these over HTTP_AUTHORIZATION which may be doubled.
        if (!empty($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $server['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $apacheHeaders = (array) apache_request_headers();
            foreach ($apacheHeaders as $k => $v) {
                if (strtolower((string)$k) === 'authorization') {
                    $headers['authorization'] = (string) $v;
                    break;
                }
            }
        }

        return $headers;
    }
}
