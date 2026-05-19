<?php
declare(strict_types=1);
/**
 * Scan for mojibake (UTF-8 em-dash mangled to â€") and other legacy/compat patterns.
 */
$root = dirname(__DIR__);

// 1. Count mojibake (â€" = 0xC3 0xA2 0xE2 0x80 0x9C pattern for em-dash)
$mojibakePattern = "\xC3\xA2\xE2\x80\x9C"; // â€" 
// Actually the common pattern is â€" which is the UTF-8 bytes for — (em-dash) 
// misinterpreted as Windows-1252. The hex is: E2 80 94 → â€"
// But in files it appears as the raw bytes. Let me just look for the 3-byte sequence.
$emDashBroken = "\xE2\x80\x93"; // en-dash (–)
$emDashProper = "\xE2\x80\x94"; // em-dash (—)

// Actually from the file content shown, the pattern is: â€" which is 3 separate characters
// â = C3 A2, € = E2 82 AC, " = E2 80 9C ... no that's not right either.
// Let me just look for the visual pattern shown in the user's files

$files = [];
$totalOccurrences = 0;
$otherBadChars = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    
    // Check for common mojibake patterns (UTF-8 double-encoded or misinterpreted)
    // â€" = em-dash mangled, â€" = en-dash mangled, â€™ = right single quote mangled
    $badPatterns = [
        'â€"' => 'em-dash (—)',     // — mangled
        'â€"' => 'en-dash (–)',     // – mangled  
        'â€™' => 'apostrophe (\')', // ' mangled
        'â€œ' => 'left-dquote (")', // " mangled
        'â€' => 'right-dquote (")', // " mangled
        'â€¢' => 'bullet (•)',      // • mangled
    ];
    
    $rel = str_replace($root . '/', '', str_replace('\\', '/', $file->getPathname()));
    $fileCount = 0;
    
    foreach ($badPatterns as $pattern => $desc) {
        $count = substr_count($content, $pattern);
        if ($count > 0) {
            $fileCount += $count;
        }
    }
    
    if ($fileCount > 0) {
        $files[] = ['file' => $rel, 'count' => $fileCount];
        $totalOccurrences += $fileCount;
    }
}

echo "=== MOJIBAKE SCAN ===\n";
echo "Files affected: " . count($files) . "\n";
echo "Total occurrences: {$totalOccurrences}\n\n";

usort($files, fn($a, $b) => $b['count'] - $a['count']);
foreach (array_slice($files, 0, 20) as $f) {
    echo sprintf("  %3d  %s\n", $f['count'], $f['file']);
}
if (count($files) > 20) {
    echo "  ... and " . (count($files) - 20) . " more files\n";
}

// 2. Scan for legacy/compat patterns
echo "\n=== LEGACY/COMPAT PATTERNS ===\n";

$legacyPatterns = [
    'singleton' => '/private\s+static\s+\?\s*self\s+\$instance/',
    '$_SESSION direct' => '/\$_SESSION\s*\[/',
    '$_GET direct' => '/\$_GET\s*\[/',
    '$_POST direct' => '/\$_POST\s*\[/',
    '$_SERVER direct' => '/\$_SERVER\s*\[/',
    '$_REQUEST direct' => '/\$_REQUEST\s*\[/',
    '$_COOKIE direct' => '/\$_COOKIE\s*\[/',
    'error_log()' => '/\berror_log\s*\(/',
    'global $' => '/\bglobal\s+\$/',
    'extract(' => '/\bextract\s*\(/',
    'backward compat' => '/backward\s+compat/i',
    'legacy' => '/\blegacy\b/i',
    'deprecated' => '/\bdeprecated\b/i',
    'workaround' => '/\bworkaround\b/i',
    'hack' => '/\/\/.*\bhack\b/i',
    'todo' => '/\/\/.*\bTODO\b/',
    'fixme' => '/\/\/.*\bFIXME\b/',
];

$iterator2 = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
);

$legacyResults = [];
foreach ($iterator2 as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    $rel = str_replace($root . '/', '', str_replace('\\', '/', $file->getPathname()));
    
    foreach ($legacyPatterns as $name => $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            $legacyResults[$name][] = ['file' => $rel, 'count' => count($matches[0])];
        }
    }
}

foreach ($legacyResults as $pattern => $hits) {
    $total = array_sum(array_column($hits, 'count'));
    echo "\n{$pattern} ({$total} hits in " . count($hits) . " files):\n";
    foreach ($hits as $h) {
        echo "  {$h['count']}x  {$h['file']}\n";
    }
}
