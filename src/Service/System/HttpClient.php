<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * HTTP Client - A secure wrapper for executing outbound cURL requests.
 *
 * Implements security hardening guidelines such as timeouts (connection/transfer)
 * and SSRF (Server-Side Request Forgery) protection by verifying requested URLs
 * against internal routing restrictions.
 *
 * @method array{status: int, body: string, headers: array<string, string>} get(string $url, array<string, string> $headers = [])
 */
final class HttpClient
{
    /**
     * Mock responses for unit testing.
     *
     * @var array<string, array{status: int, body: string, headers: array<string, string>}|\Closure>|null
     */
    public static ?array $mockResponses = null;

    /**
     * Timeout in seconds for the overall transfer execution.
     *
     * @var int
     */
    private int $timeout;

    /**
     * Timeout in seconds for establishing the initial socket connection.
     *
     * @var int
     */
    private int $connectTimeout;

    /**
     * Maximum redirect hops allowed.
     *
     * @var int
     */
    private int $maxRedirects;

    /**
     * Initialises the HTTP Client wrapper.
     *
     * @param int $timeout Max duration in seconds to wait for request resolution.
     * @param int $connectTimeout Max duration in seconds to wait to establish connection.
     * @param int $maxRedirects Maximum redirect hops allowed.
     */
    public function __construct(int $timeout = 30, int $connectTimeout = 5, int $maxRedirects = 3)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxRedirects = $maxRedirects;
    }

    /**
     * Magic call router to dispatch instance methods.
     *
     * Maps `get()` requests to the underlying request dispatcher.
     *
     * @param string $name Targeted instance method name.
     * @param array<int, mixed> $arguments Parameter arguments passed to the call.
     * @return array{status: int, body: string, headers: array<string, string>} The HTTP response tuple.
     * @throws \BadMethodCallException If the requested method is not supported.
     */
    public function __call(string $name, array $arguments)
    {
        if ($name === 'get') {
            $urlVal = $arguments[0] ?? '';
            $url = is_scalar($urlVal) ? (string) $urlVal : '';
            $rawHeaders = $arguments[1] ?? [];
            $headers = [];
            if (is_array($rawHeaders)) {
                foreach ($rawHeaders as $k => $v) {
                    if (is_scalar($v)) {
                        $headers[(string)$k] = (string)$v;
                    }
                }
            }
            return $this->request('GET', $url, null, $headers);
        }
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * Magic call router to dispatch static helper methods.
     *
     * Maps static `get()` calls to create a short-lived instance and fetch the response body.
     *
     * @param string $name Targeted static method name.
     * @param array<int, mixed> $arguments Parameter arguments passed to the call.
     * @return string|null The response body on success, or null on request failure.
     * @throws \BadMethodCallException If the requested static method is not supported.
     */
    public static function __callStatic(string $name, array $arguments)
    {
        if ($name === 'get') {
            $urlVal = $arguments[0] ?? '';
            $url = is_scalar($urlVal) ? (string) $urlVal : '';
            $timeoutVal = $arguments[1] ?? 30;
            $timeout = is_scalar($timeoutVal) ? (int) $timeoutVal : 30;
            try {
                $client = new self($timeout);
                $res = $client->request('GET', $url, null, []);
                return $res['body'];
            } catch (\Throwable) {
                return null;
            }
        }
        throw new \BadMethodCallException("Static method {$name} does not exist");
    }

    /**
     * Executes a POST request to the specified URL.
     *
     * @param string $url Target address.
     * @param mixed $data Payload to dispatch (array, raw string, etc.).
     * @param array<string, string> $headers Custom request headers.
     * @return array{status: int, body: string, headers: array<string, string>} The response metadata.
     * @throws \RuntimeException If the outbound request fails or is blocked.
     */
    public function post(string $url, mixed $data = null, array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * Executes a PUT request to the specified URL.
     *
     * @param string $url Target address.
     * @param mixed $data Payload to dispatch.
     * @param array<string, string> $headers Custom request headers.
     * @return array{status: int, body: string, headers: array<string, string>} The response metadata.
     * @throws \RuntimeException If the outbound request fails or is blocked.
     */
    public function put(string $url, mixed $data = null, array $headers = []): array
    {
        return $this->request('PUT', $url, $data, $headers);
    }

    /**
     * Executes a PATCH request to the specified URL.
     *
     * @param string $url Target address.
     * @param mixed $data Payload to dispatch.
     * @param array<string, string> $headers Custom request headers.
     * @return array{status: int, body: string, headers: array<string, string>} The response metadata.
     * @throws \RuntimeException If the outbound request fails or is blocked.
     */
    public function patch(string $url, mixed $data = null, array $headers = []): array
    {
        return $this->request('PATCH', $url, $data, $headers);
    }

    /**
     * Executes a DELETE request to the specified URL.
     *
     * @param string $url Target address.
     * @param array<string, string> $headers Custom request headers.
     * @return array{status: int, body: string, headers: array<string, string>} The response metadata.
     * @throws \RuntimeException If the outbound request fails or is blocked.
     */
    public function delete(string $url, array $headers = []): array
    {
        return $this->request('DELETE', $url, null, $headers);
    }

    /**
     * Encodes payload to JSON and dispatches a POST request.
     *
     * Automatically applies the JSON Content-Type header to the transaction.
     *
     * @param string $url Target address.
     * @param array<mixed> $data Associative array containing data fields to be encoded.
     * @param array<string, string> $headers Custom request headers.
     * @return array{status: int, body: string, headers: array<string, string>} The response metadata.
     * @throws \RuntimeException If the outbound request fails or is blocked.
     */
    public function postJson(string $url, array $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        return $this->post($url, json_encode($data), $headers);
    }

    /**
     * Initialises and executes the cURL transaction with targeted parameters.
     *
     * @param string $method HTTP action (e.g. GET, POST, PUT, DELETE, PATCH).
     * @param string $url Target address.
     * @param mixed $data Payload package.
     * @param array<string, string> $headers Mapping of HTTP headers.
     * @return array{status: int, body: string, headers: array<string, string>} The output package containing status, body, and headers.
     * @throws \RuntimeException If SSRF checks reject the URL, or cURL execution encounters issues.
     */
    private function request(string $method, string $url, mixed $data, array $headers): array
    {
        $redirects = 0;
        $currentUrl = $url;

        while (true) {
            // Enforce SSRF protection: reject addresses targeting local or private ranges
            if (!\OwnPay\Security\UrlValidator::isValidWebhookUrl($currentUrl)) {
                throw new \RuntimeException('URL blocked by SSRF protection');
            }

            // Intercept with mock responses if set
            if (self::$mockResponses !== null) {
                if (!isset(self::$mockResponses[$currentUrl])) {
                    throw new \RuntimeException("No mock response configured for URL: " . $currentUrl);
                }

                $mock = self::$mockResponses[$currentUrl];
                if ($mock instanceof \Closure) {
                    $resTuple = $mock($method, $currentUrl, $data, $headers);
                    $status = $resTuple['status'];
                    $body = $resTuple['body'];
                    $responseHeaders = $resTuple['headers'];
                } else {
                    $status = $mock['status'];
                    $body = $mock['body'];
                    $responseHeaders = $mock['headers'];
                }

                if ($status >= 300 && $status < 400) {
                    $redirectUrl = '';
                    foreach ($responseHeaders as $hName => $hVal) {
                        if (strtolower($hName) === 'location') {
                            $redirectUrl = $hVal;
                            break;
                        }
                    }

                    if ($redirectUrl !== '') {
                        if ($redirects >= $this->maxRedirects) {
                            throw new \RuntimeException('URL blocked by SSRF protection');
                        }
                        $redirects++;

                        // Resolve relative or protocol-relative redirect if needed
                        if (str_starts_with($redirectUrl, '//')) {
                            $parsedCurrent = parse_url($currentUrl);
                            $redirectUrl = ($parsedCurrent['scheme'] ?? 'https') . ':' . $redirectUrl;
                        } elseif (!preg_match('#^https?://#i', $redirectUrl)) {
                            $parsedCurrent = parse_url($currentUrl);
                            $base = ($parsedCurrent['scheme'] ?? 'https') . '://' . ($parsedCurrent['host'] ?? 'localhost');
                            if (isset($parsedCurrent['port'])) {
                                $base .= ':' . $parsedCurrent['port'];
                            }
                            if (str_starts_with($redirectUrl, '/')) {
                                $redirectUrl = $base . $redirectUrl;
                            } else {
                                $path = $parsedCurrent['path'] ?? '/';
                                $dir = dirname($path);
                                if ($dir === '\\' || $dir === '/') {
                                    $dir = '';
                                }
                                $redirectUrl = $base . '/' . ltrim($dir . '/' . $redirectUrl, '/');
                            }
                        }

                        // Enforce header safety for cross-origin redirects
                        $origHost = parse_url($currentUrl, PHP_URL_HOST);
                        $newHost = parse_url($redirectUrl, PHP_URL_HOST);
                        if (is_string($origHost) && is_string($newHost) && strtolower($origHost) !== strtolower($newHost)) {
                            $sensitive = ['authorization', 'cookie', 'x-api-key'];
                            foreach ($headers as $key => $value) {
                                if (in_array(strtolower($key), $sensitive, true)) {
                                    unset($headers[$key]);
                                }
                            }
                        }

                        $currentUrl = $redirectUrl;
                        continue;
                    }
                }

                return [
                    'status'  => $status,
                    'body'    => $body,
                    'headers' => $responseHeaders,
                ];
            }

            $parsed = parse_url($currentUrl);
            $host = $parsed['host'] ?? '';
            $port = $parsed['port'] ?? (strtolower($parsed['scheme'] ?? '') === 'https' ? 443 : 80);

            $pinIp = null;
            if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $resolvedIps = [];
                $records = @dns_get_record($host, DNS_A | DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (isset($record['type'])) {
                            if ($record['type'] === 'A' && isset($record['ip'])) {
                                $resolvedIps[] = $record['ip'];
                            } elseif ($record['type'] === 'AAAA' && isset($record['ipv6'])) {
                                $resolvedIps[] = $record['ipv6'];
                            }
                        }
                    }
                }
                if (empty($resolvedIps)) {
                    $ipv4s = gethostbynamel($host);
                    if (is_array($ipv4s)) {
                        $resolvedIps = array_merge($resolvedIps, $ipv4s);
                    }
                }

                // Verify resolved IPs
                foreach ($resolvedIps as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                        throw new \RuntimeException('URL blocked by SSRF protection');
                    }
                }

                if (!empty($resolvedIps)) {
                    $pinIp = $resolvedIps[0];
                }
            }

            $ch = curl_init($currentUrl);
            $responseHeaders = [];

            $curlOpts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_USERAGENT      => 'OwnPay/' . EnvironmentService::version(),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[trim($parts[0])] = trim($parts[1]);
                    }
                    return strlen($header);
                },
            ];

            if ($pinIp !== null) {
                $curlOpts[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinIp}"];
                if (str_contains($pinIp, ':')) {
                    $curlOpts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
                }
            }

            curl_setopt_array($ch, $curlOpts);

            // Build header structures for cURL execution
            $curlHeaders = [];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "{$key}: {$value}";
            }
            if (!empty($curlHeaders)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
            }

            // Apply payload content body for outbound mutations
            if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                if (is_string($data)) {
                    $postFields = $data;
                } elseif (is_array($data) || is_object($data)) {
                    $postFields = http_build_query($data);
                } else {
                    $postFields = is_scalar($data) ? (string) $data : '';
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            }

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_error($ch);
            curl_close($ch);

            if (!is_string($body)) {
                throw new \RuntimeException("HTTP request failed: {$error}");
            }

            // If it is a redirect, follow manually and validate the target
            if ($status >= 300 && $status < 400) {
                // Find Location header if redirectUrl is empty
                if (empty($redirectUrl)) {
                    foreach ($responseHeaders as $hName => $hVal) {
                        if (strtolower($hName) === 'location') {
                            $redirectUrl = $hVal;
                            break;
                        }
                    }
                }

                if (!empty($redirectUrl)) {
                    if ($redirects >= $this->maxRedirects) {
                        throw new \RuntimeException('URL blocked by SSRF protection');
                    }
                    $redirects++;

                    // Resolve relative or protocol-relative redirect if needed
                    if (str_starts_with($redirectUrl, '//')) {
                        $parsedCurrent = parse_url($currentUrl);
                        $redirectUrl = ($parsedCurrent['scheme'] ?? 'https') . ':' . $redirectUrl;
                    } elseif (!preg_match('#^https?://#i', $redirectUrl)) {
                        $parsedCurrent = parse_url($currentUrl);
                        $base = ($parsedCurrent['scheme'] ?? 'https') . '://' . ($parsedCurrent['host'] ?? 'localhost');
                        if (isset($parsedCurrent['port'])) {
                            $base .= ':' . $parsedCurrent['port'];
                        }
                        if (str_starts_with($redirectUrl, '/')) {
                            $redirectUrl = $base . $redirectUrl;
                        } else {
                            $path = $parsedCurrent['path'] ?? '/';
                            $dir = dirname($path);
                            if ($dir === '\\' || $dir === '/') {
                                $dir = '';
                            }
                            $redirectUrl = $base . '/' . ltrim($dir . '/' . $redirectUrl, '/');
                        }
                    }

                    // Enforce header safety for cross-origin redirects
                    $origHost = parse_url($currentUrl, PHP_URL_HOST);
                    $newHost = parse_url($redirectUrl, PHP_URL_HOST);
                    if (is_string($origHost) && is_string($newHost) && strtolower($origHost) !== strtolower($newHost)) {
                        $sensitive = ['authorization', 'cookie', 'x-api-key'];
                        foreach ($headers as $key => $value) {
                            if (in_array(strtolower($key), $sensitive, true)) {
                                unset($headers[$key]);
                            }
                        }
                    }

                    $currentUrl = $redirectUrl;
                    continue;
                }
            }

            return [
                'status'  => $status,
                'body'    => $body,
                'headers' => $responseHeaders,
            ];
        }
    }
}
