<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__) . '/modules/gateways';
$directories = scandir($baseDir);

foreach ($directories as $dir) {
    if ($dir === '.' || $dir === '..') {
        continue;
    }
    
    $path = $baseDir . '/' . $dir;
    if (is_dir($path)) {
        $manifestPath = $path . '/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (is_array($manifest)) {
                $changed = false;
                if (!isset($manifest['icon'])) {
                    $manifest['icon'] = 'icon.svg';
                    $changed = true;
                }
                
                // Let's also check if namespace is correct
                if (isset($manifest['namespace'])) {
                    // Make sure it doesn't have double backslashes stored weirdly, or check if it's correct PSR-4
                    // e.g. "namespace": "OwnPay\\Modules\\Gateways\\Adyen"
                }

                if ($changed) {
                    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    echo "Updated manifest for: {$dir}\n";
                }
            }
        }
    }
}
echo "Manifest fix complete!\n";
