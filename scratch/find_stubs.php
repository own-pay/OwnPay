<?php
declare(strict_types=1);

$dirPath = __DIR__ . '/../modules/gateways';
$stubs = [];
foreach (glob($dirPath . '/*') as $dir) {
    if (!is_dir($dir)) continue;
    $slug = basename($dir);
    $phpFiles = glob($dir . '/*.php');
    if (empty($phpFiles)) {
        echo "No PHP files in $slug\n";
        continue;
    }
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    // Check if it contains "Simulation UAT" or has similar simulation redirect logic
    $hasSim = str_contains($content, 'Simulation UAT') || str_contains($content, 'SIM_') || str_contains($content, 'Simulation Mode');
    
    // Check if it actually initiates a cURL request
    $hasCurl = str_contains($content, 'curl_init');
    
    echo "$slug: has_sim=" . ($hasSim ? 'YES' : 'NO') . ", has_curl=" . ($hasCurl ? 'YES' : 'NO') . " (" . filesize($file) . " bytes)\n";
}
