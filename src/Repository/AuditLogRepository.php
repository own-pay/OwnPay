<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for audit logs (`op_audit_logs` table).
 *
 * Keeps track of user actions, modified entity values (both old and new values),
 * user metadata (IP address, user agent), and brand contexts.
 * Unscoped globally to support superadmin views across multiple brands/tenants.
 */
final class AuditLogRepository extends BaseRepository
{
    protected string $table = 'op_audit_logs';
    protected array $fillable = [
        'merchant_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'signature',
    ];

    /**
     * Records a new audit event in the system log.
     *
     * In accordance with compliance, this operation bypasses tenant scoping
     * to ensure audit records remain universally discoverable by superadmins.
     *
     * @param int|null $merchantId Associated merchant identifier, or null if system-wide.
     * @param int|null $userId Associated user identifier, or null if system-triggered.
     * @param string $action The log action key descriptor.
     * @param string|null $entityType The class or database table name of the target entity.
     * @param int|null $entityId The primary key identifier of the target entity.
     * @param array<string, mixed>|null $oldValues Entity attribute values before execution.
     * @param array<string, mixed>|null $newValues Entity attribute values after execution.
     * @param string|null $ip The client IP address executing the operation.
     * @param string|null $userAgent The client browser user agent header.
     * @return string Last inserted primary key ID of the log record.
     */
    public function record(
        ?int $merchantId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): string {
        $oldJson = $oldValues !== null ? (string)json_encode($oldValues) : null;
        $newJson = $newValues !== null ? (string)json_encode($newValues) : null;
        $ua = $userAgent ? mb_substr($userAgent, 0, 500) : null;

        $signature = $this->calculateSignature(
            $merchantId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldJson,
            $newJson,
            $ip,
            $ua
        );

        return $this->create([
            'merchant_id' => $merchantId,
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues !== null ? json_encode($oldValues) : null,
            'new_values'  => $newValues !== null ? json_encode($newValues) : null,
            'ip_address'  => $ip,
            'user_agent'  => $ua,
            'signature'   => $signature,
        ]);
    }

    /**
     * Calculates the SHA-256 HMAC signature for a log entry row context.
     */
    public function calculateSignature(
        ?int $merchantId,
        ?int $userId,
        string $action,
        ?string $entityType,
        ?int $entityId,
        ?string $oldValuesJson,
        ?string $newValuesJson,
        ?string $ip,
        ?string $userAgent
    ): string {
        $secret = \OwnPay\Service\System\EnvironmentService::get('AUDIT_HMAC_SECRET');
        if ($secret === '' || strlen($secret) < 32) {
            throw new \RuntimeException('Insecure or missing AUDIT_HMAC_SECRET. Secret must be at least 32 characters long.');
        }

        $oldNormalized = null;
        if ($oldValuesJson !== null && $oldValuesJson !== '') {
            $decoded = json_decode($oldValuesJson, true);
            if (is_array($decoded)) {
                $this->canonicalizeArray($decoded);
                $oldNormalized = json_encode($decoded);
            } else {
                $oldNormalized = $oldValuesJson;
            }
        }

        $newNormalized = null;
        if ($newValuesJson !== null && $newValuesJson !== '') {
            $decoded = json_decode($newValuesJson, true);
            if (is_array($decoded)) {
                $this->canonicalizeArray($decoded);
                $newNormalized = json_encode($decoded);
            } else {
                $newNormalized = $newValuesJson;
            }
        }

        $payload = sprintf(
            '%s|%s|%s|%s|%s|%s|%s|%s|%s',
            $merchantId !== null ? (string)$merchantId : '',
            $userId !== null ? (string)$userId : '',
            $action,
            $entityType !== null ? $entityType : '',
            $entityId !== null ? (string)$entityId : '',
            $oldNormalized !== null ? (string)$oldNormalized : '',
            $newNormalized !== null ? (string)$newNormalized : '',
            $ip !== null ? $ip : '',
            $userAgent !== null ? $userAgent : ''
        );

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Recursively sorts array keys to ensure canonical JSON representation.
     *
     * @param array<mixed, mixed> $array
     */
    private function canonicalizeArray(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->canonicalizeArray($value);
            }
        }
    }

    /**
     * Verifies the integrity of all logged events and returns any compromised entries.
     *
     * @return array<int, array<string, mixed>> List of corrupted audit log rows.
     */
    public function verifyIntegrity(): array
    {
        $rows = $this->db->fetchAll("SELECT * FROM {$this->table} ORDER BY id ASC");
        $compromised = [];

        foreach ($rows as $row) {
            $merchantId = isset($row['merchant_id']) && is_scalar($row['merchant_id']) ? (int) $row['merchant_id'] : null;
            $userId = isset($row['user_id']) && is_scalar($row['user_id']) ? (int) $row['user_id'] : null;
            $action = isset($row['action']) && is_scalar($row['action']) ? (string) $row['action'] : '';
            $entityType = isset($row['entity_type']) && is_scalar($row['entity_type']) ? (string) $row['entity_type'] : null;
            $entityId = isset($row['entity_id']) && is_scalar($row['entity_id']) ? (int) $row['entity_id'] : null;
            $oldValuesJson = isset($row['old_values']) && is_scalar($row['old_values']) ? (string) $row['old_values'] : null;
            $newValuesJson = isset($row['new_values']) && is_scalar($row['new_values']) ? (string) $row['new_values'] : null;
            $ip = isset($row['ip_address']) && is_scalar($row['ip_address']) ? (string) $row['ip_address'] : null;
            $userAgent = isset($row['user_agent']) && is_scalar($row['user_agent']) ? (string) $row['user_agent'] : null;
            $storedSignature = isset($row['signature']) && is_scalar($row['signature']) ? (string) $row['signature'] : '';

            // Skip entries that were created before the signature column was added (storedSignature === null)
            if ($row['signature'] === null) {
                continue;
            }

            $calculated = $this->calculateSignature(
                $merchantId,
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValuesJson,
                $newValuesJson,
                $ip,
                $userAgent
            );

            if (!hash_equals($calculated, $storedSignature)) {
                $compromised[] = $row;
            }
        }

        return $compromised;
    }

    /**
     * Signs any pre-existing logs that do not currently have a signature.
     *
     * @return int Number of signed pre-existing logs.
     */
    public function signExistingLogs(): int
    {
        $rows = $this->db->fetchAll("SELECT * FROM {$this->table} WHERE signature IS NULL");
        $count = 0;

        foreach ($rows as $row) {
            $id = isset($row['id']) && is_scalar($row['id']) ? (int)$row['id'] : 0;
            $merchantId = isset($row['merchant_id']) && is_scalar($row['merchant_id']) ? (int) $row['merchant_id'] : null;
            $userId = isset($row['user_id']) && is_scalar($row['user_id']) ? (int) $row['user_id'] : null;
            $action = isset($row['action']) && is_scalar($row['action']) ? (string) $row['action'] : '';
            $entityType = isset($row['entity_type']) && is_scalar($row['entity_type']) ? (string) $row['entity_type'] : null;
            $entityId = isset($row['entity_id']) && is_scalar($row['entity_id']) ? (int) $row['entity_id'] : null;
            $oldValuesJson = isset($row['old_values']) && is_scalar($row['old_values']) ? (string) $row['old_values'] : null;
            $newValuesJson = isset($row['new_values']) && is_scalar($row['new_values']) ? (string) $row['new_values'] : null;
            $ip = isset($row['ip_address']) && is_scalar($row['ip_address']) ? (string) $row['ip_address'] : null;
            $userAgent = isset($row['user_agent']) && is_scalar($row['user_agent']) ? (string) $row['user_agent'] : null;

            $signature = $this->calculateSignature(
                $merchantId,
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValuesJson,
                $newValuesJson,
                $ip,
                $userAgent
            );

            $this->db->execute(
                "UPDATE {$this->table} SET signature = :sig WHERE id = :id",
                ['sig' => $signature, 'id' => $id]
            );
            $count++;
        }

        return $count;
    }


    /**
     * Lists audit log records with sorting and pagination, optionally scoped by merchant ID.
     *
     * Joins user profiles to obtain displayable operator names.
     *
     * @param int|null $merchantId Scoping merchant ID context, or null for all merchants.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return array<int, array<string, mixed>> List of audit log records.
     */
    public function listPaginated(?int $merchantId, int $limit, int $offset): array
    {
        $where = $merchantId !== null ? 'WHERE l.merchant_id = :mid' : '';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];
        $params['lim'] = $limit;
        $params['off'] = $offset;

        return $this->db->fetchAll(
            "SELECT l.*, u.name as user_name
             FROM {$this->table} l
             LEFT JOIN op_merchant_users u ON u.id = l.user_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT :lim OFFSET :off",
            $params
        );
    }

    /**
     * Counts the total audit log records matching criteria.
     *
     * @param int|null $merchantId Scoping merchant ID context, or null for all merchants.
     * @return int Matching records count.
     */
    public function countFiltered(?int $merchantId): int
    {
        $where = $merchantId !== null ? 'merchant_id = :mid' : '1=1';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];

        return $this->db->count($this->table, $where, $params);
    }

    /**
     * Retrieves all audit log entries associated with a specific entity.
     *
     * @param string $entityType The entity's structural type name.
     * @param int $entityId The primary key identifier of the target entity.
     * @return array<int, array<string, mixed>> List of matching audit log entries.
     */
    public function listForEntity(string $entityType, int $entityId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE entity_type = :et AND entity_id = :eid ORDER BY created_at DESC",
            ['et' => $entityType, 'eid' => $entityId]
        );
    }
}
