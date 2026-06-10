<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/Database.php';

echo "=== OwnPay Standalone Landing Page Verification ===\n";

// 1. Check database connection
try {
    $db = Database::getConnection();
    echo "[PASS] Database connection verified successfully.\n";
} catch (Throwable $e) {
    echo "[FAIL] Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Perform waitlist subscription test via curl request to localhost:8000
$testEmail = 'test_dev_' . bin2hex(random_bytes(4)) . '@ownpay.org';
echo "Testing waitlist signup for: {$testEmail}\n";

$payload = json_encode(['email' => $testEmail]);

$ch = curl_init('http://127.0.0.1:8000/waitlist');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo "Response: {$response}\n";

if ($httpCode !== 200) {
    echo "[FAIL] Waitlist endpoint returned status code: {$httpCode}\n";
    exit(1);
}

$data = json_decode((string)$response, true);
if (!isset($data['success']) || !$data['success']) {
    echo "[FAIL] Waitlist signup failed: " . ($data['message'] ?? 'Unknown error') . "\n";
    exit(1);
}
echo "[PASS] Waitlist signup API responded with success.\n";

// 3. Verify database record
$stmt = $db->prepare("SELECT * FROM `op_org_subscribers` WHERE `email` = ?");
$stmt->execute([$testEmail]);
$record = $stmt->fetch();

if (!$record) {
    echo "[FAIL] Email record not found in op_org_subscribers table.\n";
    exit(1);
}
echo "[PASS] Database record successfully verified: ID {$record['id']}.\n";

// 4. Test home page loading content
$ch = curl_init('http://127.0.0.1:8000/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);
$html = curl_exec($ch);
curl_close($ch);

if (str_contains((string)$html, 'Self-Hosted Payments. Zero Platform Tax.')) {
    echo "[PASS] Home page loaded and contains the correct hero headline.\n";
} else {
    echo "[FAIL] Home page content verification failed.\n";
    exit(1);
}

echo "=== Verification Successful! ===\n";
exit(0);
