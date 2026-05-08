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
    'host'     => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
    'database' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'ownpay',
    'username' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root',
    'password' => $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '',
    'charset'  => $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4',
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
