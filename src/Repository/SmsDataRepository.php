<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * Repository class responsible for database operations, persistence, and querying
 * of parsed SMS data entries within the 'op_sms_parsed' table.
 *
 * This repository processes incoming notification streams transmitted by mobile companion
 * devices. It manages duplicate checking, merchant-scoped listing, and queue counts
 * under strict tenant contexts to ensure brand-level isolation.
 */
final class SmsDataRepository extends BaseRepository
{
    use TenantScope;

    /**
     * The database table name associated with this repository.
     *
     * @var string
     */
    protected string $table = 'op_sms_parsed';

    /**
     * The list of columns that are safe to be bulk-filled on insertion or update.
     *
     * @var array<int, string>
     */
    protected array $fillable = [
        'merchant_id', 'device_id', 'local_id', 'sender', 'body', 'amount',
        'trx_id', 'parsed_sender', 'parsed_balance', 'gateway_slug',
        'parser_type', 'parsed_type', 'template_id', 'parse_confidence',
        'match_status', 'transaction_id', 'raw_data', 'encrypted_raw',
        'received_at',
    ];

    /**
     * Detects if an incoming SMS entry is a duplicate by scanning for records
     * from the same device, matching sender, and matching received timestamp
     * within a strict ±1 second tolerance window.
     *
     * @param string $deviceId The pairing identifier of the originating device.
     * @param string $sender The raw sender address or number.
     * @param string $receivedAt The date-time string of when the SMS was received.
     * @return bool True if a matching duplicate is found, false otherwise.
     * @throws \LogicException If the active tenant context cannot be resolved.
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
        $cntVal = $row['cnt'] ?? 0;
        return ((int) (is_scalar($cntVal) ? $cntVal : 0)) > 0;
    }

    /**
     * Retrieves a paginated list of parsed SMS records scoped under the active tenant,
     * optionally filtered by their transaction matching status.
     *
     * @param int $limit Maximum number of records to return. Defaults to 20.
     * @param int $offset Numerical offset for database query pagination. Defaults to 0.
     * @param string|null $status Optional status filter (e.g., 'matched', 'pending', 'ignored').
     * @return array{items: array<int, array<string, mixed>>, total: int} A structure containing list of items and total count.
     * @throws \LogicException If the active tenant context cannot be resolved.
     */
    public function listPaginated(int $limit = 20, int $offset = 0, ?string $status = null): array
    {
        $conds = [];
        $params = [];
        if ($this->tenantId !== null) {
            $conds[] = "merchant_id = :mid";
            $params['mid'] = $this->tenantId;
        }
        if ($status !== null) {
            $conds[] = "match_status = :status";
            $params['status'] = $status;
        }
        $where = $conds === [] ? '1=1' : implode(' AND ', $conds);

        $total = $this->db->count($this->table, $where, $params);

        $items = $this->db->fetchAll(
            "SELECT id, device_id, sender, received_at, amount, trx_id,
                    gateway_slug, parser_type, parsed_type, parse_confidence,
                    match_status, created_at
             FROM {$this->table}
             WHERE {$where}
             ORDER BY received_at DESC
             LIMIT :lim OFFSET :off",
            array_merge($params, ['lim' => $limit, 'off' => $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Counts the total number of unresolved or unmatched SMS entries for the active tenant,
     * which represent the pending review queue for administrator verification.
     *
     * @return int The total count of pending SMS entries.
     * @throws \LogicException If the active tenant context cannot be resolved.
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
     * Updates key attributes on an existing SMS record to assist in admin-level
     * resolution, transaction matching, or reprocessing.
     *
     * @param int $id The internal primary identifier of the target SMS record.
     * @param array<string, mixed> $data Array of updated values to apply.
     * @return int The number of affected database rows.
     * @throws \LogicException If the active tenant context cannot be resolved.
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
