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
        // 1. Request attribute resolution
        $fromReq = $req->getAttribute('merchant_id');
        if ($fromReq !== null) {
            $this->activeBrandId = (int) $fromReq;
            return $this->activeBrandId;
        }

        // 2. Session state checks (guarding against CLI or API bootstrap environments)
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['active_brand_id'])) {
                $this->activeBrandId = (int) $_SESSION['active_brand_id'];
                return $this->activeBrandId;
            }

            if (isset($_SESSION['auth_merchant_id'])) {
                $this->activeBrandId = (int) $_SESSION['auth_merchant_id'];
                return $this->activeBrandId;
            }
        }

        // 3. System fallback resolution
        $first = $this->db->fetchOne("SELECT id FROM op_merchants ORDER BY id ASC LIMIT 1");
        if ($first) {
            $this->activeBrandId = (int) $first['id'];
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
            if (isset($_SESSION['active_brand_id'])) {
                return (int) $_SESSION['active_brand_id'];
            }
            if (isset($_SESSION['auth_merchant_id'])) {
                return (int) $_SESSION['auth_merchant_id'];
            }
        }

        return null;
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
     * @return array<int, array{id: int, name: string, slug: string, logo_path: string|null, status: string}> List of brand profiles.
     */
    public function getAllBrands(): array
    {
        if ($this->brandsCache !== null) {
            return $this->brandsCache;
        }

        $this->brandsCache = $this->db->fetchAll(
            "SELECT id, name, slug, logo_path, status FROM op_merchants ORDER BY name ASC"
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
        if ($id === null) {
            return null;
        }
        return $this->db->fetchOne("SELECT * FROM op_merchants WHERE id = :id", ['id' => $id]);
    }
}
