<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$baseDir = dirname(__DIR__) . '/modules/gateways';
$directories = scandir($baseDir);

$errors = [];
$loadedCount = 0;

foreach ($directories as $dir) {
    if ($dir === '.' || $dir === '..') {
        continue;
    }
    
    $path = $baseDir . '/' . $dir;
    if (is_dir($path)) {
        try {
            $manifest = \OwnPay\Plugin\PluginManifest::fromDirectory($path);
            if ($manifest === null) {
                $errors[] = "[{$dir}] failed to parse manifest.json";
                continue;
            }
            
            $entrypointFile = $path . '/' . $manifest->entrypoint;
            if (!file_exists($entrypointFile)) {
                $errors[] = "[{$dir}] entrypoint file '{$manifest->entrypoint}' not found at {$entrypointFile}";
                continue;
            }
            
            require_once $entrypointFile;
            
            $className = pathinfo($manifest->entrypoint, PATHINFO_FILENAME);
            $fqcn = rtrim($manifest->namespace, '\\') . '\\' . $className;
            
            if (!class_exists($fqcn)) {
                $errors[] = "[{$dir}] class '{$fqcn}' does not exist after requiring '{$manifest->entrypoint}'";
                continue;
            }
            
            if (!is_subclass_of($fqcn, \OwnPay\Plugin\PluginInterface::class)) {
                $errors[] = "[{$dir}] class '{$fqcn}' does not implement PluginInterface";
            }
            
            if (!is_subclass_of($fqcn, \OwnPay\Gateway\GatewayAdapterInterface::class)) {
                $errors[] = "[{$dir}] class '{$fqcn}' does not implement GatewayAdapterInterface";
            }
            
            echo "PASSED: {$dir} -> resolved and loaded class: {$fqcn}\n";
            $loadedCount++;
            
        } catch (\Throwable $e) {
            $errors[] = "[{$dir}] error during loading: " . $e->getMessage();
        }
    }
}

echo "\n--- Validation Summary ---\n";
if (count($errors) > 0) {
    echo "Found " . count($errors) . " loadability issues:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
} else {
    echo "All " . $loadedCount . " gateway plugins are 100% loadable and conformant to OwnPay's core interfaces!\n";
}

