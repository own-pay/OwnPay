<?php

declare(strict_types=1);

namespace AnirbanPay\Http;

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
    ) {}

    public function hasPermission(string $module, string $action): bool
    {
        return $this->permissions['resources'][$module][$action] ?? false;
    }

    public function canAccessPage(string $page): bool
    {
        return $this->permissions['pages'][$page] ?? false;
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
