<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

/**
 * MobileNotificationRepository — CRUD for `op_mobile_notifications`.
 *
 * Self-hosted notification queue polled by mobile devices.
 * Replaces third-party push services (FCM/APNs).
 */
final class MobileNotificationRepository
{
    private const TABLE = 'op_mobile_notifications';

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    /**
     * Queue a notification for a device.
     *
     * @param string $deviceUuid Target device UUID
     * @param string $type       Notification type (e.g. 'sms_parsed', 'payment_received')
     * @param string $title      Notification title
     * @param string $body       Notification body text
     * @param array  $payload    Optional JSON payload for deep linking
     * @return int Inserted row ID
     */
    public function create(string $deviceUuid, string $type, string $title, string $body = '', array $payload = []): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . self::TABLE . " (device_uuid, type, title, body, payload, created_at)
             VALUES (:uuid, :type, :title, :body, :payload, NOW())"
        );
        $stmt->execute([
            ':uuid'    => $deviceUuid,
            ':type'    => $type,
            ':title'   => $title,
            ':body'    => $body,
            ':payload' => !empty($payload) ? json_encode($payload) : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Poll for unread notifications since a given timestamp.
     * Used by: GET /api/v1/notifications/poll?since=<timestamp>
     *
     * @param string      $deviceUuid Device UUID
     * @param string|null $since      ISO 8601 timestamp (fetch newer than this)
     * @param int         $limit      Max notifications to return
     * @return array List of notification records
     */
    public function pollSince(string $deviceUuid, ?string $since = null, int $limit = 50): array
    {
        $sql = "SELECT id, type, title, body, payload, is_read, created_at
                FROM " . self::TABLE . "
                WHERE device_uuid = :uuid";
        $params = [':uuid' => $deviceUuid];

        if ($since !== null) {
            $sql .= " AND created_at > :since";
            $params[':since'] = $since;
        } else {
            $sql .= " AND is_read = 0";
        }

        $sql .= " ORDER BY created_at DESC LIMIT :lim";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Mark notifications as read.
     *
     * @param string $deviceUuid Device UUID
     * @param array  $ids        Array of notification IDs to mark read
     * @return int Number of rows updated
     */
    public function markRead(string $deviceUuid, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE . "
             SET is_read = 1, read_at = NOW()
             WHERE device_uuid = ? AND id IN ({$placeholders})"
        );

        $params = array_merge([$deviceUuid], array_map('intval', $ids));
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Count unread notifications for a device.
     */
    public function countUnread(string $deviceUuid): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . "
             WHERE device_uuid = :uuid AND is_read = 0"
        );
        $stmt->execute([':uuid' => $deviceUuid]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Purge read notifications older than the specified age.
     *
     * @param int $olderThanDays Default: 7 days
     * @return int Number of rows deleted
     */
    public function purgeOldRead(int $olderThanDays = 7): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($olderThanDays * 86400));

        $stmt = $this->pdo->prepare(
            "DELETE FROM " . self::TABLE . "
             WHERE is_read = 1 AND read_at < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Find a notification by its ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
