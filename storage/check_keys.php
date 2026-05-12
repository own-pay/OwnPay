<?php
// Grab a real API key from DB and test with it
$pdo = new PDO('mysql:host=localhost;dbname=ownpay;charset=utf8mb4', 'root', 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Look for any active key
$key = $pdo->query("SELECT key_prefix, key_hash FROM op_api_keys WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$key) { die("No active API keys in DB. Create one via admin panel first.\n"); }

echo "Found key prefix: {$key['key_prefix']}\n";
echo "Hash: {$key['key_hash']}\n";
echo "NOTE: Cannot reconstruct full key from hash — must generate fresh key via admin UI.\n";
echo "\nTest with curl:\n";
echo "curl -H 'Authorization: Bearer YOUR_KEY' https://ownpay.test/api/v1/transactions\n";
