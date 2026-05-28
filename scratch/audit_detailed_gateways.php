<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$gateways = [];

foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    // Check strict types
    $strictTypes = str_contains($content, 'declare(strict_types=1);');
    
    // Check namespace
    $namespace = 'unknown';
    if (preg_match('/namespace\s+([a-zA-Z0-9_\\\\]+);/', $content, $matches)) {
        $namespace = $matches[1];
    }
    
    // Check if it implements standard interface
    $implementsInterface = str_contains($content, 'implements PluginInterface') || str_contains($content, 'implements GatewayAdapterInterface');
    
    // Extract endpoints
    $endpoints = [];
    if (preg_match_all('/https?:\/\/[a-zA-Z0-9_\-\.\/\?=&%#]+/i', $content, $matches)) {
        $endpoints = array_unique($matches[0]);
    }
    
    // Check if it does BCMath scale or precision multiplication
    $hasBCMath = str_contains($content, 'bcmul') || str_contains($content, 'bcdiv') || str_contains($content, 'bcadd') || str_contains($content, 'bcsub');
    
    // Check signature algorithm
    $verifyMethod = 'unknown';
    if (str_contains($content, 'function verify(')) {
        $pos = strpos($content, 'function verify(');
        $verifyBlock = substr($content, $pos, 800);
        if (str_contains($verifyBlock, 'hash_hmac')) {
            $verifyMethod = 'hash_hmac';
        } elseif (str_contains($verifyBlock, 'openssl_verify')) {
            $verifyMethod = 'openssl_verify';
        } elseif (str_contains($verifyBlock, 'md5')) {
            $verifyMethod = 'md5';
        } elseif (str_contains($verifyBlock, 'sha256')) {
            $verifyMethod = 'sha256';
        } elseif (str_contains($verifyBlock, 'hash_equals')) {
            $verifyMethod = 'hash_equals';
        } else {
            $verifyMethod = 'custom/basic';
        }
    }
    
    $gateways[$slug] = [
        'strict_types' => $strictTypes,
        'namespace' => $namespace,
        'implements_interface' => $implementsInterface,
        'endpoints' => $endpoints,
        'has_bcmath' => $hasBCMath,
        'verify_method' => $verifyMethod,
    ];
}

file_put_contents(__DIR__ . '/detailed_audit.json', json_encode($gateways, JSON_PRETTY_PRINT));
echo "Audited " . count($gateways) . " gateways.\n";
