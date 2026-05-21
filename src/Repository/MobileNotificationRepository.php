<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * MobileNotificationRepository — CRUD for `op_mobile_notifications`.
 *
 * Self-hosted notification queue polled by mobile devices.
 * Replaces third-party push services (FCM/APNs).
 */
final class MobileNotificationRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_mobile_notifications';
    protected array $fillable = [
        'merchant_id', 'device_uuid', 'type', 'title', 'body', 'payload',
        'is_read', 'read_at',
    ];

    /**
     * Queue a notification for a device.
     */
    public function queue(string $deviceUuid, string $type, string $title, string $body = '', array $payload = []): string
    {
        return $this->createScoped([
            'device_uuid' => $deviceUuid,
            'type'        => $type,
            'title'       => $title,
            'body'        => $body,
            'payload'     => !empty($payload) ? json_encode($payload) : null,
        ]);
    }

    /**
     * Poll for unread notifications since a given timestamp.
     */
    public function pollSince(string $deviceUuid, ?string $since = null, int $limit = 50): array
    {
        $where = "device_uuid = :uuid AND merchant_id = :mid";
        $params = ['uuid' => $deviceUuid, 'mid' => $this->requireTenant()];

        if ($since !== null) {
            $where .= " AND created_at > :since";
            $params['since'] = $since;
        } else {
            $where .= " AND is_read = 0";
        }

        return $this->db->fetchAll(
            "SELECT id, type, title, body, payload, is_read, created_at
             FROM {$this->table}
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT :lim",
            array_merge($params, ['lim' => $limit])
        );
    }

    /**
     * Mark notifications as read.
     */
    public function markRead(string $deviceUuid, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->execute(
            "UPDATE {$this->table}
             SET is_read = 1, read_at = NOW()
             WHERE device_uuid = ? AND merchant_id = ? AND id IN ({$placeholders})",
            array_merge([$deviceUuid, $this->requireTenant()], array_map('intval', $ids))
        );

        return $stmt->rowCount();
    }

    /**
     * Purge read notifications older than specified age.
     * NOTE: Global housekeeping — no tenant scope.
     */
    public function purgeOldRead(int $olderThanDays = 7): int
    {
        $cutoff = DateHelper::ago($olderThanDays * 86400);
        $stmt = $this->db->execute(
            "DELETE FROM {$this->table}
             WHERE is_read = 1 AND read_at < :cutoff",
            ['cutoff' => $cutoff]
        );
        return $stmt->rowCount();
    }

    /**
     * Count unread notifications for device.
     */
    public function countUnread(int $merchantId, string $deviceUuid): int
    {
        return $this->db->count(
            $this->table,
            "merchant_id = :mid AND device_uuid = :did AND is_read = 0",
            ['mid' => $merchantId, 'did' => $deviceUuid]
        );
    }

    /**
     * List notifications for device.
     */
    public function listForDevice(int $merchantId, string $deviceUuid, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT id, type, title, body, payload as data, read_at, created_at
             FROM {$this->table}
             WHERE merchant_id = :mid AND device_uuid = :did
             ORDER BY created_at DESC LIMIT :lim",
            ['mid' => $merchantId, 'did' => $deviceUuid, 'lim' => $limit]
        );
    }

    /**
     * Mark notifications as read by IDs.
     *
     * BUG-007 FIX: Added device_uuid scoping to prevent IDOR.
     * Previously any device in a brand could acknowledge another device's notifications.
     */
    public function acknowledgeIds(array $ids, int $merchantId, string $deviceUuid = ''): int
    {
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$merchantId]);

        // BUG-007 FIX: Scope by device_uuid when provided
        $deviceClause = '';
        if ($deviceUuid !== '') {
            $deviceClause = ' AND device_uuid = ?';
            $params[] = $deviceUuid;
        }

        $stmt = $this->db->execute(
            "UPDATE {$this->table} SET is_read = 1, read_at = NOW() WHERE id IN ({$placeholders}) AND merchant_id = ?{$deviceClause}",
            $params
        );
        return $stmt->rowCount();
    }
}
