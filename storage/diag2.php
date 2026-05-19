<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$sample = file_get_contents($root . '/src/Event/EventManager.php');
$lines = explode("\n", $sample);
foreach ($lines as $i => $line) {
    if (stripos($line, 'Hook') !== false && stripos($line, 'event') !== false) {
        echo "Line " . ($i+1) . " hex dump:\n";
        for ($j = 0; $j < strlen($line); $j++) {
            $b = ord($line[$j]);
            if ($b > 0x7F) {
                echo sprintf("[%02X]", $b);
            } else {
                echo chr($b);
            }
        }
        echo "\n\n";
    }
    if (stripos($line, 'fire-and-forget') !== false) {
        echo "Line " . ($i+1) . " hex dump:\n";
        for ($j = 0; $j < strlen($line); $j++) {
            $b = ord($line[$j]);
            if ($b > 0x7F) {
                echo sprintf("[%02X]", $b);
            } else {
                echo chr($b);
            }
        }
        echo "\n\n";
    }
}
echo "File encoding: " . mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) . "\n";
