<?php
$raw = file_get_contents(__DIR__ . '/phpstan_errors2.json');
echo "Length: " . strlen($raw) . "\n";
echo "First 20 bytes hex: " . bin2hex(substr($raw, 0, 20)) . "\n";
echo "First 100 bytes: " . substr($raw, 0, 100) . "\n";
