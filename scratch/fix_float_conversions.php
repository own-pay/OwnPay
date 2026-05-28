<?php
declare(strict_types=1);

$filesToFix = [
    __DIR__ . '/../modules/gateways/blik/BlikGateway.php' => [
        ['(int) round(((float) $params[\'amount\']) * 100)', '(int) bcmul((string) (float) $params[\'amount\'], \'100\', 0)'],
        ['(int) round($amountFloat * 100.0)', '(int) bcmul((string) $amountFloat, \'100\', 0)']
    ],
    __DIR__ . '/../modules/gateways/giropay/GiropayGateway.php' => [
        ['(int) round(((float) $params[\'amount\']) * 100)', '(int) bcmul((string) (float) $params[\'amount\'], \'100\', 0)'],
        ['$amountFloat = $amountCents / 100.0;', '$amountFloat = (float) bcdiv((string) $amountCents, \'100\', 2);']
    ],
    __DIR__ . '/../modules/gateways/przelewy24/Przelewy24Gateway.php' => [
        ['(int) round(((float) $params[\'amount\']) * 100)', '(int) bcmul((string) (float) $params[\'amount\'], \'100\', 0)'],
        ['(int) round($amountFloat * 100.0)', '(int) bcmul((string) $amountFloat, \'100\', 0)']
    ]
];

foreach ($filesToFix as $file => $replacements) {
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($file);
    foreach ($replacements as $rep) {
        $content = str_replace($rep[0], $rep[1], $content);
    }
    file_put_contents($file, $content);
    echo "Fixed: " . basename($file) . "\n";
    
    // Check syntax
    $output = [];
    $ret = 0;
    exec("php -l " . escapeshellarg($file), $output, $ret);
    if ($ret !== 0) {
        echo "  - Syntax ERROR: " . implode("\n", $output) . "\n";
    } else {
        echo "  - Syntax OK\n";
    }
}
