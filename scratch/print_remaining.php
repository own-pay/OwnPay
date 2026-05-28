<?php
declare(strict_types=1);

$jsonFile = __DIR__ . '/phpstan_errors_utf8.json';
if (!file_exists($jsonFile)) {
    die("File not found\n");
}

$data = json_decode(file_get_contents($jsonFile), true);
$files = [];
foreach ($data['files'] as $file => $fileData) {
    if (str_contains($file, 'modules/gateways/') || str_contains($file, 'modules\\gateways\\')) {
        $files[basename($file)] = count($fileData['messages']);
    }
}
asort($files);
foreach ($files as $name => $count) {
    echo "$name: $count errors\n";
}
echo "Total files with errors: " . count($files) . "\n";
