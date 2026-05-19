<?php
declare(strict_types=1);

namespace OwnPay\Service\Admin;

/**
 * AdminSession — injectable session wrapper for admin controllers.
 *
 * Replaces all raw $_SESSION access. Makes controllers:
 * - Testable (mock this service)
 * - Session-backend agnostic (swap to Redis/DB later)
 * - Type-safe (int userId, not mixed)
 */
final class AdminSession
{
    /**
     * Get authenticated user ID.
     */
    public function userId(): ?int
    {
        $id = $_SESSION['auth_user_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /**
     * Get authenticated user name.
     */
    public function userName(): string
    {
        return (string) ($_SESSION['auth_name'] ?? 'Admin');
    }

    /**
     * Get authenticated user email.
     */
    public function userEmail(): string
    {
        return (string) ($_SESSION['auth_email'] ?? '');
    }

    /**
     * Check superadmin flag.
     */
    public function isSuperadmin(): bool
    {
        return (bool) ($_SESSION['is_superadmin'] ?? false);
    }

    /**
     * Get authenticated merchant ID (home brand).
     */
    public function merchantId(): ?int
    {
        $id = $_SESSION['auth_merchant_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /**
     * Get active brand ID (selected via brand switcher).
     */
    public function activeBrandId(): ?int
    {
        $id = $_SESSION['active_brand_id'] ?? $_SESSION['auth_merchant_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /**
     * Set a flash message (consumed on next page render).
     */
    public function flash(string $type, string $message): void
    {
        $_SESSION["flash_{$type}"] = $message;
    }

    /**
     * Convenience: flash success message.
     */
    public function flashSuccess(string $message): void
    {
        $this->flash('success', $message);
    }

    /**
     * Convenience: flash error message.
     */
    public function flashError(string $message): void
    {
        $this->flash('error', $message);
    }

    /**
     * Consume flash messages (returns and clears).
     * @return array{success: ?string, error: ?string}
     */
    public function consumeFlash(): array
    {
        $flash = [
            'success' => $_SESSION['flash_success'] ?? null,
            'error'   => $_SESSION['flash_error'] ?? null,
        ];
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return $flash;
    }

    /**
     * Get the current user context array for templates.
     * @return array{id: ?int, name: string, email: string}
     */
    public function currentUser(): array
    {
        return [
            'id'             => $this->userId(),
            'name'           => $this->userName(),
            'email'          => $this->userEmail(),
            'two_fa_enabled' => (bool) ($_SESSION['two_fa_enabled'] ?? false),
        ];
    }

    /**
     * Set arbitrary session value.
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Get arbitrary session value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Update auth session data after profile changes.
     */
    public function updateProfile(string $name, string $email): void
    {
        $_SESSION['auth_name'] = $name;
        $_SESSION['auth_email'] = $email;
    }
}
