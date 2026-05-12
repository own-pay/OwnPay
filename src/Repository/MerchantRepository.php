<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

final class MerchantRepository extends BaseRepository
{
    protected string $table = 'op_merchants';
    protected array $fillable = [
        'uuid', 'name', 'slug', 'email', 'phone', 'logo_path',
        'timezone', 'default_currency', 'webhook_secret', 'settings', 'status',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    public function createMerchant(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['webhook_secret'] = bin2hex(random_bytes(32));
        return $this->create($data);
    }

    /**
     * List all brands with primary domain (admin listing).
     */
    public function listWithDomains(): array
    {
        return $this->db->fetchAll(
            "SELECT m.id, m.name, m.slug, m.logo_path, m.status, m.created_at,
                    d.domain as primary_domain
             FROM {$this->table} m
             LEFT JOIN op_domains d ON d.merchant_id = m.id AND d.is_primary = 1
             ORDER BY m.created_at DESC"
        );
    }

    /**
     * Find merchant with primary domain info.
     */
    public function findWithDomain(int $id): ?array
    {
        $brand = $this->find($id);
        if (!$brand) return null;

        $domain = $this->db->fetchOne(
            "SELECT domain, dns_verified FROM op_domains WHERE merchant_id = :mid AND is_primary = 1 LIMIT 1",
            ['mid' => $id]
        );
        if ($domain) {
            $brand['primary_domain'] = $domain['domain'];
            $brand['dns_verified'] = $domain['dns_verified'];
        }
        return $brand;
    }

    /**
     * Update brand fields.
     */
    public function updateBrand(int $id, array $data): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET name = :name, email = :email, phone = :phone,
             timezone = :tz, default_currency = :cur, status = :status
             WHERE id = :id",
            [
                'name'   => $data['name'],
                'email'  => $data['email'],
                'phone'  => $data['phone'] ?? '',
                'tz'     => $data['timezone'] ?? 'Asia/Dhaka',
                'cur'    => $data['default_currency'] ?? 'BDT',
                'status' => $data['status'] ?? 'active',
                'id'     => $id,
            ]
        );
    }
}
