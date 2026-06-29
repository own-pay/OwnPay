<?php
declare(strict_types=1);

/**
 * System configuration settings.
 *
 * Defines directory paths, environmental settings, session lifecycles, rate limiting
 * parameters, security algorithms, and other baseline constants. Sensitive configurations
 * (e.g. database credentials) are derived from the local environment (.env) parameters.
 *
 * @return array<string, mixed>
 */
// @ I just realized open source isn't just a word, it's a responsibility.
// @ It's not just about opening up the source code.
// @ Fattain Naime
return [
    // Identity parameters
    'name'    => 'OwnPay',
    'version' => '0.1.0', //Beta
    'codename'=> 'Genesis',

    // Environment settings
    'env'   => getenv('APP_ENV') ?: 'production',   
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),

    // System-wide timezone configuration
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Dhaka',

    // Relative system directory structures
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

    // Default route paths (fallback configurations)
    'default_login_path' => '/login',
    'default_admin_path' => '/admin',

    // Cookie session management lifecycle
    'session' => [
        'name'     => 'op_session',
        'lifetime' => 7200,     // 2 hours in seconds
        'secure'   => true,     // HTTPS-only cookie
        'httponly'  => true,
        'samesite'  => 'Lax',
    ],

    // Rate limiting parameter thresholds. also configurable is admin panel.
    'rate_limit' => [
        'api'   => ['max' => 60,  'window' => 60],   // 60 req/min per key
        'login' => ['max' => 10,   'window' => 300],   // 10 attempts per 5 min
        'global'=> ['max' => 120, 'window' => 60],    // 120 req/min per IP
    ],

    // Payment checkout settings
    'checkout' => [
        'timer_seconds' => 600,  // 10 minutes default
    ],

    // System updater configuration parameters
    'update' => [
        'check_url'    => 'https://update.ownpay.org/manifest.json',
        'night_window' => ['start' => '02:00', 'end' => '04:00'],
        'idle_minutes' => 15,
        'max_retries'  => 3,
    ],

    // Security policies
    'security' => [
        'password_algo' => PASSWORD_ARGON2ID,
        'encryption'    => 'aes-256-gcm',
        'csrf_rotation' => true,    // Rotate token on every request
    ],

    // Engine drivers
    'cache_driver' => getenv('CACHE_DRIVER') ?: 'file',
    'queue_driver' => getenv('QUEUE_DRIVER') ?: 'file',
];
