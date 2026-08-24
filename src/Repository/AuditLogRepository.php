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
        'prev_hash',
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
        // Defense-in-depth: redact sensitive keys (password_hash, totp_secret,
        // api_key, webhook_secret, etc.) BEFORE serializing to JSON. Without
        // this, the audit log itself becomes a repository of secrets. See
        // LogSanitizer::REDACT_KEYS for the full list. The signature is also
        // computed against the sanitized JSON so verifyIntegrity() stays
        // consistent with what was stored.
        $sanitizedOld = $oldValues !== null ? \OwnPay\Security\LogSanitizer::sanitize($oldValues) : null;
        $sanitizedNew = $newValues !== null ? \OwnPay\Security\LogSanitizer::sanitize($newValues) : null;

        $oldJson = $sanitizedOld !== null ? (string)json_encode($sanitizedOld) : null;
        $newJson = $sanitizedNew !== null ? (string)json_encode($sanitizedNew) : null;
        $ua = $userAgent ? mb_substr($userAgent, 0, 500) : null;

        // SYS-1: Resolve the previous row's signature so we can chain.
        // The forward hash chain makes deletion/insertion detectable:
        // each row's signature includes the previous row's signature, so
        // removing a row breaks the chain link between its predecessor
        // and successor.
        $prevRow = $this->db->fetchOne(
            "SELECT signature FROM {$this->table} ORDER BY id DESC LIMIT 1"
        );
        $prevHash = (is_array($prevRow) && isset($prevRow['signature']) && is_string($prevRow['signature']))
            ? $prevRow['signature']
            : null;

        $signature = $this->calculateSignature(
            $merchantId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldJson,
            $newJson,
            $ip,
            $ua,
            $prevHash
        );

        return $this->create([
            'merchant_id' => $merchantId,
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldJson,
            'new_values'  => $newJson,
            'ip_address'  => $ip,
            'user_agent'  => $ua,
            'signature'   => $signature,
            'prev_hash'   => $prevHash,
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
        ?string $userAgent,
        ?string $prevHash = null
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

        // SYS-1: Include prevHash in the payload so the signature forms
        // a forward hash chain. Without this, an attacker with DB write
        // access could DELETE audit log entries without detection - the
        // verifyIntegrity() scan simply wouldn't see them. With the
        // chain, deleting a row breaks the link between its predecessor
        // and successor, which verifyIntegrity() flags as compromised.
        $payload = sprintf(
            '%s|%s|%s|%s|%s|%s|%s|%s|%s|%s',
            $merchantId !== null ? (string)$merchantId : '',
            $userId !== null ? (string)$userId : '',
            $action,
            $entityType !== null ? $entityType : '',
            $entityId !== null ? (string)$entityId : '',
            $oldNormalized !== null ? (string)$oldNormalized : '',
            $newNormalized !== null ? (string)$newNormalized : '',
            $ip !== null ? $ip : '',
            $userAgent !== null ? $userAgent : '',
            $prevHash !== null ? $prevHash : ''
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

        // SYS-1: Track the previous row's signature so we can verify the
        // forward hash chain. A row whose prev_hash does not match the
        // signature of its predecessor indicates either deletion (chain
        // broken) or insertion (prev_hash forged).
        $expectedPrevHash = null;

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
            $storedPrevHash = isset($row['prev_hash']) && is_scalar($row['prev_hash']) ? (string) $row['prev_hash'] : null;

            // Skip entries that were created before the signature column was added (storedSignature === null)
            if ($row['signature'] === null) {
                continue;
            }

            // SYS-1: Chain-link check. If the row's stored prev_hash does
            // not match the signature of the immediately preceding row, the
            // chain is broken - a row was deleted or inserted between the
            // predecessor and this row. We mark the row as compromised so
            // the audit-trail UI surfaces the gap.
            if ($expectedPrevHash !== null && $storedPrevHash !== null && !hash_equals($expectedPrevHash, $storedPrevHash)) {
                $compromised[] = $row;
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
                $userAgent,
                $storedPrevHash
            );

            if (!hash_equals($calculated, $storedSignature)) {
                $compromised[] = $row;
            }

            // Advance the chain pointer.
            $expectedPrevHash = $storedSignature;
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
     * Counts audit log rows that do not yet have a cryptographic signature.
     *
     * Used by the integrity-scan UI to surface "legacy unsigned rows" as a
     * separate category that the operator must explicitly review before
     * signing, rather than silently blessing them via signExistingLogs()
     * inside the scan() flow.
     *
     * @return int Number of rows where signature IS NULL.
     */
    public function countUnsigned(): int
    {
        return $this->db->count($this->table, 'signature IS NULL');
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
     * Scopes the query by merchant_id (REPO-7) so a future caller that
     * passes a user-controlled entity_id cannot read another tenant's
     * audit trail. Pass null for the superadmin "All Brands" view -
     * consistent with the existing pattern in listPaginated().
     *
     * @param string $entityType The entity's structural type name.
     * @param int $entityId The primary key identifier of the target entity.
     * @param int|null $merchantId Scoping merchant ID, or null for all merchants (superadmin).
     * @return array<int, array<string, mixed>> List of matching audit log entries.
     */
    public function listForEntity(string $entityType, int $entityId, ?int $merchantId = null): array
    {
        $where = 'entity_type = :et AND entity_id = :eid';
        $params = ['et' => $entityType, 'eid' => $entityId];

        if ($merchantId !== null) {
            $where .= ' AND merchant_id = :mid';
            $params['mid'] = $merchantId;
        }

        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY created_at DESC",
            $params
        );
    }
}
