<?php
declare(strict_types=1);

/**
 * Application configuration constants.
 *
 * All runtime-specific values (DB, keys, secrets) live in .env.
 * This file holds structural/behavioral constants only.
 */

return [
    // ─── Identity ──────────────────────────────────────────────
    'name'    => 'Own Pay',
    'version' => '0.1.0',
    'codename'=> 'Genesis',

    // ─── Environment ───────────────────────────────────────────
    // Overridden by APP_ENV in .env: 'production', 'staging', 'development'
    'env'   => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),

    // ─── Timezone ──────────────────────────────────────────────
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Dhaka',

    // ─── Paths (relative to project root) ──────────────────────
    'paths' => [
        'root'      => dirname(__DIR__),
        'public'    => dirname(__DIR__) . '/public',
        'config'    => __DIR__,
        'src'       => dirname(__DIR__) . '/src',
        'templates' => dirname(__DIR__) . '/templates',
        'storage'   => dirname(__DIR__) . '/storage',
        'modules'   => dirname(__DIR__) . '/modules',
        'database'  => dirname(__DIR__) . '/database',
        'logs'      => dirname(__DIR__) . '/storage/logs',
        'cache'     => dirname(__DIR__) . '/storage/cache',
        'queue'     => dirname(__DIR__) . '/storage/queue',
        'sessions'  => dirname(__DIR__) . '/storage/sessions',
        'backups'   => dirname(__DIR__) . '/storage/backups',
        'temp'      => dirname(__DIR__) . '/storage/temp',
        'plugins'   => dirname(__DIR__) . '/storage/plugins',
    ],

    // ─── URL Customization ─────────────────────────────────────
    // Configurable via admin panel, stored in op_system_settings.
    // These are fallback defaults if DB not yet available (e.g., installer).
    'default_login_path' => '/login',
    'default_admin_path' => '/admin',

    // ─── Session ───────────────────────────────────────────────
    'session' => [
        'name'     => 'op_session',
        'lifetime' => 7200,     // 2 hours in seconds
        'secure'   => true,     // HTTPS-only cookie
        'httponly'  => true,
        'samesite'  => 'Lax',
    ],

    // ─── Rate Limiting Defaults ────────────────────────────────
    'rate_limit' => [
        'api'   => ['max' => 60,  'window' => 60],   // 60 req/min per key
        'login' => ['max' => 5,   'window' => 300],   // 5 attempts per 5 min
        'global'=> ['max' => 120, 'window' => 60],    // 120 req/min per IP
    ],

    // ─── Checkout ──────────────────────────────────────────────
    'checkout' => [
        'timer_seconds' => 600,  // 10 minutes default
    ],

    // ─── Update Server ─────────────────────────────────────────
    'update' => [
        'check_url'    => 'https://update.ownpay.org/manifest.json',
        'night_window' => ['start' => '02:00', 'end' => '04:00'],
        'idle_minutes' => 15,
        'max_retries'  => 3,
    ],

    // ─── Security ──────────────────────────────────────────────
    'security' => [
        'password_algo' => PASSWORD_ARGON2ID,
        'encryption'    => 'aes-256-gcm',
        'csrf_rotation' => true,    // Rotate token on every request
    ],

    // ─── Cache/Queue Driver ────────────────────────────────────
    // 'file' or 'redis'. Auto-detected if not set.
    'cache_driver' => getenv('CACHE_DRIVER') ?: 'file',
    'queue_driver' => getenv('QUEUE_DRIVER') ?: 'file',
];
