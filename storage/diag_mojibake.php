<?php
declare(strict_types=1);
/**
 * Fix all mojibake in src/. Replace â€" → —, â€™ → ', etc.
 * Also fix â€" (en-dash variant), â€" (other variants).
 */
$root = dirname(__DIR__);
$fixed = 0;
$fileCount = 0;

$replacements = [
    "â€"" => "—",   // em-dash
    "â€"" => "–",   // en-dash  
    "â€™" => "'",    // right single quote
    "â€œ" => '"',    // left double quote
    "â€\x9D" => '"', // right double quote (raw byte)
    "â€¢" => "•",   // bullet
    "â€¦" => "…",   // ellipsis
    "â€"" => "—",   // another em-dash variant
];

// Actually the problem is simpler. The files have UTF-8 encoded characters that
// got double-encoded or mis-encoded. The pattern "â€"" is the 6-byte sequence:
// C3 A2 (â) + E2 82 AC (€) + E2 80 9C (") = original UTF-8 em-dash E2 80 94 (—)
// misread as Latin-1 and re-encoded to UTF-8.
//
// But we see "â€"" in the display which means the actual bytes in the file are the
// UTF-8 for em-dash (E2 80 94) being correctly stored but some viewer renders them
// incorrectly. Let me check what's actually in the files.

// Check a known file
$sample = file_get_contents($root . '/src/Event/EventManager.php');
$pos = strpos($sample, 'event engine');
if ($pos !== false) {
    $snippet = substr($sample, $pos + 13, 10);
    echo "Raw bytes after 'event engine': ";
    for ($i = 0; $i < strlen($snippet); $i++) {
        echo sprintf('%02X ', ord($snippet[$i]));
    }
    echo "\n";
    echo "String: " . $snippet . "\n";
}

// The em-dash in UTF-8 is: E2 80 94
// If bytes are E2 80 93, that's en-dash
// If we see C3 A2 C2 80 C2 94, that's double-encoded UTF-8

// Let's just find the actual byte pattern
$badBytes = "\xC3\xA2\xC2\x80\xC2\x94"; // double-encoded em-dash
$badBytes2 = "\xC3\xA2\xC2\x80\xC2\x93"; // double-encoded en-dash
$badBytes3 = "\xC3\xA2\xC2\x80\xC2\x99"; // double-encoded right single quote

// Also check for: â€" which in raw form could be 3 separate UTF-8 chars
$bad_a = "\xC3\xA2"; // â
$bad_b = "\xE2\x80\x9C"; // "
$bad_c = "\xE2\x80\x93"; // –

echo "\nChecking for double-encoded patterns:\n";
echo "Pattern 1 (C3A2 C280 C294): " . substr_count($sample, $badBytes) . " hits\n";
echo "Pattern 2 (raw â€"): " . substr_count($sample, $bad_a . $bad_b) . " hits\n";

// Check for the actual rendered pattern in the file
// The view_file shows: â€" which is 3 chars: â (U+00E2) + € (U+20AC) + " (U+201C)  
// In UTF-8: C3 A2 + E2 82 AC + E2 80 9C = 9 bytes
$pat3 = "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C";
echo "Pattern 3 (â€"): " . substr_count($sample, $pat3) . " hits\n";

// Let me just try mb_detect_encoding
echo "File encoding: " . mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) . "\n";

// Actually the simplest approach: search for every 3+ byte sequence that looks like 
// double-encoded UTF-8 and replace.
// The double-encoding pattern is: C3 xx C2 xx C2 xx where xx are the original bytes.
// Or the pattern could be that files were saved with BOM or wrong encoding.

// Let me just search all unique byte sequences around known problem areas
$lines = explode("\n", $sample);
foreach ($lines as $i => $line) {
    if (stripos($line, 'event engine') !== false || stripos($line, 'Hook/Filter') !== false) {
        echo "\nLine " . ($i+1) . " bytes:\n";
        for ($j = 0; $j < strlen($line); $j++) {
            $b = ord($line[$j]);
            if ($b > 0x7F) {
                echo sprintf("[%02X]", $b);
            } else {
                echo chr($b);
            }
        }
        echo "\n";
    }
}
