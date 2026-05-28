<?php
declare(strict_types=1);

$json = json_decode(file_get_contents(__DIR__ . '/phpstan_errors_utf8.json'), true);
foreach ($json['files'] as $file => $fileData) {
    foreach ($fileData['messages'] as $msg) {
        if (str_contains($msg['message'], 'is_scalar')) {
            echo "- $file: Line {$msg['line']}: {$msg['message']}\n";
        }
    }
}
