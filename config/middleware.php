<?php
declare(strict_types=1);

/**
 * Middleware pipeline configuration.
 *
 * Defines ordered middleware stacks for different request contexts.
 * Each entry is a fully-qualified class name.
 * Middleware are executed top-to-bottom on request, bottom-to-top on response.
 */

return [
    // ─── Global: applied to ALL requests ───────────────────────
    'global' => [
        \OwnPay\Middleware\SecurityHeadersMiddleware::class,
        \OwnPay\Middleware\MaintenanceMiddleware::class,
        \OwnPay\Middleware\DomainMiddleware::class,
    ],

    // ─── Web: admin panel + checkout + pages ───────────────────
    'web' => [
        \OwnPay\Middleware\SessionMiddleware::class,
        \OwnPay\Middleware\CsrfMiddleware::class,
    ],

    // ─── Admin: web + auth + permissions ───────────────────────
    'admin' => [
        \OwnPay\Middleware\SessionMiddleware::class,
        \OwnPay\Middleware\CsrfMiddleware::class,
        \OwnPay\Middleware\RateLimiterMiddleware::class,
        \OwnPay\Middleware\TwoFactorMiddleware::class,
        \OwnPay\Middleware\PermissionMiddleware::class,
    ],

    // ─── API: bearer auth + rate limit ─────────────────────────
    'api' => [
        \OwnPay\Middleware\CorsMiddleware::class,
        \OwnPay\Middleware\RateLimiterMiddleware::class,
        \OwnPay\Middleware\BearerAuthMiddleware::class,
    ],

    // ─── API Public: no auth (health checks, public endpoints) ──
    'api-public' => [
        \OwnPay\Middleware\CorsMiddleware::class,
        \OwnPay\Middleware\RateLimiterMiddleware::class,
    ],

    // ─── Mobile API: JWT + device auth ─────────────────────────
    'mobile' => [
        \OwnPay\Middleware\CorsMiddleware::class,
        \OwnPay\Middleware\RateLimiterMiddleware::class,
        \OwnPay\Middleware\JwtAuthMiddleware::class,
    ],

    // ─── Webhook: signature verification ───────────────────────
    'webhook' => [
        \OwnPay\Middleware\IpAllowlistMiddleware::class,
        \OwnPay\Middleware\RequestSignatureMiddleware::class,
    ],

    // ─── Checkout: minimal (session + csrf) ────────────────────
    'checkout' => [
        \OwnPay\Middleware\SessionMiddleware::class,
        \OwnPay\Middleware\CsrfMiddleware::class,
        \OwnPay\Middleware\RateLimiterMiddleware::class,
    ],

    // ─── Install: minimal — no DB available yet ───────────────
    'install' => [
        \OwnPay\Middleware\SecurityHeadersMiddleware::class,
    ],
];
