<?php
/**
 * Fix SystemUpdateJob — HttpClient static call, DateHelper import fix
 */
$root = dirname(__DIR__);
$file = $root . '/src/Cron/SystemUpdateJob.php';
$content = file_get_contents($file);

// Fix 1: Replace HttpClient::get() static call with instance call
$old = 'HttpClient::get(self::MANIFEST_URL)';
$new = "(new HttpClient(10, 5))->get(self::MANIFEST_URL)['body']";
$content = str_replace($old, $new, $content);

// Fix 2: Also fix the broken use statement at line 11 (has \r before use)
$content = str_replace("\r\ruse OwnPay\\Support\\DateHelper;\n", "\r\nuse OwnPay\\Support\\DateHelper;\r\n", $content);

file_put_contents($file, $content);
echo "OK: SystemUpdateJob.php fixed\n";

// Verify syntax
exec("php -l {$file} 2>&1", $out, $code);
echo ($code === 0 ? "SYNTAX OK" : "SYNTAX FAIL: " . implode(', ', $out)) . "\n";
