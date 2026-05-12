<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * SmsDataRepository — CRUD for `op_sms_parsed`.
 *
 * Stores parsed SMS data submitted by mobile companion devices.
 */
final class SmsDataRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_sms_parsed';
    protected array $fillable = [
        'merchant_id', 'device_id', 'local_id', 'sender', 'body', 'amount',
        'trx_id', 'parsed_sender', 'parsed_balance', 'gateway_slug',
        'parser_type', 'parsed_type', 'template_id', 'parse_confidence',
        'match_status', 'transaction_id', 'raw_data', 'encrypted_raw',
        'received_at',
    ];

    /**
     * Check for duplicate: same device + sender + received_at within 1-second window.
     */
    public function isDuplicate(string $deviceId, string $sender, string $receivedAt): bool
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM {$this->table}
             WHERE device_id = :did AND sender = :sender
               AND ABS(TIMESTAMPDIFF(SECOND, received_at, :received_at)) <= 1
               AND merchant_id = :mid
             LIMIT 1",
            [
                'did'         => $deviceId,
                'sender'      => $sender,
                'received_at' => $receivedAt,
                'mid'         => $this->requireTenant(),
            ]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    /**
     * List parsed SMS for merchant with pagination.
     */
    public function listPaginated(int $limit = 20, int $offset = 0, ?string $status = null): array
    {
        $where = "merchant_id = :mid";
        $params = ['mid' => $this->requireTenant()];

        if ($status !== null) {
            $where .= " AND match_status = :status";
            $params['status'] = $status;
        }

        $total = $this->db->count($this->table, $where, $params);

        $items = $this->db->fetchAll(
            "SELECT id, device_id, sender, received_at, amount, trx_id,
                    gateway_slug, parser_type, parsed_type, parse_confidence,
                    match_status, created_at
             FROM {$this->table}
             WHERE {$where}
             ORDER BY received_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Count unparsed entries for merchant (admin review queue).
     */
    public function countUnparsed(): int
    {
        return $this->db->count(
            $this->table,
            "merchant_id = :mid AND match_status = 'pending'",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Update parsed data on existing SMS record (admin reprocess/resolve).
     */
    public function updateParsedData(int $id, array $data): int
    {
        $allowed = [
            'amount', 'trx_id', 'gateway_slug',
            'parser_type', 'match_status', 'transaction_id',
        ];

        $update = array_intersect_key($data, array_flip($allowed));
        if (empty($update)) {
            return 0;
        }

        return $this->updateScoped($id, $update);
    }
}
