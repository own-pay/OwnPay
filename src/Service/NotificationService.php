<?php
declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Security\UrlValidator;

/**
 * Notification Service
 *
 * Handles IPN (Instant Payment Notification) webhook dispatch,
 * both single and multi-curl batch operations.
 *
 * SSRF defense (F5 from docs/security_audit/full_codebase_audit.md):
 *   Every outbound URL is validated by UrlValidator::isSafeOutbound() before
 *   dial. Blocked URLs return HTTP code 0 and emit a security log entry.
 *   FOLLOWLOCATION is disabled — redirect-based SSRF bypass not possible.
 */
class NotificationService
{
    public static function sendIPN(string $url, array $payload): int
{
    // SSRF guard — refuse to dial private / loopback / non-http targets
    $reason = null;
    if (!UrlValidator::isSafeOutbound($url, $reason)) {
        Logger::security()->warning('outbound_ipn_blocked', [
            'url'    => $url,
            'reason' => $reason,
        ]);
        return 0;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Connection: close'
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) {
            return strlen($data);
        },
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($result === false) {
        $httpCode = 0;
    }



    return $httpCode;
}

    public static function sendIPNMulti(array $jobs): array
{
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($jobs as $job) {
        // SSRF guard per-job
        $reason = null;
        if (!UrlValidator::isSafeOutbound($job['url'], $reason)) {
            Logger::security()->warning('outbound_ipn_blocked_batch', [
                'url'    => $job['url'],
                'job_id' => $job['id'],
                'reason' => $reason,
            ]);
            $results[$job['id']] = 0;
            continue;
        }

        $json = json_encode($job['payload'], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($job['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Connection: close'
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => fn($ch, $data) => strlen($data),
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[(int) $ch] = [
            'handle' => $ch,
            'id' => $job['id']
        ];
    }

    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    foreach ($handles as $item) {
        $ch = $item['handle'];
        $id = $item['id'];

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 0) {
            $code = 0;
        }

        $results[$id] = $code;

        curl_multi_remove_handle($mh, $ch);

    }

    curl_multi_close($mh);

    return $results;
}
}
