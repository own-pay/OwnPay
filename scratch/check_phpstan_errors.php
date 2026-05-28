<?php
declare(strict_types=1);

$logFile = 'C:\\Users\\iamna\\.gemini\\antigravity\\brain\\9716b760-262d-494c-bea2-ae57a1b849ff\\.system_generated\\tasks\\task-265.log';
if (!file_exists($logFile)) {
    die("Log file not found.\n");
}

$content = file_get_contents($logFile);
$lines = explode("\n", $content);

$targetGateways = [
    'paytabs', 'fawry', 'midtrans', 'xendit', 'ebanx', 'kushki', 'payfast', 'paddle', 'braintree', 'authorize-net'
];

$currentFile = null;
$fileErrors = [];

foreach ($lines as $line) {
    if (preg_match('/gateways\\\\([^\\\\]+)\\\\([a-zA-Z0-9_-]+\\.php)/', $line, $matches)) {
        $currentFile = $matches[1] . '/' . $matches[2];
        if (!isset($fileErrors[$currentFile])) {
            $fileErrors[$currentFile] = [];
        }
    } elseif (preg_match('/^\s+(\d+)\s+(.+)$/', $line, $matches) && $currentFile !== null) {
        $lineNum = $matches[1];
        $errMsg = trim($matches[2]);
        $fileErrors[$currentFile][] = "Line $lineNum: $errMsg";
    }
}

foreach ($fileErrors as $file => $errors) {
    $slug = explode('/', $file)[0];
    if (in_array($slug, $targetGateways)) {
        echo "=== $file ===\n";
        foreach ($errors as $err) {
            echo "  $err\n";
        }
        echo "\n";
    }
}
