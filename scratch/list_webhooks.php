<?php
declare(strict_types=1);

$content = file_get_contents(__DIR__ . '/build_gateways.php');
if ($content === false) {
    die("Failed to read file\n");
}

$gateways = require __DIR__ . '/../config/services.php'; // wait, no, build_gateways has the local $gateways array.
// Let's just find the keys in the $gateways array by parsing the file or executing a modified version of it.
// We can include build_gateways.php but since it executes side effects (creates directories), let's just inspect it.
preg_match_all('/\'([a-zA-Z0-9_-]+)\'\s*=>\s*\[/', $content, $matches);
foreach ($matches[1] as $gw) {
    if (in_array($gw, ['gateways', 'options', 'checkout', 'amount', 'local_price', 'quick_pay'])) continue;
    // check if this gw config block has webhook
    $pos = strpos($content, "'$gw' =>");
    if ($pos !== false) {
        $nextBlock = substr($content, $pos, 3000);
        if (str_contains($nextBlock, "'webhook' =>")) {
            echo "Gateway: $gw has custom webhook logic\n";
        }
    }
}
