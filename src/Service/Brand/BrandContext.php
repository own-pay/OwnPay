<?php
declare(strict_types=1);

namespace OwnPay\Service\Brand;

use OwnPay\Core\Database;
use OwnPay\Http\Request;

/**
 * Brand context — central resolver for "which brand is active?"
 *
 * Resolution order:
 *   1. Request attribute (from DomainMiddleware or BearerAuthMiddleware)
 *   2. Session (active_brand_id, set by brand switcher)
 *   3. Default brand (first merchant in DB)
 */
final class BrandContext
{
    private Database $db;
    private ?int $activeBrandId = null;
    private ?array $brandsCache = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Resolve active brand from request, session, or default.
     */
    public function resolveFromRequest(Request $req): ?int
    {
        // 1. Request attribute (DomainMiddleware / BearerAuthMiddleware)
        $fromReq = $req->getAttribute('merchant_id');
        if ($fromReq !== null) {
            $this->activeBrandId = (int) $fromReq;
            return $this->activeBrandId;
        }

        // 2. Session
        // BUG-27 FIX: Guard $_SESSION access — not available in CLI/API contexts.
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

        // 3. Default (first brand)
        $first = $this->db->fetchOne("SELECT id FROM op_merchants ORDER BY id ASC LIMIT 1");
        if ($first) {
            $this->activeBrandId = (int) $first['id'];
        }

        return $this->activeBrandId;
    }

    public function getActiveBrandId(): ?int
    {
        if ($this->activeBrandId !== null) {
            return $this->activeBrandId;
        }

        // Fall back to session
        // BUG-27 FIX: Guard $_SESSION access for CLI/API safety.
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

    public function setActiveBrandId(int $id): void
    {
        $this->activeBrandId = $id;
        // BUG-27 FIX: Only write to session if active.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['active_brand_id'] = $id;
        }
    }

    /**
     * Is user viewing all brands (global view)?
     */
    public function isGlobalView(): bool
    {
        $mode = 'single';
        if (session_status() === PHP_SESSION_ACTIVE) {
            $mode = $_SESSION['brand_view_mode'] ?? 'single';
        }
        return ($this->getActiveBrandId() === null) || ($mode === 'global');
    }

    public function setGlobalView(bool $global): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['brand_view_mode'] = $global ? 'global' : 'single';
        }
    }

    /**
     * Get all brands for dropdown.
     * @return array<int, array{id: int, name: string, slug: string, logo_path: ?string, status: string}>
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
     * Get current brand details.
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
