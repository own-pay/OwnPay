<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dirPath = __DIR__ . '/../modules/gateways';
$directories = scandir($dirPath);

$results = [];

foreach ($directories as $dir) {
    if ($dir === '.' || $dir === '..') {
        continue;
    }
    
    $path = $dirPath . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }
    
    $phpFiles = glob($path . '/*.php');
    if (empty($phpFiles)) {
        $results[$dir] = [
            'status' => 'missing_php',
            'details' => 'No PHP files found'
        ];
        continue;
    }
    
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    if ($content === false) {
        $results[$dir] = [
            'status' => 'error_reading',
            'details' => 'Failed to read file'
        ];
        continue;
    }
    
    $hasCurl = str_contains($content, 'curl_init');
    $hasSim = str_contains($content, 'Simulation UAT') || str_contains($content, 'Simulation Mode') || str_contains($content, 'Simulate') || str_contains($content, 'SIM_');
    $hasPendingCheck = str_contains($content, 'pending') && str_contains($content, 'complete');
    
    // Check if it's a pure placeholder (e.g. returns a mock redirect immediately without curl or with dummy credentials)
    // A plugin is a placeholder if it doesn't do curl/api calls but immediately returns PAID/SIM_ URL on initiate.
    // Wait, let's look at the initiate function.
    $hasRealApiCall = str_contains($content, 'curl_exec') || str_contains($content, 'file_get_contents');
    
    // Check for comments indicating demo/mock/todo/placeholder/stub
    $hasTodo = str_contains($content, 'TODO') || str_contains($content, 'FIXME') || str_contains($content, 'placeholder') || str_contains($content, 'dummy') || str_contains($content, 'stub');
    
    // Let's analyze if the initiate method actually uses CURLOPT_URL or equivalent to send request
    $status = 'fully_functional';
    $reasons = [];
    
    if (!$hasRealApiCall) {
        $status = 'stub';
        $reasons[] = 'No outbound HTTP request (curl_exec/file_get_contents) found';
    }
    
    if ($hasTodo) {
        $reasons[] = 'Contains TODO/placeholder comments';
    }
    
    $results[$dir] = [
        'status' => $status,
        'has_curl' => $hasCurl,
        'has_sim' => $hasSim,
        'has_todo' => $hasTodo,
        'reasons' => $reasons,
        'file_size' => filesize($file),
    ];
}

file_put_contents(__DIR__ . '/audit_report.json', json_encode($results, JSON_PRETTY_PRINT));
echo "Successfully wrote audit_report.json\n";
