<?php
declare(strict_types=1);

$report = json_decode(file_get_contents(__DIR__ . '/audit_report.json'), true);
foreach ($report as $name => $data) {
    if ($data['status'] === 'stub' || !empty($data['reasons'])) {
        echo $name . ': ' . json_encode($data) . PHP_EOL;
    }
}
