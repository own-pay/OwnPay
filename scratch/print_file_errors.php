<?php
declare(strict_types=1);

$filename = $argv[1] ?? 'WiseGateway.php';
$json = json_decode(file_get_contents(__DIR__ . '/phpstan_errors_utf8.json'), true);
foreach ($json['files'] as $file => $fileData) {
    if (str_contains(strtolower($file), strtolower($filename))) {
        echo "Errors for $file:\n";
        foreach ($fileData['messages'] as $msg) {
            echo "Line {$msg['line']}: {$msg['message']}\n";
        }
    }
}
