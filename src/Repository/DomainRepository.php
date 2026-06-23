<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for custom domains (`op_domains` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages domain verification tokens, DNS state checks, and primary routing.
 */
final class DomainRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_domains';
    protected array $fillable = [
        'merchant_id', 'domain', 'type', 'verification_token',
        'dns_verified', 'dns_verified_at', 'ssl_status', 'redirect_url',
        'is_primary', 'status',
    ];

    /**
     * Finds a domain record by hostname.
     *
     * @param string $domain The target domain hostname.
     * @return array<string, mixed>|null Domain database record, or null if not found.
     */
    public function findByDomain(string $domain): ?array
    {
        return $this->findBy('domain', $domain);
    }

    /**
     * Finds the primary active and verified custom domain under the active tenant context.
     *
     * Used for routing checkout pages and generating white-labeled brand links.
     *
     * @return array<string, mixed>|null Domain database record, or null if none found.
     */
    public function findActiveDomain(): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' AND dns_verified = 1 ORDER BY is_primary DESC, id DESC LIMIT 1",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists all custom domains registered under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of matching domain records.
     */
    public function listAllScoped(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY created_at DESC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Finds all domains currently pending DNS verification across all merchants.
     *
     * Unscoped globally to support background cron verification jobs.
     *
     * @return array<int, array<string, mixed>> List of pending domain records.
     */
    public function findPendingVerification(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE dns_verified = 0 AND status = 'pending'"
        );
    }

    /**
     * Finds verified+active domains whose ownership proof is older than the grace window.
     *
     * Used by the periodic re-verification pass: a domain that was verified once
     * and never re-checked stays trusted forever, even after the owner removes
     * the TXT record or loses control of the domain (DNS TOCTOU). Only domains
     * verified longer ago than $minHours are returned, so freshly verified
     * domains are not immediately re-checked.
     *
     * @param int $minHours Minimum age (hours) since last verification.
     * @return array<int, array<string, mixed>> Stale verified domain records.
     */
    public function findStaleVerified(int $minHours = 24): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE dns_verified = 1 AND status = 'active'
               AND (dns_verified_at IS NULL OR dns_verified_at < DATE_SUB(NOW(6), INTERVAL :hrs HOUR))",
            ['hrs' => $minHours]
        );
    }
}
