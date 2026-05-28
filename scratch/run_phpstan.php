<?php
declare(strict_types=1);

echo "Running PHPStan...\n";
$cmd = 'vendor\\bin\\phpstan.bat analyse modules --error-format=json --memory-limit=2G';
if (!file_exists('vendor/bin/phpstan.bat') && file_exists('vendor/bin/phpstan')) {
    $cmd = 'php vendor/bin/phpstan analyse modules --error-format=json --memory-limit=2G';
}
exec($cmd, $output, $exitCode);

file_put_contents(__DIR__ . '/phpstan_errors3.json', implode("\n", $output));
echo "Finished with exit code: $exitCode. Output bytes written: " . strlen(implode("\n", $output)) . "\n";
