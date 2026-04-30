<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class CustomerRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_customers';
    protected array $fillable = [
        'merchant_id', 'uuid', 'name_enc', 'email_enc', 'email_hash',
        'phone_enc', 'phone_hash', 'metadata',
    ];

    /**
     * Find by email hash (for lookup without decryption).
     */
    public function findByEmailHash(string $hash): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE email_hash = :h AND merchant_id = :mid LIMIT 1",
            ['h' => $hash, 'mid' => $this->requireTenant()]
        );
    }
}
