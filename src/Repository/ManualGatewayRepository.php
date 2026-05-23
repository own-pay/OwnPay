<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for merchant-defined manual payment gateways (`op_manual_gateways` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages properties such as custom instructions, input fields configuration, SMS verification patterns,
 * and currency/limits.
 *
 * @package OwnPay\Repository
 */
final class ManualGatewayRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_manual_gateways';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'slug', 'name', 'logo_path', 'qr_code_path', 'colors',
        'input_fields', 'instructions', 'admin_notes', 'sms_verification',
        'sms_sender_pattern', 'sms_regex_template', 'currency',
        'min_amount', 'max_amount', 'sort_order', 'status',
    ];

    /**
     * Finds a manual gateway record by its unique slug under the active tenant context.
     *
     * @param string $slug Unique identifier/slug of the manual gateway.
     * @return array<string, mixed>|null The manual gateway database record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE slug = :s AND merchant_id = :mid LIMIT 1",
            ['s' => $slug, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists active manual gateway records under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of active manual gateways.
     */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' ORDER BY sort_order ASC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists all manual gateway records under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of all manual gateways.
     */
    public function listAll(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY sort_order ASC, id DESC",
            ['mid' => $this->requireTenant()]
        );
    }
}

