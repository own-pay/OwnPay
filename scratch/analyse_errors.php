<?php
declare(strict_types=1);

$jsonFile = __DIR__ . '/phpstan_errors3.json';
if (!file_exists($jsonFile)) {
    die("File not found\n");
}

$raw = file_get_contents($jsonFile);
// Detect UTF-16LE using bin2hex for robustness
if (bin2hex(substr($raw, 0, 2)) === 'fffe') {
    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
}

file_put_contents(__DIR__ . '/phpstan_errors_utf8.json', $raw);

$data = json_decode($raw, true);
if (!is_array($data)) {
    die("Invalid JSON format\n");
}

$totals = $data['totals'] ?? [];
echo "Total errors: " . ($totals['errors'] ?? 0) . "\n";
echo "Total file errors: " . ($totals['file_errors'] ?? 0) . "\n";

$files = $data['files'] ?? [];
$errorCountByFile = [];
foreach ($files as $filePath => $fileData) {
    $errorCountByFile[$filePath] = count($fileData['messages'] ?? []);
}

arsort($errorCountByFile);
foreach ($errorCountByFile as $file => $count) {
    echo "- $file: $count errors\n";
}
