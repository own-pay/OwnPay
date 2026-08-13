<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for payment links (`op_payment_links` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages reusable customer payment links, limits, and usage counts.
 *
 * @package OwnPay\Repository
 */
final class PaymentLinkRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_payment_links';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'uuid', 'slug', 'title', 'description', 'amount', 'currency',
        'is_amount_fixed', 'require_address', 'min_amount', 'max_amount', 'redirect_url',
        'max_uses', 'use_count', 'expires_at', 'status',
    ];

    /**
     * Finds a payment link record by its unique URL slug.
     *
     * @param string $slug Unique URL slug.
     * @return array<string, mixed>|null The payment link record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * Finds an active payment link by its slug (used during checkout initialization).
     *
     * @param string $slug Unique URL slug.
     * @return array<string, mixed>|null The active payment link record, or null if not found/inactive.
     */
    public function findActiveBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE slug = :slug AND status = 'active'",
            ['slug' => $slug]
        );
    }

    /**
     * Increments the link usage counter.
     *
     * @param int $id The primary key ID of the payment link.
     * @return void
     */
    public function incrementUseCount(int $id): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET use_count = use_count + 1 WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Atomically increments the link usage counter and deactivates the link if the
     * resulting use_count meets/exceeds the configured max_uses threshold.
     *
     * The increment and the status transition are executed in a single SQL UPDATE so
     * that two concurrent completions of the same link cannot both observe a
     * sub-threshold use_count and both leave the link active (the previous
     * increment-then-check-then-deactivate sequence was non-atomic - see audit
     * finding PAY-13).
     *
     * The UPDATE predicate (`max_uses = 0 OR use_count < max_uses`) prevents
     * use_count from exceeding max_uses: once the link is exhausted the statement
     * matches no rows. The method therefore returns the post-increment use_count
     * when the increment succeeded, or 0 when the link was already exhausted
     * (or does not exist).
     *
     * @param int $id The primary key ID of the payment link.
     *
     * @return int The post-increment use_count, or 0 if the link was already
     *             exhausted (no row updated) - callers should treat 0 as
     *             "do not allow this completion to proceed / refund".
     */
    public function incrementUseCountAtomic(int $id): int
    {
        $affected = $this->db->update(
            "UPDATE {$this->table}
                SET use_count = use_count + 1,
                    status = CASE
                        WHEN max_uses > 0 AND (use_count + 1) >= max_uses THEN 'inactive'
                        ELSE status
                    END
              WHERE id = :id
                AND (max_uses = 0 OR use_count < max_uses)",
            ['id' => $id]
        );

        if ($affected <= 0) {
            return 0;
        }

        $row = $this->db->fetchOne(
            "SELECT use_count FROM {$this->table} WHERE id = :id LIMIT 1",
            ['id' => $id]
        );
        $useCountVal = $row['use_count'] ?? 0;
        return is_scalar($useCountVal) ? (int) $useCountVal : 0;
    }
}

