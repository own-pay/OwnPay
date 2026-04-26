<?php

declare(strict_types=1);

namespace OwnPay\Http;

/**
 * Immutable request context — replaces global variables in controllers.
 *
 * Created by SessionMiddleware, passed to Controller::handle().
 */
final class RequestContext
{
    public function __construct(
        public readonly string $dbPrefix,
        public readonly array $user,
        public readonly array $brand,
        public readonly array $permissions,
        public readonly string $csrfToken,
        public readonly bool $isLoggedIn,
        public readonly string $role,
        // Site config (populated by SessionMiddleware)
        public readonly string $siteUrl = '',
        public readonly string $pathAdmin = '',
        public readonly string $pathPayment = '',
        public readonly string $pathInvoice = '',
        public readonly string $pathPaymentLink = '',
        public readonly string $currencyCode = '',
        public readonly string $currencySymbol = '',
        public readonly float $currencyRate = 1.0,
        public readonly bool $demoMode = false,
        // Legacy wrapper arrays for controllers still expecting nested structure
        public readonly array $userResponse = [],
        public readonly array $brandResponse = [],
        public readonly array $permissionResponse = [],
        public readonly array $cookieResponse = [],
    ) {}

    public function hasPermission(string $module, string $action): bool
    {
        // Admin role bypasses permission checks (matches PermissionService logic)
        if ($this->role === 'admin') {
            return true;
        }
        return $this->permissions['resources'][$module][$action] ?? false;
    }

    public function canAccessPage(string $page): bool
    {
        if ($this->role === 'admin') {
            return true;
        }
        return !empty($this->permissions['pages'][$page]);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function userId(): string
    {
        return $this->user['id'] ?? '';
    }

    public function brandId(): string
    {
        return $this->brand['brand_id'] ?? '';
    }
}
