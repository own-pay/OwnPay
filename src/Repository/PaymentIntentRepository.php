<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

final class PaymentIntentRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_payment_intents';
    protected array $fillable = [
        'merchant_id', 'uuid', 'token', 'customer_id', 'amount', 'currency',
        'description', 'metadata', 'redirect_url', 'cancel_url', 'webhook_url',
        'status', 'expires_at',
    ];

    public function createIntent(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['token'] = bin2hex(random_bytes(32));
        $data['expires_at'] = $data['expires_at'] ?? date('Y-m-d H:i:s', time() + 600);
        return $this->createScoped($data);
    }

    public function findByToken(string $token): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE token = :t LIMIT 1",
            ['t' => $token]
        );
    }

    /**
     * Expire stale intents. Uses idx_expires index.
     */
    public function expireStale(): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW(6)",
            []
        );
    }
}
