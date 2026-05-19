<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixed = 0;
$fileCount = 0;

// Build replacement map using chr() to avoid encoding issues in source
$replacements = [];

// Pattern 1: em-dash (most common, ~174 hits)
$replacements[chr(0xC3).chr(0xA2).chr(0xE2).chr(0x82).chr(0xAC).chr(0xE2).chr(0x80).chr(0x9D)] = chr(0xE2).chr(0x80).chr(0x94);

// Pattern 2: em-dash swapped variant (~1672 hits in chains)
$replacements[chr(0xC3).chr(0xA2).chr(0xE2).chr(0x80).chr(0x9D).chr(0xE2).chr(0x82).chr(0xAC)] = chr(0xE2).chr(0x80).chr(0x94);

// Pattern 3: em-dash 5-byte variant (2 hits)
$replacements[chr(0xC3).chr(0xA2).chr(0xE2).chr(0x82).chr(0xAC).chr(0xC2).chr(0x9D)] = chr(0xE2).chr(0x80).chr(0x94);

// Pattern 4: box-drawing horizontal ─ (~20 hits)
$replacements[chr(0xC3).chr(0xA2).chr(0xE2).chr(0x80).chr(0xA0).chr(0xE2).chr(0x80).chr(0x99)] = chr(0xE2).chr(0x94).chr(0x80);

// Pattern 5: euro sign (2 hits)
$replacements[chr(0xC3).chr(0xA2).chr(0xE2).chr(0x80).chr(0x9A).chr(0xC2).chr(0xAC)] = chr(0xE2).chr(0x82).chr(0xAC);

// Pattern 6: superscript 1 (1 hit)
$replacements[chr(0xC3).chr(0xA2).chr(0xE2).chr(0x80).chr(0x9A).chr(0xC2).chr(0xB9)] = chr(0xC2).chr(0xB9);

// Sort by key length descending (longer patterns first to avoid partial matches)
uksort($replacements, fn($a, $b) => strlen($b) - strlen($a));

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    
    $original = $content;
    
    foreach ($replacements as $bad => $good) {
        $content = str_replace($bad, $good, $content);
    }
    
    if ($content !== $original) {
        $changes = 0;
        foreach ($replacements as $bad => $good) {
            $changes += substr_count($original, $bad);
        }
        
        file_put_contents($file->getPathname(), $content);
        $rel = str_replace($root . '\\', '', $file->getPathname());
        $rel = str_replace('\\', '/', $rel);
        echo sprintf("  %3d  %s\n", $changes, $rel);
        $fixed += $changes;
        $fileCount++;
    }
}

echo "\nFixed {$fixed} mojibake in {$fileCount} files\n";

// Verify
$remaining = 0;
$iterator2 = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator2 as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    $offset = 0;
    while (($pos = strpos($content, chr(0xC3).chr(0xA2), $offset)) !== false) {
        $remaining++;
        $offset = $pos + 2;
    }
}
echo "Remaining C3A2 sequences: {$remaining}\n";

// Syntax check a sample
echo "\nSyntax spot-check:\n";
$checks = [
    'src/Event/EventManager.php',
    'src/Http/Request.php',
    'src/Core/Database.php',
    'src/View/FragmentRenderer.php',
    'src/Middleware/PermissionMiddleware.php',
];
foreach ($checks as $f) {
    exec("php -l \"{$root}/{$f}\" 2>&1", $out, $code);
    echo ($code === 0 ? 'OK' : 'FAIL') . "  {$f}\n";
    $out = [];
}
