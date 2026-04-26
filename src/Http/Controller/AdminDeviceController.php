<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Core\Database;
use OwnPay\Http\JsonResponse;

/**
 * AdminDeviceController — Admin management for paired devices.
 *
 * Endpoints (Bearer-auth admin routes):
 *   GET    /v1/admin/devices           — List all paired devices
 *   GET    /v1/admin/devices/{id}      — Single device detail
 *   POST   /v1/admin/devices/{id}/revoke  — Revoke a device
 *   DELETE /v1/admin/devices/{id}      — Permanently delete a device
 *   POST   /v1/admin/notifications/cleanup — Purge old read notifications
 */
final class AdminDeviceController
{
    /**
     * GET /v1/admin/devices?page=1&per_page=20&status=active
     */
    public function index(array $params): void
    {
        $pdo = Database::getInstance()->getPdo();
        $brandId = (int) ($_SERVER['HTTP_X_BRAND_ID'] ?? $GLOBALS['__merchant']['brand_id'] ?? 1);

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;
        $status  = $_GET['status'] ?? null; // 'active' | 'revoked' | null (all)

        $where  = 'brand_id = :brand';
        $params = [':brand' => $brandId];

        if ($status === 'active') {
            $where .= ' AND revoked_at IS NULL';
        } elseif ($status === 'revoked') {
            $where .= ' AND revoked_at IS NOT NULL';
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM op_paired_devices WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT id, device_uuid, device_name, platform, app_version,
                    created_at, last_seen_at, revoked_at
             FROM op_paired_devices
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $devices = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Enrich with SMS count per device
        foreach ($devices as &$d) {
            $smsStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM op_sms_parsed WHERE device_uuid = :uuid"
            );
            $smsStmt->execute([':uuid' => $d['device_uuid']]);
            $d['sms_count'] = (int) $smsStmt->fetchColumn();
            $d['is_active'] = $d['revoked_at'] === null;
        }
        unset($d);

        JsonResponse::success([
            'devices'  => $devices,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    /**
     * GET /v1/admin/devices/{id}
     */
    public function show(array $params): void
    {
        $pdo = Database::getInstance()->getPdo();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'Device ID is required.', 400);
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT id, device_uuid, device_name, platform, app_version,
                    created_at, last_seen_at, revoked_at
             FROM op_paired_devices WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$device) {
            JsonResponse::error('NOT_FOUND', 'Device not found.', 404);
            return;
        }

        // SMS stats for this device
        $statsStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_sms,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN status = 'admin_review' THEN 1 ELSE 0 END) AS pending_review,
                SUM(CASE WHEN parsed_type = 'credit' THEN parsed_amount ELSE 0 END) AS total_credit,
                SUM(CASE WHEN parsed_type = 'debit' THEN parsed_amount ELSE 0 END) AS total_debit
             FROM op_sms_parsed WHERE device_uuid = :uuid"
        );
        $statsStmt->execute([':uuid' => $device['device_uuid']]);
        $stats = $statsStmt->fetch(\PDO::FETCH_ASSOC);

        $device['is_active'] = $device['revoked_at'] === null;
        $device['stats'] = [
            'total_sms'      => (int) ($stats['total_sms'] ?? 0),
            'accepted'       => (int) ($stats['accepted'] ?? 0),
            'pending_review' => (int) ($stats['pending_review'] ?? 0),
            'total_credit'   => (float) ($stats['total_credit'] ?? 0),
            'total_debit'    => (float) ($stats['total_debit'] ?? 0),
        ];

        JsonResponse::success(['device' => $device]);
    }

    /**
     * POST /v1/admin/devices/{id}/revoke
     *
     * Soft-revoke a device. Sets revoked_at timestamp.
     * The device's JWT/refresh tokens will be rejected on next use.
     */
    public function revoke(array $params): void
    {
        $pdo = Database::getInstance()->getPdo();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'Device ID is required.', 400);
            return;
        }

        $stmt = $pdo->prepare("SELECT id, revoked_at FROM op_paired_devices WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$device) {
            JsonResponse::error('NOT_FOUND', 'Device not found.', 404);
            return;
        }

        if ($device['revoked_at'] !== null) {
            JsonResponse::error('ALREADY_REVOKED', 'Device is already revoked.', 409);
            return;
        }

        $pdo->prepare(
            "UPDATE op_paired_devices SET revoked_at = NOW() WHERE id = :id"
        )->execute([':id' => $id]);

        JsonResponse::success(['revoked' => true, 'device_id' => $id]);
    }

    /**
     * DELETE /v1/admin/devices/{id}
     *
     * Permanently delete a device and all its notifications.
     * SMS records are preserved for audit trail.
     */
    public function destroy(array $params): void
    {
        $pdo = Database::getInstance()->getPdo();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'Device ID is required.', 400);
            return;
        }

        $stmt = $pdo->prepare("SELECT id, device_uuid FROM op_paired_devices WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$device) {
            JsonResponse::error('NOT_FOUND', 'Device not found.', 404);
            return;
        }

        // Delete notifications for this device
        $pdo->prepare(
            "DELETE FROM op_mobile_notifications WHERE device_uuid = :uuid"
        )->execute([':uuid' => $device['device_uuid']]);

        // Delete device record
        $pdo->prepare("DELETE FROM op_paired_devices WHERE id = :id")->execute([':id' => $id]);

        JsonResponse::success(['deleted' => true, 'device_id' => $id]);
    }

    /**
     * POST /v1/admin/notifications/cleanup
     *
     * Purge old read notifications. Body: { "older_than_days": 7 }
     */
    public function notificationCleanup(array $params): void
    {
        $body = JsonResponse::parseRequestBody();
        $days = (int) ($body['older_than_days'] ?? 7);
        $days = max(1, min(90, $days));

        $notifRepo = new \OwnPay\Repository\MobileNotificationRepository();
        $purged = $notifRepo->purgeOldRead($days);

        JsonResponse::success([
            'purged'          => $purged,
            'older_than_days' => $days,
        ]);
    }
}
