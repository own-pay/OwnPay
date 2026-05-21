<?php
declare(strict_types=1);

namespace OwnPay\Controller\Webhook;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Class CspReportController
 *
 * Handles HTTP requests containing Content Security Policy (CSP) violation reports.
 * Logs CSP violations for security monitoring.
 *
 * @package OwnPay\Controller\Webhook
 */
final class CspReportController
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * CspReportController constructor.
     *
     * @param Container $c The DI container.
     */
    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    /**
     * Handles and logs a CSP violation report payload.
     *
     * POST /webhook/csp-report
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response acknowledging receipt of the report.
     */
    public function handle(Request $req): Response
    {
        $report = $req->json();
        $cspReport = $report['csp-report'] ?? $report;

        $this->c->get(\OwnPay\Service\System\Logger::class)->warning('CSP Violation', [
            'document_uri'       => $cspReport['document-uri'] ?? '',
            'violated_directive' => $cspReport['violated-directive'] ?? '',
            'blocked_uri'        => $cspReport['blocked-uri'] ?? '',
            'source_file'        => $cspReport['source-file'] ?? '',
            'line_number'        => $cspReport['line-number'] ?? '',
        ]);

        return Response::json(['received' => true], 204);
    }
}
