<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$inventory = [];

foreach (glob($dir . '/*') as $folder) {
    if (!is_dir($folder)) continue;
    $slug = basename($folder);
    
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    // Extract metadata
    $name = $slug;
    if (preg_match('/\'name\'\s*=>\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
        $name = $matches[1];
    }
    
    // Find endpoints
    $endpoints = [];
    if (preg_match_all('/https?:\/\/[a-zA-Z0-9_\-\.\/\?=&%#]+/i', $content, $matches)) {
        $endpoints = array_unique($matches[0]);
    }
    
    // Find signature algorithm or webhook verification method
    $verifyWebhook = 'none';
    if (str_contains($content, 'verifyWebhook')) {
        $pos = strpos($content, 'function verifyWebhook');
        $verifyBlock = substr($content, $pos, 600);
        if (str_contains($verifyBlock, 'hash_hmac')) {
            $verifyWebhook = 'hash_hmac';
        } elseif (str_contains($verifyBlock, 'hash_equals')) {
            $verifyWebhook = 'hash_equals';
        } elseif (str_contains($verifyBlock, 'openssl_verify')) {
            $verifyWebhook = 'openssl_verify';
        } elseif (str_contains($verifyBlock, 'md5')) {
            $verifyWebhook = 'md5';
        } elseif (str_contains($verifyBlock, 'sha256')) {
            $verifyWebhook = 'sha256';
        } else {
            $verifyWebhook = 'custom/basic';
        }
    }
    
    // Find API version indications
    $apiVersion = 'unknown';
    if (preg_match('/\/v\d+(\.\d+)?/i', $content, $matches)) {
        $apiVersion = $matches[0];
    }
    
    $inventory[$slug] = [
        'name' => $name,
        'file' => $file,
        'endpoints' => $endpoints,
        'webhook_verification' => $verifyWebhook,
        'api_version' => $apiVersion,
        'file_size' => filesize($file),
    ];
}

file_put_contents(__DIR__ . '/gateways_inventory.json', json_encode($inventory, JSON_PRETTY_PRINT));
echo "Inventory completed for " . count($inventory) . " gateways.\n";
