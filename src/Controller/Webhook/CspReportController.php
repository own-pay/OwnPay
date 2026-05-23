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
        $reportArr = is_array($report) ? $report : [];
        $cspReportVal = $reportArr['csp-report'] ?? $reportArr;
        $cspReport = is_array($cspReportVal) ? $cspReportVal : [];
 
        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
        if ($logger instanceof \OwnPay\Service\System\Logger) {
            $logger->warning('CSP Violation', [
                'document_uri'       => is_scalar($cspReport['document-uri'] ?? null) ? (string) $cspReport['document-uri'] : '',
                'violated_directive' => is_scalar($cspReport['violated-directive'] ?? null) ? (string) $cspReport['violated-directive'] : '',
                'blocked_uri'        => is_scalar($cspReport['blocked-uri'] ?? null) ? (string) $cspReport['blocked-uri'] : '',
                'source_file'        => is_scalar($cspReport['source-file'] ?? null) ? (string) $cspReport['source-file'] : '',
                'line_number'        => is_scalar($cspReport['line-number'] ?? null) ? (string) $cspReport['line-number'] : '',
            ]);
        }
 
        return Response::json(['received' => true], 204);
    }
}
