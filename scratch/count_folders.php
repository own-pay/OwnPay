<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$folders = [];
foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $folders[] = basename($folder);
}
echo "Count: " . count($folders) . "\n";
echo "Folders: " . implode(', ', $folders) . "\n";
