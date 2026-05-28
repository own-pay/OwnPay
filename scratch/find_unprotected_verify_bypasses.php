<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$slugs = [
    '2checkout', 'biller-genie', 'bluesnap', 'chase-paymentech', 'cybersource', 'dlocal', 'elavon',
    'fastspring', 'fattmerchant', 'first-data', 'fiserv', 'global-payments', 'heartland', 'helcim',
    'midtrans', 'moneris', 'neteller', 'nmi', 'payline-data', 'payment-depot', 'payoneer', 'paytrace',
    'rapyd', 'shift4', 'skrill', 'stax', 'trustcommerce', 'tsys', 'worldpay'
];

foreach ($slugs as $slug) {
    $path = $dir . '/' . $slug;
    $phpFiles = glob($path . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    // Find variables used in str_starts_with check:
    // e.g. if ($orderId === '' || str_starts_with($orderId, 'SIM_'))
    // or if ($reference === '' || str_starts_with($reference, 'SIM_'))
    // Let's use regex to find:
    // if ($VAR === '' || str_starts_with($VAR, 'SIM_')) {
    // and replace it with:
    // if ($VAR === '' || str_starts_with($VAR, 'SIM_')) {
    //     if ($mode === 'live') {
    //         return [
    //             'success'        => false,
    //             'gateway_trx_id' => '',
    //             'status'         => 'failed',
    //         ];
    //     }
    
    $pattern = '/if\s*\(\s*\$(\w+)\s*===\s*\'\'\s*\|\|\s*str_starts_with\(\s*\$\w+\s*,\s*\'SIM_\'\s*\)\s*\)\s*\{/';
    if (preg_match($pattern, $content, $matches)) {
        $varName = $matches[1];
        $target = "if (\${$varName} === '' || str_starts_with(\${$varName}, 'SIM_')) {";
        $replacement = "if (\${$varName} === '' || str_starts_with(\${$varName}, 'SIM_')) {
            if (\$mode === 'live') {
                return [
                    'success'        => false,
                    'gateway_trx_id' => '',
                    'status'         => 'failed',
                ];
            }";
            
        $newContent = str_replace($target, $replacement, $content);
        if ($newContent !== $content) {
            file_put_contents($file, $newContent);
            echo "[{$slug}] verify() simulation bypass hardened.\n";
        }
    } else {
        // Let's check alternate formats, like if (str_starts_with($orderId, 'SIM_'))
        $patternAlt = '/if\s*\(\s*str_starts_with\(\s*\$(\w+)\s*,\s*\'SIM_\'\s*\)\s*\)\s*\{/';
        if (preg_match($patternAlt, $content, $matches)) {
            $varName = $matches[1];
            $target = "if (str_starts_with(\${$varName}, 'SIM_')) {";
            $replacement = "if (str_starts_with(\${$varName}, 'SIM_')) {
                if (\$mode === 'live') {
                    return [
                        'success'        => false,
                        'gateway_trx_id' => '',
                        'status'         => 'failed',
                    ];
                }";
            $newContent = str_replace($target, $replacement, $content);
            if ($newContent !== $content) {
                file_put_contents($file, $newContent);
                echo "[{$slug}] verify() simulation bypass hardened (alt format).\n";
            }
        }
    }
}
