<?php

declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * HttpClient — outbound HTTP wrapper with hardened defaults.
 *
 * Security posture (see docs/security_audit/full_codebase_audit.md F5 + F7):
 *   - TLS verify always on
 *   - Redirect-following is OPT-IN (default off) — prevents redirect-based SSRF
 *     bypass after a UrlValidator check
 *   - When redirects ARE allowed, only http/https schemes can be followed
 *
 * Callers should call UrlValidator::isSafeOutbound($url) BEFORE these methods
 * for any URL sourced from user input (webhooks, IPN, etc.). HttpClient itself
 * does not call UrlValidator — it only enforces transport-level hardening.
 */
final class HttpClient
{
    public static function get(string $url, int $timeout = 10, bool $allowRedirects = false): ?string
    {
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => $allowRedirects,
            CURLOPT_USERAGENT      => 'OwnPay/' . (defined('OP_VERSION') ? OP_VERSION : '1.0'),
        ];
        if ($allowRedirects) {
            $opts[CURLOPT_MAXREDIRS]       = 3;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        $ch = curl_init();
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log("HttpClient::get failed for {$url}: HTTP {$httpCode} — {$error}");
            return null;
        }

        return $response;
    }

    public static function post(
        string $url,
        string $body,
        array $headers = [],
        int $timeout = 15,
        bool $allowRedirects = false,
    ): ?string {
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => $allowRedirects,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'OwnPay/' . (defined('OP_VERSION') ? OP_VERSION : '1.0'),
        ];
        if ($allowRedirects) {
            $opts[CURLOPT_MAXREDIRS]       = 3;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        $ch = curl_init();
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log("HttpClient::post failed for {$url}: HTTP {$httpCode} — {$error}");
            return null;
        }

        return $response;
    }
}
