<?php
declare(strict_types=1);
$root = dirname(__DIR__);

// Known mojibake pattern: C3 A2 E2 82 AC E2 80 9D = em-dash
// This is UTF-8 encoding of: â (C3 A2) + € (E2 82 AC) + right-double-quote (E2 80 9D)
// Which is the Windows-1252 interpretation of UTF-8 em-dash bytes E2 80 94
// decoded as: â=E2, €=80 (Win-1252 0x80=€), "=94 (Win-1252 0x94=")

// Full map of Win-1252 → UTF-8 re-encoding:
// Original UTF-8 em-dash: E2 80 94
// Win-1252 interprets: E2→â, 80→€, 94→"
// Re-encodes to UTF-8: C3A2 + E282AC + E2809D
// Wait, 94 in Win-1252 = RIGHT DOUBLE QUOTATION MARK (U+201D) → E2 80 9D

// So the exact pattern varies based on the original character:
// — (em-dash, U+2014, UTF-8: E2 80 94): Win-1252 → â€" → C3A2 E282AC E2809C
// Wait, 94 in CP-1252 maps to U+201D → E2 80 9D
// But the scan found â€" which visually shows as that string... let me check other chars.

$patterns = [];

// Scan all PHP files and collect unique mojibake sequences
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
);

$sequences = [];
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    
    // Find all C3 A2 sequences (start of mojibake)
    $offset = 0;
    while (($pos = strpos($content, "\xC3\xA2", $offset)) !== false) {
        // Extract next 10 bytes for analysis
        $seq = substr($content, $pos, 10);
        $hex = '';
        for ($i = 0; $i < min(10, strlen($seq)); $i++) {
            $hex .= sprintf('%02X ', ord($seq[$i]));
        }
        $sequences[$hex] = ($sequences[$hex] ?? 0) + 1;
        $offset = $pos + 2;
    }
}

echo "=== UNIQUE MOJIBAKE SEQUENCES ===\n";
arsort($sequences);
foreach ($sequences as $hex => $count) {
    echo sprintf("%4d  %s\n", $count, $hex);
}

echo "\n=== TOTAL UNIQUE PATTERNS: " . count($sequences) . " ===\n";
echo "=== TOTAL OCCURRENCES: " . array_sum($sequences) . " ===\n";
