<?php
declare(strict_types=1);

try {
    // Load config/env
    require_once dirname(__DIR__) . '/config/config.php';

    // Connect to PDO
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $sql = file_get_contents(__DIR__ . '/migrations.sql');
    $pdo->exec($sql);
    echo "Database migrations executed successfully!\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration Error: " . $e->getMessage() . "\n");
    exit(1);
}
