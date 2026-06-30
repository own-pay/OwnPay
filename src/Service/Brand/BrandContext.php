<?php
declare(strict_types=1);

namespace OwnPay\Service\Brand;

use OwnPay\Core\Database;
use OwnPay\Http\Request;

/**
 * OwnPay Brand Context Resolver.
 *
 * Central source of truth for identifying the active brand (merchant) context.
 * Resolves context via a predefined hierarchy: request attributes, active session overrides,
 * home user merchant contexts, and system default fallbacks.
 *
 * @package OwnPay\Service\Brand
 */
final class BrandContext
{
    /**
     * @var Database The database execution wrapper.
     */
    private Database $db;

    /**
     * @var int|null Cache of the resolved active brand ID.
     */
    private ?int $activeBrandId = null;

    /**
     * @var array<int, array<string, mixed>>|null Cache index of all system brands.
     */
    private ?array $brandsCache = null;

    /**
     * @var int|null Cache of the reserved "All Brands" platform-owner merchant id.
     */
    private ?int $platformId = null;

    /**
     * BrandContext constructor.
     *
     * @param Database $db The database engine.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Resolves the active brand/merchant ID from the request lifecycle or session.
     *
     * Priority:
     * 1. Request attributes populated by DomainMiddleware or BearerAuthMiddleware.
     * 2. Active session settings.
     * 3. Home merchant associated with user session.
     * 4. System default (lowest primary key brand record).
     *
     * @param Request $req The incoming HTTP request.
     * @return int|null The active brand ID.
     */
    public function resolveFromRequest(Request $req): ?int
    {
        $resolved = null;

        // 1. Request attribute resolution
        $fromReq = $req->getAttribute('merchant_id');
        if ($fromReq !== null && is_scalar($fromReq)) {
            $resolved = (int) $fromReq;
        }

        // 2. Session state checks (guarding against CLI or API bootstrap environments)
        if ($resolved === null && session_status() === PHP_SESSION_ACTIVE) {
            $abId = $_SESSION['active_brand_id'] ?? null;
            if (is_scalar($abId)) {
                $resolved = (int) $abId;
            } else {
                $amId = $_SESSION['auth_merchant_id'] ?? null;
                if (is_scalar($amId)) {
                    $resolved = (int) $amId;
                }
            }
        }

        // Verify that the resolved merchant ID actually exists in the database (0 is valid for global/all-brands view).
        // If it does not exist (e.g. database reseeded), clear the invalid session state.
        if ($resolved !== null) {
            if ($resolved === 0) {
                $this->activeBrandId = 0;
                return $this->activeBrandId;
            }
            $exists = $this->db->fetchOne("SELECT id FROM op_merchants WHERE id = :id", ['id' => $resolved]);
            if ($exists) {
                $this->activeBrandId = $resolved;
                return $this->activeBrandId;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                unset($_SESSION['active_brand_id'], $_SESSION['auth_merchant_id']);
            }
        }

        // 3. System fallback resolution
        $first = $this->db->fetchOne("SELECT id FROM op_merchants ORDER BY id ASC LIMIT 1");
        if ($first && isset($first['id']) && is_scalar($first['id'])) {
            $this->activeBrandId = (int) $first['id'];
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['active_brand_id'] = $this->activeBrandId;
                $_SESSION['auth_merchant_id'] = $this->activeBrandId;
            }
        }

        return $this->activeBrandId;
    }

    /**
     * Returns the currently resolved active brand identifier.
     *
     * @return int|null Active brand ID, or null if unresolved.
     */
    public function getActiveBrandId(): ?int
    {
        if ($this->activeBrandId !== null) {
            return $this->activeBrandId;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $abId = $_SESSION['active_brand_id'] ?? null;
            if (is_scalar($abId)) {
                return (int) $abId;
            }
            $amId = $_SESSION['auth_merchant_id'] ?? null;
            if (is_scalar($amId)) {
                return (int) $amId;
            }
        }

        return null;
    }

    /**
     * Resolves the id of the reserved "All Brands" platform-owner merchant row.
     *
     * This row owns All-Brands-scoped data (data created under an All-Brands API key, or in the
     * All Brands admin view). Its id varies per database (auto-increment), so it is ALWAYS resolved
     * via the is_platform flag, never hard-coded. Lazily ensures the row exists as a safety net so
     * All-Brands writes can never fail on a missing platform owner.
     *
     * @return int The platform-owner merchant id.
     */
    public function getPlatformId(): int
    {
        if ($this->platformId !== null) {
            return $this->platformId;
        }

        $row = $this->db->fetchOne("SELECT id FROM op_merchants WHERE is_platform = 1 ORDER BY id ASC LIMIT 1");
        if ($row !== null && isset($row['id']) && is_scalar($row['id'])) {
            return $this->platformId = (int) $row['id'];
        }

        // Safety net (fresh/partial installs, reseeded test DBs): create the reserved row idempotently.
        $this->db->execute(
            "INSERT IGNORE INTO op_merchants (uuid, name, slug, email, timezone, default_currency, status, is_platform, created_at, updated_at)
             VALUES (:uuid, 'All Brands (Platform)', '__platform__', 'platform@ownpay.local', 'UTC', 'USD', 'active', 1, NOW(6), NOW(6))",
            ['uuid' => '00000000-0000-4000-8000-0000000000aa']
        );
        $row = $this->db->fetchOne("SELECT id FROM op_merchants WHERE is_platform = 1 ORDER BY id ASC LIMIT 1");
        $id = ($row !== null && isset($row['id']) && is_scalar($row['id'])) ? (int) $row['id'] : 0;

        return $this->platformId = $id;
    }

    /**
     * Resolves the merchant id to OWN newly-created records.
     *
     * - All Brands (global) view → the platform-owner id (data is platform-owned and stays readable
     *   only by All Brands).
     * - A specific brand view → that brand's id (data is brand-owned, readable by the brand + All Brands).
     *
     * @return int The owner merchant id for write operations.
     */
    public function getWriteMerchantId(): int
    {
        if ($this->isGlobalView()) {
            return $this->getPlatformId();
        }
        $id = $this->getActiveBrandId();
        return ($id !== null && $id > 0) ? $id : $this->getPlatformId();
    }

    /**
     * Forces the active brand identifier context.
     *
     * Writes to session data if the session state is currently active.
     *
     * @param int $id The target brand identifier.
     * @return void
     */
    public function setActiveBrandId(int $id): void
    {
        $this->activeBrandId = $id;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['active_brand_id'] = $id;
        }
    }

    /**
     * Asserts whether the context is evaluating global superadmin datasets.
     *
     * @return bool True if viewing global datasets; false if scoped.
     */
    public function isGlobalView(): bool
    {
        $mode = 'single';
        if (session_status() === PHP_SESSION_ACTIVE) {
            $mode = $_SESSION['brand_view_mode'] ?? 'single';
        }
        return ($this->getActiveBrandId() === null) || ($mode === 'global');
    }

    /**
     * Toggles the global administrative view mode.
     *
     * @param bool $global True to set global mode; false for scoped.
     * @return void
     */
    public function setGlobalView(bool $global): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['brand_view_mode'] = $global ? 'global' : 'single';
        }
    }

    /**
     * Retrieves all registered brand entities.
     *
     * @return array<int, array<string, mixed>> List of brand profiles.
     */
    public function getAllBrands(): array
    {
        if ($this->brandsCache !== null) {
            return $this->brandsCache;
        }

        // Exclude the reserved platform-owner row: it is the "All Brands" scope, not a selectable brand.
        $this->brandsCache = $this->db->fetchAll(
            "SELECT id, name, slug, logo_path, color, initials, description, status FROM op_merchants WHERE is_platform = 0 ORDER BY name ASC"
        );

        return $this->brandsCache;
    }

    /**
     * Resolves the profile details of the active brand context.
     *
     * @return array<string, mixed>|null The brand details array, or null if unresolved.
     */
    public function getActiveBrand(): ?array
    {
        $id = $this->getActiveBrandId();
        if ($id === null || $id === 0) {
            return null;
        }
        return $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = :id", ['id' => $id]);
    }
}
