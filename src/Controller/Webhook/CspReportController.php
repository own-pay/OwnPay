<?php
declare(strict_types=1);

namespace OwnPay\Controller\Webhook;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * CSP Report endpoint.
 * OWASP: Log CSP violations for security monitoring.
 */
final class CspReportController
{
    private Container $c;
    public function __construct(Container $c) { $this->c = $c; }

    public function handle(Request $req): Response
    {
        $report = $req->jsonBody();
        $cspReport = $report['csp-report'] ?? $report;

        $this->c->get(\OwnPay\Core\Logger::class)->warning('CSP Violation', [
            'document_uri'    => $cspReport['document-uri'] ?? '',
            'violated_directive' => $cspReport['violated-directive'] ?? '',
            'blocked_uri'     => $cspReport['blocked-uri'] ?? '',
            'source_file'     => $cspReport['source-file'] ?? '',
            'line_number'     => $cspReport['line-number'] ?? '',
        ]);

        return Response::json(['received' => true], 204);
    }
}
