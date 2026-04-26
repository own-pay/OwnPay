<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Service\Logger;

/**
 * CspReportController — receives Content-Security-Policy violation reports.
 *
 * F17 from docs/security_audit/full_codebase_audit.md
 *
 * Routed at POST /api/csp-report. Browsers POST a JSON body with the violation
 * details; we log them via the security channel without responding with details.
 *
 * Body format (browser standard, both legacy `csp-report` and modern Reporting API):
 *   {
 *     "csp-report": {
 *       "blocked-uri": "...",
 *       "violated-directive": "...",
 *       "original-policy": "...",
 *       "document-uri": "...",
 *       ...
 *     }
 *   }
 */
final class CspReportController
{
    public function index(): void
    {
        // Always reply 204 — browsers don't read the body, and we don't want to
        // give attackers any signal about whether their probe was logged.
        http_response_code(204);
        header('Cache-Control: no-store');

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            return;
        }

        // Cap body to 16 KB to avoid log floods
        if (strlen($rawBody) > 16384) {
            $rawBody = substr($rawBody, 0, 16384);
        }

        $report = json_decode($rawBody, true);
        if (!is_array($report)) {
            // Malformed report — log raw fragment for diagnosis
            Logger::security()->warning('csp_report_malformed', [
                'raw' => substr($rawBody, 0, 1024),
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
            ]);
            return;
        }

        // Normalize legacy and modern report formats
        $payload = $report['csp-report']
            ?? $report[0]['body']
            ?? $report;

        Logger::security()->warning('csp_violation', [
            'blocked_uri'        => $payload['blocked-uri']        ?? null,
            'violated_directive' => $payload['violated-directive'] ?? $payload['effective-directive'] ?? null,
            'document_uri'       => $payload['document-uri']       ?? $payload['documentURL']         ?? null,
            'source_file'        => $payload['source-file']        ?? null,
            'line_number'        => $payload['line-number']        ?? null,
            'ip'                 => $_SERVER['REMOTE_ADDR']        ?? 'unknown',
            'ua'                 => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ]);
    }
}
