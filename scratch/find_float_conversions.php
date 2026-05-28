<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$matches = [];

foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    $hasFloat = false;
    $foundLines = [];
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (preg_match('/(\*\s*100|\/\s*100|round\s*\(|float\s*)\)/i', $line) || str_contains($line, '* 100') || str_contains($line, '/ 100')) {
            // Exclude comments
            if (trim($line) !== '' && !str_starts_with(trim($line), '//') && !str_starts_with(trim($line), '*')) {
                $foundLines[] = ($i + 1) . ': ' . trim($line);
                $hasFloat = true;
            }
        }
    }
    
    if ($hasFloat) {
        $matches[$slug] = $foundLines;
    }
}

echo json_encode($matches, JSON_PRETTY_PRINT);
