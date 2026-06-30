<?php
declare(strict_types=1);

namespace OwnPay\Service\Admin;

/**
 * Injectable session wrapper for admin controllers.
 *
 * Abstracting raw $_SESSION interactions allows the session backend implementation
 * to remain agnostic (supporting alternative providers like Redis or database stores)
 * and enhances testability.
 */
final class AdminSession
{
    /**
     * Resolves the authenticated admin user identifier.
     *
     * @return int|null The user ID if authenticated, or null.
     */
    public function userId(): ?int
    {
        $id = $_SESSION['auth_user_id'] ?? null;
        return is_scalar($id) ? (int) $id : null;
    }

    /**
     * Resolves the authenticated user name.
     *
     * @return string The authenticated user name.
     */
    public function userName(): string
    {
        $name = $_SESSION['auth_name'] ?? 'Admin';
        return is_scalar($name) ? (string) $name : 'Admin';
    }

    /**
     * Resolves the authenticated user email address.
     *
     * @return string The email address.
     */
    public function userEmail(): string
    {
        $email = $_SESSION['auth_email'] ?? '';
        return is_scalar($email) ? (string) $email : '';
    }

    /**
     * Evaluates whether the authenticated user has superadmin system authority.
     *
     * @return bool True if the user is a superadmin.
     */
    public function isSuperadmin(): bool
    {
        return (bool) ($_SESSION['is_superadmin'] ?? false);
    }

    /**
     * Resolves the authenticated user's home brand/merchant identifier.
     *
     * @return int|null The merchant ID or null.
     */
    public function merchantId(): ?int
    {
        $id = $_SESSION['auth_merchant_id'] ?? null;
        return is_scalar($id) ? (int) $id : null;
    }

    /**
     * Resolves the active brand/merchant identifier.
     *
     * Fallback resolution sequence checks active brand selection before home brand context.
     *
     * @return int|null The active merchant ID context or null.
     */
    public function activeBrandId(): ?int
    {
        $id = $_SESSION['active_brand_id'] ?? $_SESSION['auth_merchant_id'] ?? null;
        return is_scalar($id) ? (int) $id : null;
    }

    /**
     * Sets a session flash message to be consumed on the subsequent page rendering.
     *
     * @param string $type The alert type classification (e.g., 'success', 'error').
     * @param string $message The flash message string.
     * @return void
     */
    public function flash(string $type, string $message): void
    {
        $_SESSION["flash_{$type}"] = $message;
    }

    /**
     * Sets a success flash message.
     *
     * @param string $message The flash message string.
     * @return void
     */
    public function flashSuccess(string $message): void
    {
        $this->flash('success', $message);
    }

    /**
     * Sets an error flash message.
     *
     * @param string $message The flash message string.
     * @return void
     */
    public function flashError(string $message): void
    {
        $this->flash('error', $message);
    }

    /**
     * Consumes and clears pending session flash messages.
     *
     * @return array{success: string|null, error: string|null} The flash messages array.
     */
    public function consumeFlash(): array
    {
        $successVal = $_SESSION['flash_success'] ?? null;
        $errorVal = $_SESSION['flash_error'] ?? null;
        $flash = [
            'success' => is_scalar($successVal) ? (string) $successVal : null,
            'error'   => is_scalar($errorVal) ? (string) $errorVal : null,
        ];
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return $flash;
    }

    /**
     * Resolves the user identity profile mapping for template variables injection.
     *
     * @return array{id: int|null, name: string, email: string, two_fa_enabled: bool} Current user data.
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
     * Assigns a custom key-value parameter in the active session wrapper.
     *
     * @param string $key Unique storage key.
     * @param mixed $value The object or data to store.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Retrieves an arbitrary variable from the session storage wrapper.
     *
     * @param string $key Unique storage key.
     * @param mixed $default The fallback value to return if the key is not defined.
     * @return mixed The session value, or the fallback value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Updates authentication profiles stored in sessions.
     *
     * @param string $name The updated user profile name.
     * @param string $email The updated user profile email address.
     * @return void
     */
    public function updateProfile(string $name, string $email): void
    {
        $_SESSION['auth_name'] = $name;
        $_SESSION['auth_email'] = $email;
    }
}
