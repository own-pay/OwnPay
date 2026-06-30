<?php
declare(strict_types=1);

namespace OwnPay\Http;

/**
 * Class Request
 *
 * An immutable HTTP request wrapper encapsulating superglobals ($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE),
 * raw request payloads, headers, route parameters, and custom middleware attributes.
 *
 * @package OwnPay\Http
 */
final class Request
{
    /**
     * @var string The HTTP method.
     */
    private readonly string $method;

    /**
     * @var string The full request URI.
     */
    private readonly string $uri;

    /**
     * @var string The parsed request path.
     */
    private readonly string $path;

    /**
     * @var array<string, mixed> The query parameters.
     */
    private readonly array $query;

    /**
     * @var array<string, mixed> The POST parameters.
     */
    private readonly array $post;

    /**
     * @var array<string, mixed> The server configuration parameters.
     */
    private readonly array $server;

    /**
     * @var array<string, array<string, mixed>> The uploaded files data.
     */
    private readonly array $files;

    /**
     * @var array<string, string> The cookie variables.
     */
    private readonly array $cookies;

    /**
     * @var array<string, string> The normalized HTTP headers.
     */
    private readonly array $headers;

    /**
     * @var string|null The raw request body stream payload.
     */
    private readonly ?string $rawBody;

    /**
     * @var array<string, string> Route parameters (e.g., {id}, {token}).
     */
    private array $routeParams = [];

    /**
     * @var array<string, mixed> Extra attributes set by middleware.
     */
    private array $attributes = [];

    /**
     * @var array<string, mixed>|null Cached decoded JSON body.
     */
    private ?array $jsonCache = null;

    /**
     * Request constructor.
     *
     * @param array<string, mixed> $query The query parameters.
     * @param array<string, mixed> $post The POST parameters.
     * @param array<string, mixed> $server The server variables.
     * @param array<string, array<string, mixed>> $files The uploaded files variables.
     * @param array<string, string> $cookies The request cookies.
     * @param string|null $rawBody The raw input body stream.
     */
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

        $reqMethod = $server['REQUEST_METHOD'] ?? 'GET';
        $this->method = strtoupper(is_string($reqMethod) ? $reqMethod : 'GET');
        $reqUri = $server['REQUEST_URI'] ?? '/';
        $this->uri    = is_string($reqUri) ? $reqUri : '/';
        $this->path   = parse_url($this->uri, PHP_URL_PATH) ?: '/';
        $this->headers = $this->parseHeaders($server);
    }

    /**
     * Captures and constructs a new Request instance using current PHP superglobals.
     *
     * @return self The captured Request instance.
     */
    public static function capture(): self
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            $query[(string)$k] = $v;
        }
        $post = [];
        foreach ($_POST as $k => $v) {
            $post[(string)$k] = $v;
        }
        $server = [];
        foreach ($_SERVER as $k => $v) {
            $server[(string)$k] = $v;
        }
        $files = [];
        foreach ($_FILES as $k => $v) {
            if (is_array($v)) {
                $files[(string)$k] = $v;
            }
        }
        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_scalar($v)) {
                $cookies[(string)$k] = (string)$v;
            }
        }

        return new self(
            $query,
            $post,
            $server,
            $files,
            $cookies,
            file_get_contents('php://input') ?: null
        );
    }

    /**
     * Retrieves the HTTP request method in uppercase.
     *
     * @return string The HTTP method.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Retrieves the request URI.
     *
     * @return string The request URI.
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Retrieves the parsed URL path.
     *
     * @return string The URL path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Checks if the request method matches the specified verb.
     *
     * @param string $method The HTTP verb to verify.
     * @return bool True if the method matches, otherwise false.
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Checks if the request was made via AJAX (XMLHttpRequest).
     *
     * @return bool True if the request is an AJAX request, otherwise false.
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Checks if the request Content-Type header specifies application/json.
     *
     * @return bool True if the payload is JSON, otherwise false.
     */
    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'application/json');
    }

    /**
     * Evaluates if the client expects a JSON response.
     *
     * Matches request path prefixes, content headers, or AJAX flags.
     *
     * @return bool True if a JSON response is expected, otherwise false.
     */
    public function expectsJson(): bool
    {
        return str_contains($this->header('Accept', ''), 'application/json')
            || $this->isAjax()
            || str_starts_with($this->path, '/api/');
    }

    /**
     * Retrieves a query string parameter, or the full query array if no key is provided.
     *
     * @param string|null $key The parameter name.
     * @param mixed $default The fallback value if parameter is not found.
     * @return mixed The parameter value, the query array, or the default fallback.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /**
     * Retrieves a POST parameter, or the full POST array if no key is provided.
     *
     * @param string|null $key The parameter name.
     * @param mixed $default The fallback value if parameter is not found.
     * @return mixed The parameter value, the POST array, or the default fallback.
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    /**
     * Decodes and retrieves JSON request payload data.
     *
     * @param string|null $key The parameter name to fetch.
     * @param mixed $default The fallback value if key is not found.
     * @return mixed The parameter value, the decoded JSON array, or the default fallback.
     */
    public function json(?string $key = null, mixed $default = null): mixed
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
     * Retrieves request input parameters, searching POST, JSON, and query string in priority order.
     *
     * @param string $key The parameter name.
     * @param mixed $default The fallback value if parameter is not found.
     * @return mixed The input parameter value, or the default fallback.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->json($key) ?? $this->query[$key] ?? $default;
    }

    /**
     * Aggregates and returns all request inputs from query string, JSON payload, and POST body.
     *
     * @return array<string, mixed> The merged input parameters.
     */
    public function all(): array
    {
        $json = $this->json();
        $jsonArray = is_array($json) ? $json : [];
        $merged = array_merge($this->query, $jsonArray, $this->post);
        $result = [];
        foreach ($merged as $k => $v) {
            $result[(string)$k] = $v;
        }
        return $result;
    }

    /**
     * Checks if a specified parameter key is present in the request input array.
     *
     * @param string $key The parameter key.
     * @return bool True if the key exists, otherwise false.
     */
    public function has(string $key): bool
    {
        $json = $this->json();
        $jsonArray = is_array($json) ? $json : [];
        return array_key_exists($key, $this->post)
            || array_key_exists($key, $jsonArray)
            || array_key_exists($key, $this->query);
    }

    /**
     * Retrieves only the specified key-value pairs from all request inputs.
     *
     * @param array<int, string> $keys The list of keys to retrieve.
     * @return array<string, mixed> The filtered key-value inputs.
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * Retrieves the raw request body stream.
     *
     * @return string|null The raw body content, or null if empty.
     */
    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * Retrieves uploaded file variables for the specified key.
     *
     * @param string $key The file parameter name.
     * @return array<string, mixed>|null The uploaded file details, or null if not found.
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Checks if a file was successfully uploaded for the given key.
     *
     * @param string $key The file parameter name.
     * @return bool True if a valid file was uploaded, otherwise false.
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key])
            && ($this->files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    /**
     * Retrieves the specified HTTP header value.
     *
     * @param string $key The header name.
     * @param string $default The default value if header is not present.
     * @return string The header value, or default fallback.
     */
    public function header(string $key, string $default = ''): string
    {
        $normalized = strtolower($key);
        return $this->headers[$normalized] ?? $default;
    }

    /**
     * Extracts the Bearer token from the Authorization header.
     *
     * @return string|null The Bearer token value, or null if not present/invalid format.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    /**
     * Retrieves the specified server parameter.
     *
     * @param string $key The server variable name.
     * @param string $default The default value if variable is not present.
     * @return string The server variable value, or default fallback.
     */
    public function server(string $key, string $default = ''): string
    {
        $value = $this->server[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Resolves the client's IP address.
     *
     * Features proxy verification to trust forward headers (e.g. X-Forwarded-For)
     * exclusively from configured trusted reverse proxy IPs.
     *
     * @return string The resolved IP address.
     */
    public function ip(): string
    {
        $remoteAddr = $this->server('REMOTE_ADDR', '0.0.0.0');

        if ($this->isTrustedProxy($remoteAddr)) {
            // X-Forwarded-For: client, proxy1, proxy2 - leftmost is original client.
            $xff = $this->server('HTTP_X_FORWARDED_FOR');
            if ($xff !== '') {
                $ips = array_map('trim', explode(',', $xff));
                $clientIp = $ips[0];
                // Validate IP format.
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }

            // Fallback: X-Real-IP (single IP, set by Nginx).
            $realIp = $this->server('HTTP_X_REAL_IP');
            if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
                return $realIp;
            }
        }

        return $remoteAddr;
    }

    /**
     * Retrieves the client User-Agent string.
     *
     * @return string The User-Agent string.
     */
    public function userAgent(): string
    {
        return $this->server('HTTP_USER_AGENT');
    }

    /**
     * Resolves the HTTP request hostname.
     *
     * @return string The resolved host name.
     */
    public function host(): string
    {
        $host = $this->server('HTTP_HOST');
        if ($host !== '') {
            return $host;
        }
        $serverName = $this->server('SERVER_NAME');
        if ($serverName !== '') {
            return $serverName;
        }
        return 'localhost';
    }

    /**
     * Resolves the request protocol scheme (http or https).
     *
     * @return string The protocol scheme.
     */
    public function scheme(): string
    {
        $https = $this->server('HTTPS');
        if ($https !== '' && $https !== 'off') {
            return 'https';
        }

        // Check X-Forwarded-Proto from trusted proxy.
        $remoteAddr = $this->server('REMOTE_ADDR', '0.0.0.0');
        if ($this->isTrustedProxy($remoteAddr)) {
            $proto = $this->server('HTTP_X_FORWARDED_PROTO');
            if (strtolower($proto) === 'https') {
                return 'https';
            }
        }

        return 'http';
    }

    /**
     * Checks if the request was served over a secure connection (HTTPS).
     *
     * @return bool True if secure, otherwise false.
     */
    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    /**
     * Checks if the remote client address matches the trusted proxy configurations.
     *
     * Supports both direct IPv4/IPv6 comparisons and subnet matches.
     *
     * @param string $ip The target client IP address to check.
     * @return bool True if the IP is a trusted proxy, otherwise false.
     */
    private function isTrustedProxy(string $ip): bool
    {
        static $trusted = null;
        if ($trusted === null) {
            $env = getenv('TRUSTED_PROXIES') ?: '';
            $trusted = $env !== '' ? array_map('trim', explode(',', $env)) : [];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($trusted as $entry) {
            // Exact IP match.
            if ($entry === $ip) {
                return true;
            }
            // CIDR range match (e.g. 172.16.0.0/12).
            if (str_contains($entry, '/')) {
                [$subnet, $bits] = explode('/', $entry, 2);
                $bits = (int) $bits;
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);
                if ($ipLong !== false && $subnetLong !== false) {
                    if ($bits < 0 || $bits > 32) {
                        continue;
                    }
                    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
                    if (($ipLong & $mask) === ($subnetLong & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Retrieves a cookie value.
     *
     * @param string $key The cookie name.
     * @param string $default The default value if cookie is not present.
     * @return string The cookie value, or default fallback.
     */
    public function cookie(string $key, string $default = ''): string
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Assigns the parsed route parameter array.
     *
     * @param array<string, string> $params The route parameters.
     * @return void
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Retrieves the value of a specific route parameter.
     *
     * @param string $key The parameter name.
     * @param string $default The default value if not set.
     * @return string The parameter value, or default fallback.
     */
    public function param(string $key, string $default = ''): string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Retrieves all assigned route parameters.
     *
     * @return array<string, string> The route parameters.
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Assigns an extra attribute to the request context (used by middlewares).
     *
     * @param string $key The attribute key.
     * @param mixed $value The attribute value.
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Retrieves an attribute value from the request context.
     *
     * @param string $key The attribute key.
     * @param mixed $default The default value if not set.
     * @return mixed The attribute value, or default fallback.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Retrieves all request headers.
     *
     * @return array<string, string> The headers map.
     */
    public function allHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Extracts and normalizes request headers from server parameters.
     *
     * Handles Apache and PHP-FPM Authorization header extraction overrides.
     *
     * @param array<string, mixed> $server The server variables.
     * @return array<string, string> The normalized headers map.
     */
    private function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_scalar($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE']) && is_scalar($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH']) && is_scalar($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }

        $redirAuth = $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($redirAuth !== null && is_scalar($redirAuth)) {
            $headers['authorization'] = (string) $redirAuth;
        } elseif (function_exists('apache_request_headers')) {
            $apacheHeaders = (array) apache_request_headers();
            foreach ($apacheHeaders as $k => $v) {
                if (strtolower((string)$k) === 'authorization' && is_scalar($v)) {
                    $headers['authorization'] = (string) $v;
                    break;
                }
            }
        }

        return $headers;
    }
}
