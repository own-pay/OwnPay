<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class ManualGatewayRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_manual_gateways';
    protected array $fillable = [
        'merchant_id', 'slug', 'name', 'logo_path', 'qr_code_path', 'colors',
        'input_fields', 'instructions', 'admin_notes', 'sms_verification',
        'sms_sender_pattern', 'sms_regex_template', 'currency',
        'min_amount', 'max_amount', 'sort_order', 'status',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE slug = :s AND merchant_id = :mid LIMIT 1",
            ['s' => $slug, 'mid' => $this->requireTenant()]
        );
    }

    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' ORDER BY sort_order ASC",
            ['mid' => $this->requireTenant()]
        );
    }

    public function listAll(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY sort_order ASC, id DESC",
            ['mid' => $this->requireTenant()]
        );
    }
}
