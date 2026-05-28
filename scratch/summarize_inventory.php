<?php
declare(strict_types=1);

$inventory = json_decode(file_get_contents(__DIR__ . '/gateways_inventory.json'), true);
echo "=== Gateways Inventory Summary ===\n";
echo "Total Gateways: " . count($inventory) . "\n\n";

$categories = [
    'standard' => [],
    'no_endpoints' => [],
    'openssl' => [],
    'hmac' => []
];

foreach ($inventory as $slug => $data) {
    if (empty($data['endpoints'])) {
        $categories['no_endpoints'][] = $slug;
    } elseif ($data['webhook_verification'] === 'openssl_verify') {
        $categories['openssl'][] = $slug;
    } elseif ($data['webhook_verification'] === 'hash_hmac' || $data['webhook_verification'] === 'hash_equals') {
        $categories['hmac'][] = $slug;
    } else {
        $categories['standard'][] = $slug;
    }
}

foreach ($categories as $cat => $list) {
    echo "- $cat (" . count($list) . "): " . implode(', ', array_slice($list, 0, 15)) . (count($list) > 15 ? '...' : '') . "\n";
}
