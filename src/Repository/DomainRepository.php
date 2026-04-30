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

    public function markVerified(int $id): int
    {
        return $this->updateScoped($id, [
            'dns_verified' => 1,
            'dns_verified_at' => date('Y-m-d H:i:s.u'),
            'status' => 'active',
        ]);
    }
}
