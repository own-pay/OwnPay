<?php
declare(strict_types=1);

$dirPath = __DIR__ . '/../modules/gateways';
foreach (glob($dirPath . '/*') as $dir) {
    if (!is_dir($dir)) continue;
    $files = array_map('basename', glob($dir . '/*'));
    echo basename($dir) . ': ' . implode(', ', $files) . PHP_EOL;
}
