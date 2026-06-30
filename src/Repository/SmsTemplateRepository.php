<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository class responsible for managing database persistence, retrieval, and matching
 * of SMS parsing templates within the 'op_sms_templates' table.
 *
 * SMS templates contain regular expressions used by mobile companion devices or local parsers
 * to extract transaction IDs (trx_id) and payment amounts from incoming SMS text notifications
 * (e.g. from bKash, Nagad, etc.). Scoped by merchant (brand) or configured globally.
 */
final class SmsTemplateRepository extends BaseRepository
{
    use TenantScope;

    /**
     * The database table name associated with this repository.
     *
     * @var string
     */
    protected string $table = 'op_sms_templates';

    /**
     * The list of columns that are safe to be bulk-filled on insertion or update.
     *
     * @var array<int, string>
     */
    protected array $fillable = [
        'merchant_id', 'gateway_slug', 'sender_pattern', 'amount_regex',
        'trx_id_regex', 'sender_regex', 'priority', 'status',
    ];

    /**
     * Retrieves all active parsing templates ordered by priority, including both
     * merchant-specific templates and global fallback templates (where merchant_id is NULL).
     *
     * @param int|null $merchantId The unique identifier of the merchant brand, or null to load only globals.
     * @return array<int, array<string, mixed>> List of active SMS template records.
     */
    public function listActiveForMerchant(?int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active' AND (merchant_id IS NULL OR merchant_id = :mid)
             ORDER BY priority ASC",
            ['mid' => $merchantId]
        );
    }

    /**
     * Finds active templates whose sender pattern matches the incoming SMS sender string.
     *
     * Matching is **case-insensitive** (`LOWER(...) = LOWER(...)`): carriers send the same alpha sender
     * id with inconsistent casing ("bKash" / "bkash" / "BKASH"), so a case-sensitive (BINARY) match
     * silently failed to find an otherwise-correct template — the SMS then fell through to admin-review
     * unparsed. This mirrors the device-side whitelist ([getSenderWhitelist]), which is also
     * case-insensitive, keeping capture and parsing consistent. Retrieves both merchant-specific and
     * global (merchant_id IS NULL) configurations.
     *
     * @param string $sender The sender identifier (From field) received from the mobile app.
     * @param int $brandId The unique identifier of the merchant brand.
     * @return array<int, array<string, mixed>> Matching template records ordered by priority ascending.
     */
    public function findBySender(string $sender, int $brandId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND (merchant_id IS NULL OR merchant_id = :mid)
               AND LOWER(sender_pattern) = LOWER(:sender)
             ORDER BY priority ASC",
            ['mid' => $brandId, 'sender' => $sender]
        );
    }

    /**
     * Retrieves a distinct list of active sender patterns for a specific merchant.
     * Used by companion devices to whitelist and filter SMS messages on the device before transmission.
     *
     * @param int $brandId The unique identifier of the merchant brand.
     * @return string[] List of distinct non-empty sender pattern strings.
     */
    public function getSenderWhitelist(int $brandId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT sender_pattern FROM {$this->table}
             WHERE status = 'active'
               AND (merchant_id IS NULL OR merchant_id = :mid)
               AND sender_pattern != ''",
            ['mid' => $brandId]
        );
        $result = [];
        foreach ($rows as $row) {
            $pattern = $row['sender_pattern'] ?? '';
            if (is_string($pattern) && $pattern !== '') {
                $result[] = $pattern;
            }
        }
        return $result;
    }

    // --- Admin methods
    /**
     * Retrieves all templates registered for a merchant for administration display.
     *
     * @param int $merchantId The unique identifier of the merchant brand.
     * @param string $orderBy Column sorting specification. Sanity checks are applied internally.
     * @return array<int, array<string, mixed>> List of matching template records.
     */
    public function listForAdmin(int $merchantId, string $orderBy = 'priority ASC, created_at DESC'): array
    {
        $safeOrder = $this->sanitizeOrderBy($orderBy);
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY {$safeOrder}",
            ['mid' => $merchantId]
        );
    }

    /**
     * Finds a single template scoped strictly to a specific merchant brand.
     *
     * @param int $id The internal primary key identifier of the template.
     * @param int $merchantId The unique identifier of the merchant brand.
     * @return array<string, mixed>|null The template record, or null if not found.
     */
    public function findForAdmin(int $id, int $merchantId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE id = :id AND merchant_id = :mid LIMIT 1",
            ['id' => $id, 'mid' => $merchantId]
        );
    }

    /**
     * Creates a new parsing template for the specified merchant.
     *
     * @param int $merchantId The unique identifier of the merchant brand.
     * @param array<string, mixed> $data Field value pairs containing configuration patterns.
     * @return string The newly generated template's auto-increment or primary key.
     */
    public function createTemplate(int $merchantId, array $data): string
    {
        return $this->create([
            'merchant_id'    => $merchantId,
            'gateway_slug'   => $data['gateway_slug'] ?? '',
            'sender_pattern' => $data['sender_pattern'] ?? '',
            'amount_regex'   => $data['amount_regex'] ?? '',
            'trx_id_regex'   => $data['trx_id_regex'] ?? '',
            'sender_regex'   => $data['sender_regex'] ?? '',
            'priority'       => (int) (isset($data['priority']) && is_scalar($data['priority']) ? $data['priority'] : 10),
            'status'         => $data['status'] ?? 'active',
        ]);
    }

    /**
     * Updates parsing template fields for a given merchant.
     *
     * @param int $id The internal primary key of the target template.
     * @param int $merchantId The unique identifier of the merchant brand.
     * @param array<string, mixed> $data Set of field key-value pairs to modify.
     * @return void
     */
    public function updateTemplate(int $id, int $merchantId, array $data): void
    {
        $fields = [];
        $params = ['id' => $id, 'mid' => $merchantId];
        $allowed = ['gateway_slug', 'sender_pattern', 'amount_regex', 'trx_id_regex', 'sender_regex', 'priority', 'status'];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        if (empty($fields)) {
            return;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id AND merchant_id = :mid";
        $this->db->execute($sql, $params);
    }

    /**
     * Deletes a template scoped strictly to a specific merchant brand.
     *
     * @param int $id The internal primary key of the template to delete.
     * @param int $merchantId The unique identifier of the merchant brand.
     * @return void
     */
    public function deleteTemplate(int $id, int $merchantId): void
    {
        $this->db->execute(
            "DELETE FROM {$this->table} WHERE id = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $merchantId]
        );
    }

    /**
     * Counts the total number of templates registered for the specified merchant.
     *
     * @param int $merchantId The unique identifier of the merchant brand.
     * @return int The total template count.
     */
    public function countForMerchant(int $merchantId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM {$this->table} WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );
        $cntVal = $row['cnt'] ?? 0;
        return is_scalar($cntVal) ? (int) $cntVal : 0;
    }

    /**
     * Retrieves active templates scoped to tenant, preventing cross-tenant leakage.
     *
     * @param int $merchantId The unique identifier of the merchant brand.
     * @return array<int, array<string, mixed>> List of matching template records.
     */
    public function listActiveForTenant(int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' AND (merchant_id IS NULL OR merchant_id = :mid) ORDER BY priority ASC",
            ['mid' => $merchantId]
        );
    }

    /**
     * Retrieves the active templates a paired companion DEVICE should use to build its on-device sender
     * whitelist: the device's OWN brand templates, PLUS true globals (merchant_id NULL), PLUS the reserved
     * "All Brands" (platform) templates. This is what makes an All-Brands template apply to EVERY device
     * regardless of which brand the device paired under - without it, a device scoped to brand X (or to the
     * platform) never sees templates owned by a different scope, so its whitelist comes back empty and the
     * fail-closed on-device gate drops every SMS. The platform id is resolved via the is_platform flag
     * (its value varies per database), never hard-coded.
     *
     * @param int $merchantId The device's own brand/merchant id (from its JWT scope).
     * @return array<int, array<string, mixed>> Active template records ordered by priority ascending.
     */
    public function listActiveForDevice(int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND (
                 merchant_id IS NULL
                 OR merchant_id = :mid
                 OR merchant_id = (SELECT id FROM op_merchants WHERE is_platform = 1 ORDER BY id ASC LIMIT 1)
               )
             ORDER BY priority ASC",
            ['mid' => $merchantId]
        );
    }

    /**
     * Compatibility alias for listActiveForTenant resolving merchant ID from context.
     *
     * @return array<int, array<string, mixed>> List of active template records.
     * @throws \RuntimeException If the active tenant context cannot be resolved.
     */
    public function listActive(): array
    {
        return $this->listActiveForTenant($this->requireTenant());
    }
}
