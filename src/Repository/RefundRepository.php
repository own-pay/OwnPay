<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

/**
 * Repository layer for customer transaction refunds (`op_refunds` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages refund generation and total refunded totals tracking.
 *
 * @package OwnPay\Repository
 */
final class RefundRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_refunds';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'transaction_id', 'uuid', 'amount', 'reason',
        'status', 'processed_at',
    ];

    /**
     * Creates a new refund record.
     *
     * Automatically generates a UUIDv4 identifier.
     *
     * @param array<string, mixed> $data Raw refund input fields.
     * @return string The primary key ID of the newly created refund.
     */
    public function createRefund(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        return $this->createScoped($data);
    }

    /**
     * Calculates the total amount refunded for a given transaction.
     *
     * @param int $transactionId Primary key ID of the transaction.
     * @param int $merchantId The merchant brand ID.
     * @return string The total refunded amount as a decimal string.
     */
    public function getTotalRefundedAmount(int $transactionId, int $merchantId): string
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM `op_refunds`
             WHERE transaction_id = :txid AND merchant_id = :mid AND status IN ('pending', 'completed')",
            ['txid' => $transactionId, 'mid' => $merchantId]
        );
        return $row['total'] ?? '0.00';
    }
}

