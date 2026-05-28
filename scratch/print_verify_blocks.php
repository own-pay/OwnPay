<?php
declare(strict_types=1);

$slugs = [
    'authorize-net',
    'blik',
    'braintree',
    'ebanx',
    'fawry',
    'giropay',
    'kushki',
    'paddle',
    'payfast',
    'paytabs',
    'przelewy24',
    'sofort',
    'trustly',
    'xendit'
];

$dirPath = __DIR__ . '/../modules/gateways';
foreach ($slugs as $slug) {
    $path = $dirPath . '/' . $slug;
    $phpFiles = glob($path . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    $pos = strpos($content, 'function verify(');
    if ($pos !== false) {
        $block = substr($content, $pos, 400);
        echo "=== $slug ===\n$block\n\n";
    }
}
