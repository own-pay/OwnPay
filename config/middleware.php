<?php
declare(strict_types=1);

/**
 * OwnPay Middleware Pipeline Configuration.
 *
 * This file defines the ordered middleware stacks executed for different HTTP request contexts.
 * Each middleware group corresponds to a routing channel (e.g., global, web, admin, api, mobile).
 * The pipeline runs sequentially top-to-bottom for processing incoming requests, and in reverse
 * order (bottom-to-top) for modifying outbound HTTP responses.
 *
 * @package OwnPay\Config
 * @see \OwnPay\Middleware\DomainMiddleware
 * @see \OwnPay\Middleware\PermissionMiddleware
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

    // ─── API: bearer auth + rate limit + idempotency ─────────────
    'api' => [
        \OwnPay\Middleware\CorsMiddleware::class,
        \OwnPay\Middleware\RateLimiterMiddleware::class,
        \OwnPay\Middleware\BearerAuthMiddleware::class,
        \OwnPay\Middleware\IdempotencyMiddleware::class,
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
