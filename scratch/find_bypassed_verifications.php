<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$bypasses = [];

foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    // Check if verify function is simple:
    // e.g. return ['success' => true]; without any curl, openssl, hash, etc.
    if (preg_match('/public function verify\((.*?)\)\s*:\s*array\s*\{(.*?)\}/s', $content, $matches)) {
        $verifyBody = trim($matches[2]);
        // Strip comments
        $cleanBody = preg_replace('!/\*.*?\*/!s', '', $verifyBody);
        $cleanBody = preg_replace('!//.*?$!m', '', $cleanBody);
        $cleanBody = trim($cleanBody);
        
        // If the body is very simple, like just returning success => true without any checks
        if (preg_match('/^return\s*\[\s*[\'"]success[\'"]\s*=>\s*true\s*(,\s*[\'"]status[\'"]\s*=>\s*[\'"]completed[\'"]\s*)?\]\s*;$/i', $cleanBody)) {
            $bypasses[$slug][] = 'verify';
        }
    }
    
    // Check verifyWebhook
    if (preg_match('/public function verifyWebhook\((.*?)\)\s*:\s*bool\s*\{(.*?)\}/s', $content, $matches)) {
        $webhookBody = trim($matches[2]);
        $cleanBody = preg_replace('!/\*.*?\*/!s', '', $webhookBody);
        $cleanBody = preg_replace('!//.*?$!m', '', $cleanBody);
        $cleanBody = trim($cleanBody);
        
        if ($cleanBody === 'return true;') {
            // Note: some webhooks return true because the gateway doesn't support signing
            // but we want to inspect them.
            $bypasses[$slug][] = 'verifyWebhook';
        }
    }
}

echo json_encode($bypasses, JSON_PRETTY_PRINT) . "\n";
