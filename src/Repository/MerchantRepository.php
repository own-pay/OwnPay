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
}
