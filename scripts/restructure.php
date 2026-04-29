<?php

$src = __DIR__ . '/../src';

$dirs = [
    'Controller/Admin',
    'Controller/Api',
    'Controller/Checkout',
    'Service/Auth',
    'Service/Payment',
    'Service/Sms',
    'Service/Notification',
    'Service/System',
    'Service/Device',
    'Service/Customer',
];

foreach ($dirs as $dir) {
    if (!is_dir("$src/$dir")) {
        mkdir("$src/$dir", 0755, true);
    }
}

// Map of old class names to new namespaces
$moves = [
    // Controllers -> Admin
    'Controller/AddonController.php' => 'Controller/Admin',
    'Controller/ApiKeyController.php' => 'Controller/Admin',
    'Controller/AuthController.php' => 'Controller/Admin',
    'Controller/BalanceVerificationController.php' => 'Controller/Admin',
    'Controller/BrandController.php' => 'Controller/Admin',
    'Controller/CurrencyController.php' => 'Controller/Admin',
    'Controller/CustomerController.php' => 'Controller/Admin',
    'Controller/DashboardController.php' => 'Controller/Admin',
    'Controller/DeviceController.php' => 'Controller/Admin',
    'Controller/DomainController.php' => 'Controller/Admin',
    'Controller/FaqController.php' => 'Controller/Admin',
    'Controller/GatewayController.php' => 'Controller/Admin',
    'Controller/InvoiceController.php' => 'Controller/Admin',
    'Controller/PaymentLinkController.php' => 'Controller/Admin',
    'Controller/PluginController.php' => 'Controller/Admin',
    'Controller/SettingsController.php' => 'Controller/Admin',
    'Controller/SmsDataController.php' => 'Controller/Admin',
    'Controller/SmsTemplateAdminController.php' => 'Controller/Admin',
    'Controller/StaffController.php' => 'Controller/Admin',
    'Controller/SystemUpdateController.php' => 'Controller/Admin',
    'Controller/ThemeController.php' => 'Controller/Admin',
    'Controller/TransactionController.php' => 'Controller/Admin',

    // Controllers -> Api
    'Controller/CompanionApiController.php' => 'Controller/Api',
    'Controller/Frontend/ApiController.php' => 'Controller/Api',
    'Controller/Frontend/IpnController.php' => 'Controller/Api',

    // Controllers -> Checkout
    'Controller/CheckoutController.php' => 'Controller/Checkout',
    'Controller/Frontend/PaymentCheckoutController.php' => 'Controller/Checkout',
    'Controller/Frontend/PaymentLinkCheckoutController.php' => 'Controller/Checkout',
    'Controller/Frontend/InvoiceCheckoutController.php' => 'Controller/Checkout',

    // Services -> Auth
    'Service/AuthSessionService.php' => 'Service/Auth',
    'Service/JwtService.php' => 'Service/Auth',
    'Service/PermissionGuard.php' => 'Service/Auth',
    'Service/PermissionService.php' => 'Service/Auth',
    'Service/StatusGuard.php' => 'Service/Auth',

    // Services -> Payment
    'Service/PaymentService.php' => 'Service/Payment',
    'Service/GatewayApiService.php' => 'Service/Payment',
    'Service/GatewayRendererService.php' => 'Service/Payment',
    'Service/TransactionService.php' => 'Service/Payment',
    'Service/LedgerService.php' => 'Service/Payment',
    'Service/ReconciliationService.php' => 'Service/Payment',
    'Service/SettlementService.php' => 'Service/Payment',
    'Service/DisputeService.php' => 'Service/Payment',
    'Service/MfsService.php' => 'Service/Payment',
    'Service/WebhookService.php' => 'Service/Payment',
    'Service/IdempotencyBridge.php' => 'Service/Payment',
    'Service/IdempotencyService.php' => 'Service/Payment',
    'Service/CurrencyService.php' => 'Service/Payment',

    // Services -> Sms
    'Service/SmsParserService.php' => 'Service/Sms',
    'Service/SmsHeuristicParser.php' => 'Service/Sms',
    'Service/SmsRegexParser.php' => 'Service/Sms',

    // Services -> Notification
    'Service/NotificationService.php' => 'Service/Notification',
    'Service/MobileNotificationService.php' => 'Service/Notification',
    'Service/AlertService.php' => 'Service/Notification',

    // Services -> System
    'Service/CrudService.php' => 'Service/System',
    'Service/EnvironmentService.php' => 'Service/System',
    'Service/HttpClient.php' => 'Service/System',
    'Service/FilesystemService.php' => 'Service/System',
    'Service/PaginationService.php' => 'Service/System',
    'Service/DateTimeService.php' => 'Service/System',
    'Service/UpdaterService.php' => 'Service/System',
    'Service/ImageService.php' => 'Service/System',
    'Service/PdfService.php' => 'Service/System',
    'Service/InputSanitizer.php' => 'Service/System',
    'Service/Logger.php' => 'Service/System',
    'Service/AuditLogger.php' => 'Service/System',

    // Services -> Device
    'Service/DevicePairingService.php' => 'Service/Device',

    // Services -> Customer
    'Service/CustomerPiiService.php' => 'Service/Customer',
    'Service/ApiKeyService.php' => 'Service/Customer',

    // Services -> Plugin
    'Service/PluginManager.php' => 'Plugin',
];

// Execute file moves
$replacements = [];

foreach ($moves as $file => $destDir) {
    if (file_exists("$src/$file")) {
        $basename = basename($file);
        rename("$src/$file", "$src/$destDir/$basename");
        
        $oldClass = 'OwnPay\\' . str_replace('/', '\\', str_replace('.php', '', $file));
        // Remove Frontend\ if it was there
        $oldClass = str_replace('\\Frontend\\', '\\', $oldClass);
        $newClass = 'OwnPay\\' . str_replace('/', '\\', $destDir) . '\\' . str_replace('.php', '', $basename);
        
        $replacements[$oldClass] = $newClass;
        echo "Moved $file to $destDir/$basename\n";
    }
}

// Map the old namespaces to new namespaces for search/replace
$namespaceReplacements = [];
foreach ($replacements as $oldClass => $newClass) {
    // If the old class was "OwnPay\Controller\AuthController", we want to replace uses of it.
    $namespaceReplacements[$oldClass] = $newClass;
}

function processDirectory($dir, $namespaceReplacements) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->isDir()) continue;
        if ($file->getExtension() !== 'php') continue;

        $content = file_get_contents($file->getPathname());
        $changed = false;

        // Fix namespace declaration in the moved files
        foreach ($namespaceReplacements as $oldClass => $newClass) {
            $oldNs = substr($oldClass, 0, strrpos($oldClass, '\\'));
            $newNs = substr($newClass, 0, strrpos($newClass, '\\'));
            $className = substr($oldClass, strrpos($oldClass, '\\') + 1);
            
            // Only update namespace if this file IS the class
            if ($file->getBasename() === $className . '.php') {
                $content = preg_replace('/namespace ' . preg_quote($oldNs, '/') . ';/', 'namespace ' . $newNs . ';', $content, 1, $count);
                if ($count > 0) $changed = true;
                
                // If it was in Frontend, the namespace in file might have been Controller\Frontend
                $content = preg_replace('/namespace OwnPay\\\\Controller\\\\Frontend;/', 'namespace ' . $newNs . ';', $content, 1, $count);
                if ($count > 0) $changed = true;
            }

            // Update use statements
            $content = preg_replace('/use ' . preg_quote($oldClass, '/') . ';/', 'use ' . $newClass . ';', $content, -1, $count);
            if ($count > 0) $changed = true;

            // Update inline references like \OwnPay\Controller\AuthController
            $content = preg_replace('/' . preg_quote('\\' . $oldClass, '/') . '([^a-zA-Z0-9_])/', '\\\\' . $newClass . '$1', $content, -1, $count);
            if ($count > 0) $changed = true;
            
            // Update inline references like OwnPay\Controller\AuthController
            $content = preg_replace('/(?<![a-zA-Z0-9_\\\\])' . preg_quote($oldClass, '/') . '([^a-zA-Z0-9_])/', $newClass . '$1', $content, -1, $count);
            if ($count > 0) $changed = true;
        }

        if ($changed) {
            file_put_contents($file->getPathname(), $content);
        }
    }
}

// Run on src and app directories and tests
processDirectory(__DIR__ . '/../src', $namespaceReplacements);
processDirectory(__DIR__ . '/../app', $namespaceReplacements);
if (is_dir(__DIR__ . '/../tests')) {
    processDirectory(__DIR__ . '/../tests', $namespaceReplacements);
}
// Also process index.php
$indexContent = file_get_contents(__DIR__ . '/../index.php');
foreach ($namespaceReplacements as $oldClass => $newClass) {
    $indexContent = preg_replace('/' . preg_quote('\\' . $oldClass, '/') . '([^a-zA-Z0-9_])/', '\\\\' . $newClass . '$1', $indexContent);
}
file_put_contents(__DIR__ . '/../index.php', $indexContent);

echo "Namespace replacement complete.\n";

// cleanup old dirs
@rmdir("$src/Controller/Frontend");

