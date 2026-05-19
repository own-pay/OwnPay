<?php
$raw = file_get_contents(dirname(__DIR__) . '/storage/phpstan_errors.json');
$raw = ltrim($raw, "\xEF\xBB\xBF"); // Remove BOM
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['files'])) {
    echo "Invalid JSON\n";
    exit(1);
}

echo "=== ERRORS BY FILE ===\n\n";
$grouped = [];
$allMessages = [];
foreach ($data['files'] as $file => $info) {
    $short = str_replace('C:\\laragon\\www\\ownpay\\', '', $file);
    foreach ($info['messages'] as $msg) {
        $grouped[$short] = ($grouped[$short] ?? 0) + 1;
        $allMessages[] = [
            'file' => $short,
            'line' => $msg['line'],
            'msg'  => $msg['message'],
        ];
    }
}

arsort($grouped);
foreach ($grouped as $file => $count) {
    echo "{$count}\t{$file}\n";
}

echo "\n=== ALL {$data['totals']['file_errors']} ERRORS ===\n\n";
foreach ($allMessages as $i => $m) {
    $n = $i + 1;
    echo "{$n}. {$m['file']}:{$m['line']} — {$m['msg']}\n";
}
