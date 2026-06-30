<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * Repository layer for self-hosted mobile notification queue records (`op_mobile_notifications` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Implements lightweight polling logic and acknowledgment controls for paired mobile companion devices.
 *
 * @package OwnPay\Repository
 */
final class MobileNotificationRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_mobile_notifications';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'device_uuid', 'type', 'title', 'body', 'payload',
        'is_read', 'read_at',
    ];

    /**
     * Queues a notification payload for a paired device.
     *
     * @param string $deviceUuid Unique identifier string of the paired device.
     * @param string $type The notification type classification (e.g., 'heartbeat', 'transaction_pending').
     * @param string $title Header/Title text of the notification.
     * @param string $body Body text of the notification.
     * @param array<string, mixed> $payload Metadata array associated with the notification.
     * @return string The primary key ID of the queued notification record.
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
     * Retrieves active/unread notifications for a device since a specific timestamp.
     *
     * @param string $deviceUuid Unique identifier string of the device.
     * @param string|null $since Optional microsecond timestamp cutoff for filtering.
     * @param int $limit Maximum number of notifications to return.
     * @return array<int, array<string, mixed>> List of matching notification records.
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
     * Marks a list of notifications as read for a specific device.
     *
     * @param string $deviceUuid Unique identifier string of the device.
     * @param list<int> $ids List of notification primary key IDs.
     * @return int Number of affected rows.
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
     * Purges read notifications older than the specified age in days.
     *
     * Global housekeeper method (not scoped by merchant/tenant ID).
     *
     * @param int $olderThanDays The cutoff age in days (defaults to 7).
     * @return int Number of purged notification records.
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
     * Counts the total number of unread notifications queued for a specific device.
     *
     * @param int $merchantId The merchant brand ID.
     * @param string $deviceUuid Unique identifier string of the device.
     * @return int Count of unread notifications.
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
     * Lists notifications for a paired device with a hard limit constraint.
     *
     * @param int $merchantId The merchant brand ID.
     * @param string $deviceUuid Unique identifier string of the device.
     * @param int $limit Maximum number of notifications to return.
     * @return array<int, array<string, mixed>> List of matching notification records.
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
     * Acknowledges and marks notifications as read using a whitelist of IDs.
     *
     * Scopes operations by device_uuid to mitigate Insecure Direct Object Reference (IDOR) 
     * vulnerabilities (preventing cross-device notifications acknowledgment).
     *
     * @param list<int> $ids Notification IDs.
     * @param int $merchantId The merchant brand ID.
     * @param string $deviceUuid Unique identifier string of the device.
     * @return int Number of acknowledged notification records.
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

