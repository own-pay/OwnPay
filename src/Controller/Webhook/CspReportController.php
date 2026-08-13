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
     * Maximum accepted body size for a CSP report payload (64 KiB).
     *
     * CSP violation reports are small (typically a few KiB at most). Any payload
     * larger than this is almost certainly abuse (disk-exhaustion / log-injection
     * vector) and is rejected before being parsed.
     */
    private const int MAX_BODY_BYTES = 65536;

    /**
     * Maximum length (in characters) of any single logged CSP report field.
     *
     * Truncation prevents malicious clients from stuffing multi-megabyte strings
     * into individual JSON fields (which would still be under the body cap but
     * could otherwise pollute the log volume and break log viewers).
     */
    private const int MAX_FIELD_LENGTH = 2048;

    /**
     * Content-Types the endpoint will accept for a CSP violation report.
     *
     * `application/csp-report` and `application/reports+json` are the spec-defined
     * types (CSP Level 2 and Reporting API respectively). `application/json` is
     * accepted as a legacy fallback for older browsers that post the report as
     * plain JSON.
     */
    private const array ALLOWED_CONTENT_TYPES = [
        'application/csp-report',
        'application/reports+json',
        'application/json',
    ];

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
        // 1. Validate Content-Type. CSP reports are submitted by browsers with a
        //    very limited set of media types — rejecting anything else stops
        //    generic JSON / form abuse.
        $contentType = strtolower(trim($req->header('Content-Type', '')));
        // Strip any parameters (e.g. "; charset=utf-8") before matching.
        $mimePart = $contentType !== '' ? trim(explode(';', $contentType, 2)[0]) : '';
        if (!in_array($mimePart, self::ALLOWED_CONTENT_TYPES, true)) {
            return Response::json(['error' => 'unsupported_media_type'], 415);
        }

        // 2. Enforce a hard body-size cap before JSON parsing.
        $rawBody = $req->rawBody() ?? '';
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }

        $report = $req->json();
        $reportArr = is_array($report) ? $report : [];
        $cspReportVal = $reportArr['csp-report'] ?? $reportArr;
        $cspReport = is_array($cspReportVal) ? $cspReportVal : [];

        $logger = $this->c->get(\OwnPay\Service\System\Logger::class);
        if ($logger instanceof \OwnPay\Service\System\Logger) {
            $logger->warning('CSP Violation', [
                'document_uri'       => $this->truncateField($cspReport['document-uri'] ?? null),
                'violated_directive' => $this->truncateField($cspReport['violated-directive'] ?? null),
                'blocked_uri'        => $this->truncateField($cspReport['blocked-uri'] ?? null),
                'source_file'        => $this->truncateField($cspReport['source-file'] ?? null),
                'line_number'        => $this->truncateField($cspReport['line-number'] ?? null),
            ]);
        }

        return Response::json(['received' => true], 204);
    }

    /**
     * Coerces a CSP report field to a printable, length-capped string.
     *
     * @param mixed $value The raw value extracted from the JSON payload.
     * @return string The truncated, scalar-safe string representation (max MAX_FIELD_LENGTH chars).
     */
    private function truncateField(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $str = (string) $value;
        if (strlen($str) > self::MAX_FIELD_LENGTH) {
            return substr($str, 0, self::MAX_FIELD_LENGTH) . '…[truncated]';
        }
        return $str;
    }
}
