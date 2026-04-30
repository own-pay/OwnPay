<?php
declare(strict_types=1);

/**
 * Database configuration — sourced entirely from .env.
 *
 * Used by Container to build the PDO singleton.
 * No op-config.php dependency.
 */

return [
    'driver'   => 'mysql',
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_DATABASE') ?: 'ownpay',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset'  => getenv('DB_CHARSET') ?: 'utf8mb4',
    'collation'=> getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci',

    // PDO options — strict mode, emulated prepares off, exceptions on
    'options' => [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES   => false,
        \PDO::ATTR_STRINGIFY_FETCHES  => false,
        \PDO::MYSQL_ATTR_FOUND_ROWS   => true,
    ],
];
