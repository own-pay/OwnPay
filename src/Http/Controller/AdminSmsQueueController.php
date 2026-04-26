<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Service\SmsRegexParser;
use OwnPay\Service\SmsHeuristicParser;

/**
 * AdminSmsQueueController — Unparsed SMS review & reprocessing.
 *
 * Endpoints (Bearer-auth admin routes):
 *   GET  /v1/admin/sms-queue                — List unparsed SMS (admin_review status)
 *   POST /v1/admin/sms-queue/{id}/reprocess — Re-run parsing on a specific SMS record
 *   POST /v1/admin/sms-queue/{id}/resolve   — Manually resolve with admin-provided data
 *   GET  /v1/admin/sms-stats                — Parse method/status breakdown
 */
final class AdminSmsQueueController
{
    private SmsDataRepository $dataRepo;
    private SmsTemplateRepository $templateRepo;

    public function __construct()
    {
        $this->dataRepo = new SmsDataRepository();
        $this->templateRepo = new SmsTemplateRepository();
    }

    /**
     * GET /v1/admin/sms-queue?page=1&per_page=20
     *
     * List SMS records with status=admin_review or parse_method=unparsed.
     */
    public function index(array $params): void
    {
        // Get brand_id from the authenticated merchant context
        // (stored in request attributes by BearerAuthMiddleware)
        $brandId = (int) ($_SERVER['HTTP_X_BRAND_ID'] ?? $GLOBALS['__merchant']['brand_id'] ?? 1);

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $result = $this->dataRepo->listByBrand($brandId, $perPage, $offset, 'admin_review');

        // Cast numeric fields
        foreach ($result['items'] as &$item) {
            $item['parsed_amount'] = $item['parsed_amount'] !== null ? (float) $item['parsed_amount'] : null;
        }
        unset($item);

        JsonResponse::success([
            'items'    => $result['items'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $result['total'] > 0 ? (int) ceil($result['total'] / $perPage) : 0,
        ]);
    }

    /**
     * POST /v1/admin/sms-queue/{id}/reprocess
     *
     * Re-runs the 2-tier parsing engine on the raw message of a specific SMS.
     * Useful after adding a new template that might match.
     */
    public function reprocess(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'SMS record ID is required.', 400);
            return;
        }

        $record = $this->dataRepo->findById($id);
        if (!$record) {
            JsonResponse::error('NOT_FOUND', 'SMS record not found.', 404);
            return;
        }

        $rawMessage = $record['raw_message'] ?? null;
        if (empty($rawMessage)) {
            JsonResponse::error('NO_RAW_MESSAGE', 'Raw message not available for re-parsing. The SMS may only have the encrypted payload.', 400);
            return;
        }

        // Try Tier 1: regex
        $templates = $this->templateRepo->findBySender($record['sender']);
        $regexParser = new SmsRegexParser();
        $parsed = $regexParser->parse($rawMessage, $templates);

        // Try Tier 2: heuristic fallback
        if ($parsed === null) {
            $heuristicParser = new SmsHeuristicParser();
            $parsed = $heuristicParser->parse($rawMessage);
        }

        if ($parsed === null) {
            JsonResponse::success([
                'reprocessed' => false,
                'reason'      => 'No parser could extract data from this message.',
                'record_id'   => $id,
            ]);
            return;
        }

        // Update the record with new parse results
        $updateData = [
            'parsed_amount'    => $parsed['parsed_amount'] ?? null,
            'parsed_trx_id'    => $parsed['parsed_trx_id'] ?? null,
            'parsed_sender'    => $parsed['parsed_sender'] ?? null,
            'parsed_balance'   => $parsed['parsed_balance'] ?? null,
            'parsed_type'      => $parsed['parsed_type'] ?? 'unknown',
            'parse_method'     => $parsed['parse_method'] ?? 'unknown',
            'template_id'      => $parsed['template_id'] ?? null,
            'parse_confidence' => $parsed['parse_confidence'] ?? 'low',
            'status'           => 'accepted',
        ];

        $this->dataRepo->updateParsedData($id, $updateData);

        JsonResponse::success([
            'reprocessed'   => true,
            'record_id'     => $id,
            'parse_method'  => $updateData['parse_method'],
            'parsed_amount' => $updateData['parsed_amount'],
            'parsed_type'   => $updateData['parsed_type'],
        ]);
    }

    /**
     * POST /v1/admin/sms-queue/{id}/resolve
     *
     * Manually resolve an unparsed SMS with admin-provided data.
     * Body: { "amount": 500.00, "type": "credit", "trx_id": "ABC123", "sender_number": "01712345678" }
     */
    public function resolve(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'SMS record ID is required.', 400);
            return;
        }

        $record = $this->dataRepo->findById($id);
        if (!$record) {
            JsonResponse::error('NOT_FOUND', 'SMS record not found.', 404);
            return;
        }

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        if (empty($body['amount']) || empty($body['type'])) {
            JsonResponse::error('MISSING_FIELDS', 'At least "amount" and "type" are required.', 400);
            return;
        }

        $updateData = [
            'parsed_amount'    => (float) $body['amount'],
            'parsed_type'      => $body['type'],
            'parsed_trx_id'    => $body['trx_id'] ?? null,
            'parsed_sender'    => $body['sender_number'] ?? null,
            'parse_method'     => 'manual',
            'parse_confidence' => 'high',
            'status'           => 'accepted',
        ];

        $this->dataRepo->updateParsedData($id, $updateData);

        JsonResponse::success([
            'resolved'  => true,
            'record_id' => $id,
            'method'    => 'manual',
        ]);
    }

    /**
     * GET /v1/admin/sms-stats
     *
     * Parse method & status breakdown for admin overview.
     */
    public function stats(array $params): void
    {
        $brandId = (int) ($_SERVER['HTTP_X_BRAND_ID'] ?? $GLOBALS['__merchant']['brand_id'] ?? 1);
        $pdo = \OwnPay\Core\Database::getInstance()->getPdo();

        // By status
        $statusStmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS count FROM op_sms_parsed
             WHERE brand_id = :brand GROUP BY status ORDER BY count DESC"
        );
        $statusStmt->execute([':brand' => $brandId]);
        $byStatus = $statusStmt->fetchAll(\PDO::FETCH_ASSOC);

        // By parse method
        $methodStmt = $pdo->prepare(
            "SELECT parse_method, COUNT(*) AS count FROM op_sms_parsed
             WHERE brand_id = :brand GROUP BY parse_method ORDER BY count DESC"
        );
        $methodStmt->execute([':brand' => $brandId]);
        $byMethod = $methodStmt->fetchAll(\PDO::FETCH_ASSOC);

        // By provider (sender)
        $providerStmt = $pdo->prepare(
            "SELECT sender, COUNT(*) AS count,
                    SUM(CASE WHEN parsed_type = 'credit' THEN parsed_amount ELSE 0 END) AS total_credit,
                    SUM(CASE WHEN parsed_type = 'debit' THEN parsed_amount ELSE 0 END) AS total_debit
             FROM op_sms_parsed
             WHERE brand_id = :brand AND status = 'accepted'
             GROUP BY sender ORDER BY count DESC"
        );
        $providerStmt->execute([':brand' => $brandId]);
        $byProvider = $providerStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Cast numerics
        foreach ($byProvider as &$p) {
            $p['total_credit'] = (float) ($p['total_credit'] ?? 0);
            $p['total_debit']  = (float) ($p['total_debit'] ?? 0);
        }
        unset($p);

        // Template usage
        $tplStmt = $pdo->prepare(
            "SELECT t.id, t.sender_pattern, t.provider_name, COUNT(s.id) AS match_count
             FROM op_sms_templates t
             LEFT JOIN op_sms_parsed s ON s.template_id = t.id AND s.brand_id = :brand
             WHERE t.is_active = 1
             GROUP BY t.id ORDER BY match_count DESC"
        );
        $tplStmt->execute([':brand' => $brandId]);
        $templateUsage = $tplStmt->fetchAll(\PDO::FETCH_ASSOC);

        JsonResponse::success([
            'by_status'      => $byStatus,
            'by_parse_method' => $byMethod,
            'by_provider'    => $byProvider,
            'template_usage' => $templateUsage,
        ]);
    }
}
