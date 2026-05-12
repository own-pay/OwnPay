<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class DomainRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_domains';
    protected array $fillable = [
        'merchant_id', 'domain', 'type', 'verification_token',
        'dns_verified', 'dns_verified_at', 'ssl_status', 'redirect_url',
        'is_primary', 'status',
    ];

    public function findByDomain(string $domain): ?array
    {
        return $this->findBy('domain', $domain);
    }

    public function findActiveDomain(): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' AND dns_verified = 1 ORDER BY is_primary DESC, id DESC LIMIT 1",
            ['mid' => $this->requireTenant()]
        );
    }

    public function listAllScoped(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY created_at DESC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Find all domains pending DNS verification (global — used by cron).
     */
    public function findPendingVerification(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE dns_verified = 0 AND status = 'pending'"
        );
    }
}
