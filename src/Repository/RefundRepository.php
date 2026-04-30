<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

final class RefundRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_refunds';
    protected array $fillable = [
        'merchant_id', 'transaction_id', 'uuid', 'amount', 'reason',
        'status', 'processed_at',
    ];

    public function createRefund(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        return $this->createScoped($data);
    }
}
