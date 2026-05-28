<?php
declare(strict_types=1);

$dirPath = __DIR__ . '/../modules/gateways';
$results = [];

foreach (glob($dirPath . '/*') as $dir) {
    if (!is_dir($dir)) continue;
    $slug = basename($dir);
    $phpFiles = glob($dir . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);

    $hasSim = str_contains($content, 'SIM_') || str_contains($content, 'MOCK_') || str_contains($content, 'Simulation') || str_contains($content, 'sandbox');
    $checksLiveMode = str_contains($content, 'live');

    // Parse verify method if possible or check for the combination of SIM_ and live mode prevention.
    $hasVerify = str_contains($content, 'function verify(');

    // Let's search if they check mode in verify()
    $verifyBlock = '';
    if ($hasVerify) {
        $pos = strpos($content, 'function verify(');
        $verifyBlock = substr($content, $pos, 800); // look at next 800 chars of verify function
    }

    $bypassPossible = false;
    if ($hasSim && $hasVerify) {
        // If it checks SIM_ inside verify, but doesn't check if the mode is live to block it, it might be a bypass.
        if (str_contains($verifyBlock, 'SIM_') || str_contains($verifyBlock, 'MOCK_')) {
            // Check if 'live' is also checked inside the verify block.
            if (!str_contains($verifyBlock, 'live')) {
                $bypassPossible = true;
            }
        }
    }

    if ($bypassPossible) {
        $results[$slug] = [
            'file' => $file,
            'bypass_possible' => $bypassPossible,
            'verify_snippet' => $verifyBlock
        ];
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
