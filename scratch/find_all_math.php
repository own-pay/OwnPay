<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';

foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    $tokens = token_get_all($content);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_string($token) && ($token === '*' || $token === '/')) {
            // Find the surrounding context
            $left = '';
            for ($k = max(0, $i - 10); $k < $i; $k++) {
                $t = $tokens[$k];
                $left .= is_array($t) ? $t[1] : $t;
            }
            $right = '';
            for ($k = $i + 1; $k < min($count, $i + 10); $k++) {
                $t = $tokens[$k];
                $right .= is_array($t) ? $t[1] : $t;
            }
            $line = 0;
            // find line number
            for ($k = $i; $k >= 0; $k--) {
                if (is_array($tokens[$k])) {
                    $line = $tokens[$k][2];
                    break;
                }
            }
            echo "[{$slug}] line {$line}: ... " . trim($left) . " ******* [{$token}] ******* " . trim($right) . "\n";
        }
    }
}
