<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;
use OwnPay\Support\DateHelper;

/**
 * Repository layer for payment intents (`op_payment_intents` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages customer checkout intention states, token generation for guest-checkout access,
 * and expirations.
 *
 * @package OwnPay\Repository
 */
final class PaymentIntentRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_payment_intents';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'uuid', 'token', 'customer_id', 'amount', 'currency',
        'description', 'metadata', 'redirect_url', 'cancel_url', 'webhook_url',
        'status', 'expires_at',
    ];

    /**
     * Creates a new payment intent record.
     *
     * Automatically assigns a UUIDv4 and a cryptographically secure token.
     *
     * @param array<string, mixed> $data Raw intent fields.
     * @return string The primary key ID of the newly created intent.
     */
    public function createIntent(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['token'] = bin2hex(random_bytes(32));
        $data['expires_at'] = $data['expires_at'] ?? DateHelper::future(600);
        return $this->createScoped($data);
    }

    /**
     * Finds a payment intent record by its unique secure access token.
     *
     * @param string $token Secure token.
     * @return array<string, mixed>|null The payment intent record, or null if not found.
     */
    public function findByToken(string $token): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE token = :t LIMIT 1",
            ['t' => $token]
        );
    }

    /**
     * Reverts a payment intent from `processing` back to `pending` so the customer can pick a
     * different gateway from the checkout page. Scoped strictly to `processing` - never touches
     * `completed`/`failed`/`cancelled`/`expired`.
     *
     * Issue #338 (PAY-10): the revert is now gated by a 10-minute cooldown from the last
     * `updated_at` timestamp. The cooldown mirrors
     * {@see TransactionRepository::reactivateForRetry()} and prevents a customer from
     * reverting an in-flight payment that the gateway may still be processing - in that
     * window the gateway could still complete the original transaction and the customer
     * would also pay via the second gateway they pick, doubling the charge. 10 minutes
     * is long enough for any reasonable gateway round-trip to settle one way or the other;
     * after it, an abandoned `processing` intent is safe to reclaim.
     *
     * Payment intents do not carry a `gateway_slug` column - the slug lives on the linked
     * `op_transactions` row, which is reverted separately by
     * {@see TransactionRepository::reactivateForRetryByIntentId()} (and that method clears
     * the slug). So this method only needs to apply the cooldown.
     *
     * @param string $token Secure intent token.
     * @return bool True if a row was actually reverted, false if no matching `processing` row
     *              existed (or it was too recent to safely revert per the cooldown).
     */
    public function reactivateForRetry(string $token): bool
    {
        $stmt = $this->db->execute(
            "UPDATE {$this->table}
             SET status = 'pending', updated_at = NOW()
             WHERE token = :t
               AND status = 'processing'
               AND updated_at < (NOW() - INTERVAL 10 MINUTE)",
            ['t' => $token]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * Expire stale pending payment intents.
     *
     * Utilizes the `idx_expires` database index for fast execution.
     *
     * @return int Number of updated intent records.
     */
    public function expireStale(): int
    {
        return $this->db->update(
            "UPDATE {$this->table} SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW(6)",
            []
        );
    }
}
