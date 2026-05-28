<?php
declare(strict_types=1);

$audit = json_decode(file_get_contents(__DIR__ . '/detailed_audit.json'), true);

$nonStrict = [];
$nonInterface = [];
$httpUrls = [];
$noBCMath = [];

foreach ($audit as $slug => $data) {
    if (!$data['strict_types']) {
        $nonStrict[] = $slug;
    }
    if (!$data['implements_interface']) {
        $nonInterface[] = $slug;
    }
    foreach ($data['endpoints'] as $url) {
        if (str_starts_with($url, 'http://') && !str_contains($url, 'localhost') && !str_contains($url, '127.0.0.1')) {
            $httpUrls[$slug][] = $url;
        }
    }
    if (!$data['has_bcmath']) {
        $noBCMath[] = $slug;
    }
}

echo "=== NON-STRICT TYPES (" . count($nonStrict) . ") ===\n" . implode(', ', $nonStrict) . "\n\n";
echo "=== NON-INTERFACE (" . count($nonInterface) . ") ===\n" . implode(', ', $nonInterface) . "\n\n";
echo "=== HTTP ENDPOINTS (" . count($httpUrls) . ") ===\n";
foreach ($httpUrls as $slug => $urls) {
    echo "- $slug: " . implode(', ', $urls) . "\n";
}
echo "\n=== NO BCMATH (" . count($noBCMath) . ") ===\n" . implode(', ', $noBCMath) . "\n\n";
