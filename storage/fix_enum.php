<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$db = new PDO(
    'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Check current ENUM
$r = $db->query("SHOW COLUMNS FROM op_transactions WHERE Field = 'status'");
$col = $r->fetch(PDO::FETCH_ASSOC);
echo "Current: " . $col['Type'] . "\n";

// ALTER to add missing statuses
$db->exec("ALTER TABLE op_transactions MODIFY COLUMN status ENUM('pending','created','processing','completed','failed','cancelled','refunded','disputed','awaiting_verification','pending_review') NOT NULL DEFAULT 'pending'");

// Verify
$r2 = $db->query("SHOW COLUMNS FROM op_transactions WHERE Field = 'status'");
$col2 = $r2->fetch(PDO::FETCH_ASSOC);
echo "Updated: " . $col2['Type'] . "\n";
echo "Done.\n";
