<?php
declare(strict_types=1);

$data = json_decode(file_get_contents(__DIR__ . '/audit_report.json'), true);

$stubs = [];
$fully_functional = [];

foreach ($data as $name => $info) {
    if ($info['status'] === 'stub') {
        $stubs[$name] = $info;
    } else {
        $fully_functional[$name] = $info;
    }
}

echo "Total Gateways: " . count($data) . "\n";
echo "Fully Functional: " . count($fully_functional) . "\n";
echo "Stubs/Placeholders: " . count($stubs) . "\n\n";

echo "List of Stubs:\n";
foreach ($stubs as $name => $info) {
    echo "  - $name: " . implode(', ', $info['reasons']) . " (" . $info['file_size'] . " bytes)\n";
}
