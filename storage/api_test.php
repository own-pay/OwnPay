<?php
// API endpoint curl tests
// Uses the live ownpay.test domain

$base = 'https://ownpay.test';
$testKey = 'op_test_8d057d1dd6d3e41103f4445020fd2693'; // from seeder

$results = [];

function curlGet(string $url, string $key = ''): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($key) $headers[] = "Authorization: Bearer $key";
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

function curlPost(string $url, array $data, string $key = ''): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($key) $headers[] = "Authorization: Bearer $key";
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

$tests = [
    ['method' => 'GET',  'name' => 'Health Check',      'url' => "$base/api/v1/health",         'auth' => false],
    ['method' => 'GET',  'name' => 'Transactions List',  'url' => "$base/api/v1/transactions",   'auth' => true],
    ['method' => 'GET',  'name' => 'Customers List',     'url' => "$base/api/v1/customers",      'auth' => true],
    ['method' => 'GET',  'name' => 'API Keys List',      'url' => "$base/api/v1/api-keys",       'auth' => true],
    ['method' => 'POST', 'name' => 'Payment Initiate',   'url' => "$base/api/v1/payments/initiate", 'auth' => true, 'data' => [
        'amount'       => 1000,
        'currency'     => 'BDT',
        'customer_ref' => 'test-cust-001',
        'description'  => 'Test Payment',
        'metadata'     => ['order_id' => 'ORD-TEST-001'],
    ]],
    ['method' => 'POST', 'name' => 'Create Customer',    'url' => "$base/api/v1/customers",      'auth' => true, 'data' => [
        'name'  => 'API Test Customer',
        'email' => 'apitest@example.com',
        'phone' => '+8801800000001',
    ]],
    ['method' => 'GET',  'name' => 'Landing Page',       'url' => "$base/",                      'auth' => false],
    ['method' => 'GET',  'name' => 'Login Page',         'url' => "$base/login",                 'auth' => false],
    ['method' => 'GET',  'name' => '404 Check',          'url' => "$base/api/v1/nonexistent",    'auth' => true],
];

echo "OwnPay API Test Results\n";
echo str_repeat('=', 60) . "\n";
echo "Base URL : $base\n";
echo "Test Key : " . substr($testKey, 0, 15) . "...\n\n";

foreach ($tests as $test) {
    $key = $test['auth'] ? $testKey : '';
    if ($test['method'] === 'POST') {
        $res = curlPost($test['url'], $test['data'] ?? [], $key);
    } else {
        $res = curlGet($test['url'], $key);
    }

    $status = ($res['code'] >= 200 && $res['code'] < 500) ? '✓' : '✗';
    $body   = substr($res['body'] ?: '', 0, 120);
    $jsonOk = json_decode($res['body'] ?? '', true) !== null ? 'JSON' : 'HTML';

    echo "$status [{$res['code']}] {$test['method']} {$test['name']}\n";
    if ($res['error']) echo "  ERROR: {$res['error']}\n";
    echo "  Response: $jsonOk | $body\n\n";

    $results[] = [
        'test'   => $test['name'],
        'method' => $test['method'],
        'url'    => $test['url'],
        'code'   => $res['code'],
        'ok'     => $res['code'] >= 200 && $res['code'] < 500,
        'json'   => $jsonOk === 'JSON',
        'error'  => $res['error'],
    ];
}

$passed = count(array_filter($results, fn($r) => $r['ok']));
echo str_repeat('=', 60) . "\n";
echo "TOTAL: $passed/" . count($results) . " passed\n";

// Save results JSON
file_put_contents(__DIR__ . '/api_test_results.json', json_encode($results, JSON_PRETTY_PRINT));
echo "Results saved to storage/api_test_results.json\n";
