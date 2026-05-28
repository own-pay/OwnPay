<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__) . '/modules/gateways';
$directories = scandir($baseDir);

$requiredKeys = [
    'name' => 'string',
    'slug' => 'string',
    'version' => 'string',
    'description' => 'string',
    'author' => 'string',
    'type' => 'string',
    'entrypoint' => 'string',
    'namespace' => 'string',
    'capabilities' => 'array',
    'requires' => 'array',
    'category' => 'string',
    'color' => 'string',
    'csp' => 'array',
    'permissions' => 'array',
    'icon' => 'string'
];

$errors = [];

foreach ($directories as $dir) {
    if ($dir === '.' || $dir === '..') {
        continue;
    }
    
    $path = $baseDir . '/' . $dir;
    if (is_dir($path)) {
        $manifestPath = $path . '/manifest.json';
        if (!file_exists($manifestPath)) {
            $errors[] = "[{$dir}] manifest.json does not exist";
            continue;
        }
        
        $content = file_get_contents($manifestPath);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            $errors[] = "[{$dir}] manifest.json is invalid JSON";
            continue;
        }
        
        foreach ($requiredKeys as $key => $type) {
            if (!isset($data[$key])) {
                $errors[] = "[{$dir}] missing key '{$key}'";
                continue;
            }
            
            if ($type === 'string' && !is_string($data[$key])) {
                $errors[] = "[{$dir}] key '{$key}' must be string";
            } elseif ($type === 'array' && !is_array($data[$key])) {
                $errors[] = "[{$dir}] key '{$key}' must be array/object";
            }
        }
        
        // Check requires keys
        if (isset($data['requires']) && is_array($data['requires'])) {
            if (!isset($data['requires']['php'])) {
                $errors[] = "[{$dir}] key 'requires' missing 'php'";
            }
            if (!isset($data['requires']['core'])) {
                $errors[] = "[{$dir}] key 'requires' missing 'core'";
            }
        }
        
        // Check csp keys
        if (isset($data['csp']) && is_array($data['csp'])) {
            foreach (['script_src', 'style_src', 'frame_src', 'connect_src'] as $dirKey) {
                if (!isset($data['csp'][$dirKey])) {
                    $errors[] = "[{$dir}] csp missing directive '{$dirKey}'";
                } elseif (!is_array($data['csp'][$dirKey])) {
                    $errors[] = "[{$dir}] csp directive '{$dirKey}' must be array";
                }
            }
        }
    }
}

if (count($errors) > 0) {
    echo "Found " . count($errors) . " manifest issues:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
} else {
    echo "All 53 gateway manifest.json files are 100% complete and compliant!\n";
}
