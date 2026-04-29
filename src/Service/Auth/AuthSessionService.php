<?php
declare(strict_types=1);

namespace OwnPay\Service\Auth;

/**
 * Modern replacement for procedural getCookie(), setsCookie(), logoutCookie().
 *
 * Provides static methods for secure cookie and session management.
 * All cookies are set with HttpOnly, SameSite=Lax, and Secure (on HTTPS).
 */
final class AuthSessionService
{
    /**
     * Get the value of a cookie.
     *
     * Replaces: getCookie()
     *
     * @param string $name Cookie name
     * @return string|null Cookie value or null if not set
     */
    public static function getCookie(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }

    /**
     * Set a secure cookie.
     *
     * Replaces: setsCookie()
     *
     * @param string $name  Cookie name
     * @param string $value Cookie value
     * @param int    $days  Expiry in days (default: 365)
     */
    public static function setCookie(string $name, string $value, int $days = 365): void
    {
        $expiryTime = time() + ($days * 24 * 60 * 60);

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 0) == 443;

        setcookie($name, $value, [
            'expires'  => $expiryTime,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Destroy all cookies and the session.
     *
     * Replaces: logoutCookie()
     */
    public static function destroySession(): void
    {
        // Expire all cookies
        foreach ($_COOKIE as $name => $value) {
            setcookie($name, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => true,
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);
        }

        // Clear and destroy session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_unset();
        session_destroy();
    }
}
