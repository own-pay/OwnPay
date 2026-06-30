<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Middleware handling sliding-window rate limiting.
 *
 * Implements rate limiting using Redis as the primary engine and falling back to a database
 * table representation (`op_rate_limits`). Prevents brute-force attacks and DDoS threats.
 */
final class RateLimiterMiddleware
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $container;

    /**
     * Constructs a new RateLimiterMiddleware instance.
     *
     * @param Container $container The dependency injection container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Enforces the rate limiting window on incoming HTTP requests.
     *
     * Injects rate limit metadata headers (X-RateLimit-Limit, X-RateLimit-Remaining)
     * and short-circuits with a 429 JSON response when exceeded.
     *
     * @param Request $request The incoming HTTP request.
     * @param callable(Request): Response $next Next handler in the pipeline.
     * @return Response The HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        try {
            // 1. IP Whitelisting Check
            $settingsRepo = null;
            if ($this->container->has(\OwnPay\Repository\SettingsRepository::class)) {
                $repo = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
                if ($repo instanceof \OwnPay\Repository\SettingsRepository) {
                    $settingsRepo = $repo;
                    $whitelistVal = $repo->get('general', 'rate_limit_whitelist_ips', '');
                    $whitelistStr = is_string($whitelistVal) ? $whitelistVal : '';
                    if ($this->isIpWhitelisted($request->ip(), $whitelistStr)) {
                        return $next($request);
                    }
                }
            }

            $config = $this->container->get('config.app');
            if (!is_array($config)) {
                $config = [];
            }
            $path = '/' . trim($request->path(), '/');

            // Dynamically load the admin login slug to ensure rate limits apply perfectly
            $loginSlug = 'login';
            $paths = $config['paths'] ?? null;
            $root = is_array($paths) && isset($paths['root']) && is_string($paths['root']) ? $paths['root'] : dirname(__DIR__, 2);
            $cacheFile = $root . '/storage/cache/login_slug.cache';
            if (file_exists($cacheFile)) {
                $cached = file_get_contents($cacheFile);
                if ($cached !== false && preg_match('/^[a-z0-9\-]+$/', $cached)) {
                    $loginSlug = $cached;
                }
            }

            $isLoginRoute = (
                $path === '/' . $loginSlug ||
                $path === '/2fa' ||
                $path === '/forgot-password' ||
                $path === '/reset-password' ||
                str_contains($path, '/login') ||
                str_contains($path, '/2fa') ||
                str_contains($path, '/forgot-password') ||
                str_contains($path, '/reset-password') ||
                $path === '/api/mobile/v1/devices'
            );

            // 2. Dynamic Rules Matching
            $matchedRule = null;
            if ($settingsRepo !== null) {
                $rulesJsonVal = $settingsRepo->get('general', 'rate_limit_rules', '[]');
                $rulesJson = is_string($rulesJsonVal) ? $rulesJsonVal : '[]';
                $rules = json_decode($rulesJson, true);
                if (is_array($rules)) {
                    $method = $request->method();
                    foreach ($rules as $rule) {
                        if (!is_array($rule)) {
                            continue;
                        }
                        $rulePathVal = $rule['path'] ?? '';
                        $ruleMethodVal = $rule['method'] ?? 'ALL';
                        $ruleLimitVal = $rule['limit'] ?? null;
                        $ruleWindowVal = $rule['window'] ?? null;

                        $rulePath = is_string($rulePathVal) ? $rulePathVal : '';
                        $ruleMethod = is_string($ruleMethodVal) ? $ruleMethodVal : 'ALL';
                        $ruleLimit = is_numeric($ruleLimitVal) ? (int) $ruleLimitVal : null;
                        $ruleWindow = is_numeric($ruleWindowVal) ? (int) $ruleWindowVal : null;

                        if ($rulePath === '' || $ruleLimit === null || $ruleWindow === null) {
                            continue;
                        }

                        $methodMatched = false;
                        if (strcasecmp($ruleMethod, 'ALL') === 0 || strcasecmp($ruleMethod, '*') === 0 || strcasecmp($ruleMethod, $method) === 0) {
                            $methodMatched = true;
                        }

                        if (!$methodMatched) {
                            continue;
                        }

                        $pathMatched = false;
                        if ($rulePath === '*' || $rulePath === '/*') {
                            $pathMatched = true;
                        } else {
                            $regex = preg_quote($rulePath, '#');
                            $regex = str_replace('\*', '.*', $regex);
                            if (preg_match('#^' . $regex . '$#i', $path)) {
                                $pathMatched = true;
                            }
                        }

                        if ($pathMatched) {
                            $matchedRule = [
                                'max' => $ruleLimit,
                                'window' => $ruleWindow
                            ];
                            break;
                        }
                    }
                }
            }

            if ($matchedRule !== null) {
                $limit = $matchedRule['max'];
                $window = $matchedRule['window'];
            } else {
                $rateLimitConfig = $config['rate_limit'] ?? null;
                $limitConfig = null;
                if (is_array($rateLimitConfig)) {
                    if ($isLoginRoute) {
                        $limitConfig = $rateLimitConfig['login'] ?? null;
                    } elseif (str_starts_with($path, '/api/')) {
                        $limitConfig = $rateLimitConfig['api'] ?? null;
                    } else {
                        $limitConfig = $rateLimitConfig['global'] ?? null;
                    }
                }
                if (!is_array($limitConfig)) {
                    $limitConfig = $isLoginRoute ? ['max' => 5, 'window' => 300] : (str_starts_with($path, '/api/') ? ['max' => 60, 'window' => 60] : ['max' => 120, 'window' => 60]);
                }

                $limitVal = $limitConfig['max'] ?? 60;
                $limit = is_scalar($limitVal) ? (int) $limitVal : 60;
                $windowVal = $limitConfig['window'] ?? 60;
                $window = is_scalar($windowVal) ? (int) $windowVal : 60;
            }

            $key = $this->buildKey($request);
            $now = time();

            $count = $this->incrementAndCount($key, $now, $window);

            if ($count > $limit) {
                // 3. Branded HTML Throttling UI Fallback
                if (!$request->expectsJson()) {
                    if ($this->container->has(\Twig\Environment::class)) {
                        try {
                            $twig = $this->container->get(\Twig\Environment::class);
                            if ($twig instanceof \Twig\Environment) {
                                $cspNonce = '';
                                if ($this->container->has('csp_nonce')) {
                                    $cspNonceVal = $this->container->get('csp_nonce');
                                    $cspNonce = is_string($cspNonceVal) ? $cspNonceVal : '';
                                }
                                $html = $twig->render('error/429.twig', [
                                    'retry_after' => $window,
                                    'limit' => $limit,
                                    'csp_nonce' => $cspNonce
                                ]);
                                return Response::html($html, 429)
                                    ->withHeader('Retry-After', (string) $window)
                                    ->withHeader('X-RateLimit-Limit', (string) $limit)
                                    ->withHeader('X-RateLimit-Remaining', '0');
                            }
                        } catch (\Throwable) {
                            // Fallback to ErrorPageRenderer
                        }
                    }
                    $renderer = new \OwnPay\View\ErrorPageRenderer();
                    return Response::html($renderer->rateLimitPage($window, $limit), 429)
                        ->withHeader('Retry-After', (string) $window)
                        ->withHeader('X-RateLimit-Limit', (string) $limit)
                        ->withHeader('X-RateLimit-Remaining', '0');
                }

                return Response::json([
                    'success' => false,
                    'message' => 'Rate limit exceeded. Try again later.',
                ], 429)
                    ->withHeader('Retry-After', (string) $window)
                    ->withHeader('X-RateLimit-Limit', (string) $limit)
                    ->withHeader('X-RateLimit-Remaining', '0');
            }

            $response = $next($request);
            $response->withHeader('X-RateLimit-Limit', (string) $limit);
            $response->withHeader('X-RateLimit-Remaining', (string) max(0, $limit - $count));

            return $response;
        } catch (\PDOException|\RuntimeException $e) {
            $this->logWarning('Rate limiter skipped: ' . $e->getMessage());

            // Determine if the current endpoint requires strict rate limiting (fail-closed)
            $loginSlug = 'login';
            $root = dirname(__DIR__, 2);
            $cacheFile = $root . '/storage/cache/login_slug.cache';
            if (file_exists($cacheFile)) {
                $cached = file_get_contents($cacheFile);
                if ($cached !== false && preg_match('/^[a-z0-9\-]+$/', $cached)) {
                    $loginSlug = $cached;
                }
            }

            $requestPath = '/' . trim($request->path(), '/');
            $isLogin = (
                $requestPath === '/' . $loginSlug ||
                $requestPath === '/2fa' ||
                $requestPath === '/forgot-password' ||
                $requestPath === '/reset-password' ||
                str_contains($requestPath, '/login') ||
                str_contains($requestPath, '/2fa') ||
                str_contains($requestPath, '/forgot-password') ||
                str_contains($requestPath, '/reset-password')
            );

            if ($isLogin || str_starts_with($requestPath, '/api/mobile/v1/devices')) {
                return Response::json([
                    'success' => false,
                    'message' => 'Service temporarily unavailable. Limiter backend error.',
                ], 503);
            }

            return $next($request);
        }
    }

    /**
     * Generates a unique cache/DB rate limiting key based on request metadata.
     *
     * Uses 5 segments for deeper path uniqueness.
     *
     * @param Request $request The request context.
     * @return string The generated rate limiting key.
     */
    private function buildKey(Request $request): string
    {
        $ip = $request->ip();
        $prefix = explode('/', trim($request->path(), '/'));
        
        $pathKey = implode('.', array_slice($prefix, 0, 5));
        return "rl:{$ip}:{$pathKey}";
    }


    /**
     * Atomically increments the rate-limit counter and returns the new count.
     *
     * The returned value is THIS request's authoritative position in the window
     * (Redis INCR / the DB upsert are each atomic), so the caller can decide
     * allow/deny on it without a separate read - closing the read-then-increment
     * race where concurrent requests all see the same sub-limit value.
     *
     * @param string $key The rate limit key.
     * @param int $now The current timestamp.
     * @param int $window The window length.
     * @return int The post-increment hit count for this window.
     */
    private function incrementAndCount(string $key, int $now, int $window): int
    {
        // Try Redis (atomic via native INCR - no TOCTOU)
        if ($this->container->has(\OwnPay\Cache\RedisCache::class)) {
            try {
                $cache = $this->container->get(\OwnPay\Cache\RedisCache::class);
                if ($cache instanceof \OwnPay\Cache\RedisCache) {
                    $redis = $cache->redis();
                    $prefixedKey = 'op:' . $key;

                    // Native Redis INCR - single atomic op returning the new count.
                    $hits = $redis->incr($prefixedKey);
                    $count = is_scalar($hits) ? (int) $hits : 1;

                    // Set TTL only on first increment (when key was just created)
                    if ($count === 1) {
                        $redis->expire($prefixedKey, $window);
                    }

                    return $count;
                }
            } catch (\Throwable $e) {
                $this->logWarning('Redis increment failed: ' . $e->getMessage());
            }
        }

        // Atomic upsert - no TOCTOU race
        $db = $this->container->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            return 1;
        }
        $expires = $now + $window;

        $db->execute(
            "INSERT INTO op_rate_limits (key_name, hits, window_start, expires_at)
             VALUES (:k, 1, :ws, :exp)
             ON DUPLICATE KEY UPDATE
                hits = IF(expires_at > :now2, hits + 1, 1),
                window_start = IF(expires_at > :now3, window_start, :ws2),
                expires_at = IF(expires_at > :now4, expires_at, :exp2)",
            [
                'k' => $key, 'ws' => $now, 'exp' => $expires,
                'now2' => $now, 'now3' => $now, 'ws2' => $now,
                'now4' => $now, 'exp2' => $expires
            ]
        );

        // Read back the post-increment count. The upsert above already committed
        // (autocommit), so this reflects this request's increment.
        $countVal = $db->fetchColumn(
            "SELECT hits FROM op_rate_limits WHERE key_name = :k LIMIT 1",
            ['k' => $key]
        );
        $count = is_scalar($countVal) ? (int) $countVal : 1;

        // Probabilistic cleanup of expired entries (1% chance)
        if (random_int(1, 100) === 1) {
            $db->delete("DELETE FROM op_rate_limits WHERE expires_at <= :now", ['now' => $now]);
        }

        return $count;
    }

    /**
     * Logs warning messages safely without raising exceptions.
     *
     * @param string $message The warning details.
     * @return void
     */
    private function logWarning(string $message): void
    {
        try {
            if ($this->container->has(\OwnPay\Service\System\Logger::class)) {
                $logger = $this->container->get(\OwnPay\Service\System\Logger::class);
                if ($logger instanceof \OwnPay\Service\System\Logger) {
                    $logger->warning($message);
                }
            }
        } catch (\Throwable) {
            // Silently fail if logger is not available (e.g. during installer boot or missing package)
        }
    }

    /**
     * Checks if the client IP is whitelisted.
     *
     * Supports exact IPs and CIDR notations (IPv4/IPv6).
     *
     * @param string $ip The client IP.
     * @param string $whitelistStr Separated whitelist entries.
     * @return bool
     */
    private function isIpWhitelisted(string $ip, string $whitelistStr): bool
    {
        if (trim($whitelistStr) === '') {
            return false;
        }

        // Split by newlines, commas, or spaces
        $entries = preg_split('/[\s,]+/', $whitelistStr);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if ($this->ipMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if an IP matches a single rule (exact or CIDR).
     *
     * @param string $ip Client IP.
     * @param string $rule Whitelist rule (exact IP or CIDR).
     * @return bool
     */
    private function ipMatches(string $ip, string $rule): bool
    {
        if ($ip === $rule) {
            return true;
        }

        if (str_contains($rule, '/')) {
            [$subnet, $bits] = explode('/', $rule, 2);
            $bits = (int) $bits;

            // Check if IP is IPv4 or IPv6
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return false;
                }
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);
                if ($ipLong === false || $subnetLong === false) {
                    return false;
                }
                $mask = ~((1 << (32 - $bits)) - 1);
                return ($ipLong & $mask) === ($subnetLong & $mask);
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return false;
                }
                $ipBin = inet_pton($ip);
                $subnetBin = inet_pton($subnet);
                if ($ipBin === false || $subnetBin === false) {
                    return false;
                }

                // Convert IPv6 bin string to binary bit representation
                $ipBinStr = '';
                $subnetBinStr = '';
                for ($i = 0; $i < 16; $i++) {
                    $ipBinStr .= sprintf('%08b', ord($ipBin[$i]));
                    $subnetBinStr .= sprintf('%08b', ord($subnetBin[$i]));
                }

                return substr($ipBinStr, 0, $bits) === substr($subnetBinStr, 0, $bits);
            }
        }

        return false;
    }
}

