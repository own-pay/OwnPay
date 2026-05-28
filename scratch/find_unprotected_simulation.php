<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$unprotected = [];

foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);

    if (str_contains($content, 'SIM_') || str_contains($content, 'SIM_TXN_')) {
        // Let's check if the file checks for live mode.
        // Usually it should look like:
        // if ($mode === 'live') { throw ... }
        // or
        // if ($mode === 'live') { return ['success' => false]; }
        // Let's check how many times 'live' is used in the file.
        $hasLiveCheck = str_contains($content, 'live');
        
        // Let's check verify function specifically
        if (preg_match('/function verify\((.*?)\)\s*\{(.*?)\}/s', $content, $matches)) {
            $verifyBody = $matches[2];
            if (str_contains($verifyBody, 'SIM_') || str_contains($verifyBody, 'SIM_TXN_')) {
                if (!str_contains($verifyBody, 'live')) {
                    $unprotected[$slug][] = 'verify';
                }
            }
        }
        
        // Let's check initiate function specifically
        if (preg_match('/function initiate\((.*?)\)\s*\{(.*?)\}/s', $content, $matches)) {
            $initiateBody = $matches[2];
            if (str_contains($initiateBody, 'SIM_') || str_contains($initiateBody, 'SIM_TXN_')) {
                if (!str_contains($initiateBody, 'live')) {
                    $unprotected[$slug][] = 'initiate';
                }
            }
        }
    }
}

echo json_encode($unprotected, JSON_PRETTY_PRINT) . "\n";
