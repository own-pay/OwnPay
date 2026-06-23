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
}

