<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__) . '/modules/gateways';
$directories = scandir($baseDir);

$requiredFields = [
    'name',
    'slug',
    'version',
    'description',
    'author',
    'type',
    'category',
    'icon',
    'color',
    'entrypoint',
    'namespace',
    'capabilities',
    'requires',
    'csp',
    'permissions'
];

foreach ($directories as $dir) {
    if ($dir === '.' || $dir === '..') {
        continue;
    }
    
    $path = $baseDir . '/' . $dir;
    if (is_dir($path)) {
        $manifestPath = $path . '/manifest.json';
        if (!file_exists($manifestPath)) {
            echo "MISSING MANIFEST: {$dir}\n";
            continue;
        }
        
        $content = file_get_contents($manifestPath);
        $manifest = json_decode($content, true);
        if (!is_array($manifest)) {
            echo "INVALID JSON: {$dir}\n";
            continue;
        }
        
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($manifest[$field])) {
                $missing[] = $field;
            }
        }
        
        if (count($missing) > 0) {
            echo "Gateway '{$dir}' has missing fields: " . implode(', ', $missing) . "\n";
        }
    }
}
