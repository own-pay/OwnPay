<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * HTTP client — simple cURL wrapper for outbound API calls.
 *
 * Per security skill: timeout enforcement, SSRF prevention, no private IPs.
 */
final class HttpClient
{
    private int $timeout;
    private int $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 5)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @return array{status: int, body: string, headers: array}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * @return array{status: int, body: string, headers: array}
     */
    public function post(string $url, mixed $data = null, array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * @return array{status: int, body: string, headers: array}
     */
    public function put(string $url, mixed $data = null, array $headers = []): array
    {
        return $this->request('PUT', $url, $data, $headers);
    }

    /**
     * @return array{status: int, body: string, headers: array}
     */
    public function delete(string $url, array $headers = []): array
    {
        return $this->request('DELETE', $url, null, $headers);
    }

    /**
     * POST JSON.
     */
    public function postJson(string $url, array $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        return $this->post($url, json_encode($data), $headers);
    }

    private function request(string $method, string $url, mixed $data, array $headers): array
    {
        // SSRF check
        if (!\OwnPay\Security\UrlValidator::isValidWebhookUrl($url)) {
            throw new \RuntimeException('URL blocked by SSRF protection');
        }

        $ch = curl_init($url);
        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERAGENT      => 'OwnPay/' . EnvironmentService::version(),
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($header);
            },
        ]);

        // Build headers
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        // Set body for POST/PUT
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : http_build_query($data));
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }

        return [
            'status'  => $status,
            'body'    => $body,
            'headers' => $responseHeaders,
        ];
    }
}
