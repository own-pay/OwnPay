<?php

$dir = __DIR__ . '/../app/admin/dashboard';

$replacements = [
    '/(?<![a-zA-Z0-9_\\\\])getData\s*\(/i' => '\OwnPay\Service\System\CrudService::selectLegacy(',
    '/(?<![a-zA-Z0-9_\\\\])insertData\s*\(/i' => '\OwnPay\Service\System\CrudService::insertLegacy(',
    '/(?<![a-zA-Z0-9_\\\\])updateData\s*\(/i' => '\OwnPay\Service\System\CrudService::updateLegacy(',
    '/(?<![a-zA-Z0-9_\\\\])deleteData\s*\(/i' => '\OwnPay\Service\System\CrudService::deleteLegacy(',
    
    '/(?<![a-zA-Z0-9_\\\\])get_env\s*\(/i' => '\OwnPay\Service\System\EnvironmentService::get(',
    '/(?<![a-zA-Z0-9_\\\\])set_env\s*\(/i' => '\OwnPay\Service\System\EnvironmentService::set(',
    
    '/(?<![a-zA-Z0-9_\\\\])money_sanitize\s*\(/i' => '\OwnPay\Service\Payment\CurrencyService::sanitize(',
    '/(?<![a-zA-Z0-9_\\\\])money_add\s*\(/i' => '\OwnPay\Service\Payment\CurrencyService::add(',
    '/(?<![a-zA-Z0-9_\\\\])money_sub\s*\(/i' => '\OwnPay\Service\Payment\CurrencyService::sub(',
    '/(?<![a-zA-Z0-9_\\\\])money_mul\s*\(/i' => '\OwnPay\Service\Payment\CurrencyService::mul(',
    '/(?<![a-zA-Z0-9_\\\\])money_div\s*\(/i' => '\OwnPay\Service\Payment\CurrencyService::div(',
    '/(?<![a-zA-Z0-9_\\\\])money_round\s*\(/i' => '\OwnPay\Service\Payment\CurrencyService::round(',
    
    '/(?<![a-zA-Z0-9_\\\\])hasPermission\s*\(/i' => '\OwnPay\Service\Auth\PermissionService::hasPermission(',
    '/(?<![a-zA-Z0-9_\\\\])canAccessPage\s*\(/i' => '\OwnPay\Service\Auth\PermissionService::canAccessPage(',
];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$totalReplacements = 0;
$filesChanged = 0;

foreach ($files as $file) {
    if ($file->isDir()) continue;
    if ($file->getExtension() !== 'php') continue;

    $content = file_get_contents($file->getPathname());
    $changed = false;
    $fileReplacements = 0;

    foreach ($replacements as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content, -1, $count);
        if ($count > 0) {
            $changed = true;
            $fileReplacements += $count;
        }
    }

    if ($changed) {
        file_put_contents($file->getPathname(), $content);
        $totalReplacements += $fileReplacements;
        $filesChanged++;
        echo "Updated {$file->getBasename()} ({$fileReplacements} replacements)\n";
    }
}

echo "\nSummary: Made {$totalReplacements} replacements across {$filesChanged} files.\n";
