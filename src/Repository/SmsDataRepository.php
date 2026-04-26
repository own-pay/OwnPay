<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

/**
 * SmsDataRepository — CRUD for `op_sms_parsed`.
 *
 * Stores parsed SMS data submitted by mobile companion devices.
 */
final class SmsDataRepository
{
    private const TABLE = 'op_sms_parsed';

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    /**
     * Insert a parsed SMS record.
     *
     * @return int The inserted row ID
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO " . self::TABLE . " (
            device_uuid, brand_id, local_id, sender, received_at,
            encrypted_raw, raw_message, parsed_amount, parsed_trx_id,
            parsed_sender, parsed_balance, parsed_type, parse_method,
            template_id, parse_confidence, status, processed_at
        ) VALUES (
            :device_uuid, :brand_id, :local_id, :sender, :received_at,
            :encrypted_raw, :raw_message, :parsed_amount, :parsed_trx_id,
            :parsed_sender, :parsed_balance, :parsed_type, :parse_method,
            :template_id, :parse_confidence, :status, NOW()
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':device_uuid'      => $data['device_uuid'],
            ':brand_id'         => $data['brand_id'],
            ':local_id'         => $data['local_id'] ?? null,
            ':sender'           => $data['sender'],
            ':received_at'      => $data['received_at'],
            ':encrypted_raw'    => $data['encrypted_raw'],
            ':raw_message'      => $data['raw_message'] ?? null,
            ':parsed_amount'    => $data['parsed_amount'] ?? null,
            ':parsed_trx_id'    => $data['parsed_trx_id'] ?? null,
            ':parsed_sender'    => $data['parsed_sender'] ?? null,
            ':parsed_balance'   => $data['parsed_balance'] ?? null,
            ':parsed_type'      => $data['parsed_type'] ?? 'unknown',
            ':parse_method'     => $data['parse_method'] ?? 'unparsed',
            ':template_id'      => $data['template_id'] ?? null,
            ':parse_confidence' => $data['parse_confidence'] ?? 'low',
            ':status'           => $data['status'] ?? 'accepted',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Check for duplicate: same device + sender + received_at within 1-second window.
     */
    public function isDuplicate(string $deviceUuid, string $sender, string $receivedAt): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . "
             WHERE device_uuid = :uuid AND sender = :sender
               AND ABS(TIMESTAMPDIFF(SECOND, received_at, :received_at)) <= 1
             LIMIT 1"
        );
        $stmt->execute([
            ':uuid'        => $deviceUuid,
            ':sender'      => $sender,
            ':received_at' => $receivedAt,
        ]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Find by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List parsed SMS for a brand with pagination.
     */
    public function listByBrand(int $brandId, int $limit = 20, int $offset = 0, ?string $status = null): array
    {
        $where = "brand_id = :bid";
        $params = [':bid' => $brandId];

        if ($status !== null) {
            $where .= " AND status = :status";
            $params[':status'] = $status;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM " . self::TABLE . " WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT id, device_uuid, sender, received_at, parsed_amount, parsed_trx_id,
                    parsed_type, parse_method, parse_confidence, status, created_at
             FROM " . self::TABLE . "
             WHERE {$where}
             ORDER BY received_at DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    /**
     * Count unparsed entries for a brand (admin review queue).
     */
    public function countUnparsed(int $brandId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . "
             WHERE brand_id = :bid AND status = 'admin_review'"
        );
        $stmt->execute([':bid' => $brandId]);
        return (int) $stmt->fetchColumn();
    }
}
