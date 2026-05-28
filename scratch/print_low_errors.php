<?php
declare(strict_types=1);

$jsonFile = __DIR__ . '/phpstan_errors_utf8.json';
if (!file_exists($jsonFile)) {
    die("File not found\n");
}

$data = json_decode(file_get_contents($jsonFile), true);
foreach ($data['files'] as $file => $fileData) {
    $messages = $fileData['messages'] ?? [];
    if (count($messages) <= 11 && count($messages) > 0) {
        echo basename($file) . ":\n";
        foreach ($messages as $msg) {
            echo "  Line {$msg['line']}: {$msg['message']}\n";
        }
    }
}
