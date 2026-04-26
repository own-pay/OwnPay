<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Core\Database;
use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\JwtAuthMiddleware;

/**
 * MobileDashboardController — Dashboard APIs for the companion app.
 *
 * Endpoints:
 *   GET /v1/dashboard/summary                 — Today's totals + counts
 *   GET /v1/dashboard/transactions             — Paginated transaction list
 *   GET /v1/dashboard/transaction/{id}         — Single transaction detail
 *
 * All queries scoped to the authenticated device's brand_id
 * to ensure multi-tenant data isolation.
 */
final class MobileDashboardController
{
    /**
     * GET /v1/dashboard/summary
     *
     * Response:
     * {
     *   "today": {
     *     "total_received": 15000.00,
     *     "total_sent": 2000.00,
     *     "credit_count": 12,
     *     "debit_count": 3,
     *     "last_transaction": { ... }
     *   },
     *   "this_week": { ... },
     *   "this_month": { ... },
     *   "unparsed_count": 2,
     *   "unread_notifications": 5
     * }
     */
    public function summary(array $params): void
    {
        $device = (new JwtAuthMiddleware())->guard();
        $brandId = (int) $device['brand_id'];
        $pdo = Database::getInstance()->getPdo();

        // Today's summary
        $today = $this->periodSummary($pdo, $brandId, 'DATE(received_at) = CURDATE()');

        // This week
        $week = $this->periodSummary($pdo, $brandId,
            'received_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)');

        // This month
        $month = $this->periodSummary($pdo, $brandId,
            'YEAR(received_at) = YEAR(CURDATE()) AND MONTH(received_at) = MONTH(CURDATE())');

        // Last transaction
        $lastTxn = $this->lastTransaction($pdo, $brandId);

        // Unparsed count
        $unparsedStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM op_sms_parsed
             WHERE brand_id = :brand AND status = 'admin_review'"
        );
        $unparsedStmt->execute([':brand' => $brandId]);
        $unparsedCount = (int) $unparsedStmt->fetchColumn();

        // Unread notifications for this device
        $unreadStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM op_mobile_notifications
             WHERE device_uuid = :uuid AND is_read = 0"
        );
        $unreadStmt->execute([':uuid' => $device['device_uuid']]);
        $unreadNotifications = (int) $unreadStmt->fetchColumn();

        JsonResponse::success([
            'today'                => array_merge($today, [
                'last_transaction' => $lastTxn,
            ]),
            'this_week'            => $week,
            'this_month'           => $month,
            'unparsed_count'       => $unparsedCount,
            'unread_notifications' => $unreadNotifications,
        ]);
    }

    /**
     * GET /v1/dashboard/transactions?page=1&per_page=20&type=credit&sender=bKash
     *
     * Paginated list of parsed SMS transactions.
     */
    public function transactions(array $params): void
    {
        $device = (new JwtAuthMiddleware())->guard();
        $brandId = (int) $device['brand_id'];
        $pdo = Database::getInstance()->getPdo();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        // Optional filters
        $type   = $_GET['type'] ?? null;
        $sender = $_GET['sender'] ?? null;

        $where  = 'brand_id = :brand AND status = :status';
        $params = [':brand' => $brandId, ':status' => 'accepted'];

        if ($type !== null && in_array($type, ['credit', 'debit', 'unknown'], true)) {
            $where .= ' AND parsed_type = :type';
            $params[':type'] = $type;
        }
        if ($sender !== null && $sender !== '') {
            $where .= ' AND sender = :sender';
            $params[':sender'] = $sender;
        }

        // Count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM op_sms_parsed WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $sql = "SELECT id, sender, received_at, parsed_amount, parsed_trx_id, parsed_sender,
                       parsed_balance, parsed_type, parse_method, parse_confidence, created_at
                FROM op_sms_parsed
                WHERE {$where}
                ORDER BY received_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Cast numeric fields
        foreach ($items as &$item) {
            $item['parsed_amount']  = $item['parsed_amount'] !== null ? (float) $item['parsed_amount'] : null;
            $item['parsed_balance'] = $item['parsed_balance'] !== null ? (float) $item['parsed_balance'] : null;
        }
        unset($item);

        JsonResponse::success([
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * GET /v1/dashboard/transaction/{id}
     *
     * Single transaction detail (excludes raw_message for security).
     */
    public function transactionDetail(array $params): void
    {
        $device = (new JwtAuthMiddleware())->guard();
        $brandId = (int) $device['brand_id'];
        $pdo = Database::getInstance()->getPdo();

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'Transaction ID is required.', 400);
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT id, sender, received_at, parsed_amount, parsed_trx_id, parsed_sender,
                    parsed_balance, parsed_type, parse_method, parse_confidence,
                    template_id, status, created_at
             FROM op_sms_parsed
             WHERE id = :id AND brand_id = :brand
             LIMIT 1"
        );
        $stmt->execute([':id' => $id, ':brand' => $brandId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            JsonResponse::error('NOT_FOUND', 'Transaction not found.', 404);
            return;
        }

        $row['parsed_amount']  = $row['parsed_amount'] !== null ? (float) $row['parsed_amount'] : null;
        $row['parsed_balance'] = $row['parsed_balance'] !== null ? (float) $row['parsed_balance'] : null;

        JsonResponse::success(['transaction' => $row]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Calculate period summary (credit total, debit total, counts).
     */
    private function periodSummary(\PDO $pdo, int $brandId, string $dateFilter): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN parsed_type = 'credit' THEN parsed_amount ELSE 0 END), 0) AS total_received,
                COALESCE(SUM(CASE WHEN parsed_type = 'debit'  THEN parsed_amount ELSE 0 END), 0) AS total_sent,
                COALESCE(SUM(CASE WHEN parsed_type = 'credit' THEN 1 ELSE 0 END), 0) AS credit_count,
                COALESCE(SUM(CASE WHEN parsed_type = 'debit'  THEN 1 ELSE 0 END), 0) AS debit_count
             FROM op_sms_parsed
             WHERE brand_id = :brand AND status = 'accepted' AND {$dateFilter}"
        );
        $stmt->execute([':brand' => $brandId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_received' => (float) ($row['total_received'] ?? 0),
            'total_sent'     => (float) ($row['total_sent'] ?? 0),
            'credit_count'   => (int) ($row['credit_count'] ?? 0),
            'debit_count'    => (int) ($row['debit_count'] ?? 0),
        ];
    }

    /**
     * Get the most recent transaction for a brand.
     */
    private function lastTransaction(\PDO $pdo, int $brandId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id, sender, received_at, parsed_amount, parsed_trx_id,
                    parsed_sender, parsed_type, parse_method
             FROM op_sms_parsed
             WHERE brand_id = :brand AND status = 'accepted'
             ORDER BY received_at DESC LIMIT 1"
        );
        $stmt->execute([':brand' => $brandId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) return null;

        $row['parsed_amount'] = $row['parsed_amount'] !== null ? (float) $row['parsed_amount'] : null;
        return $row;
    }
}
