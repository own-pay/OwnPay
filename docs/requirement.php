<?php
declare(strict_types=1);

/**
 * OwnPay Server Requirements Checker
 * 
 * Standalone diagnostic tool to verify system compatibility for OwnPay.
 * Works via CLI (php requirement.php) and Web (example.com/docs/requirement.php).
 * 
 * @package OwnPay
 * @version 1.0.0
 */

// 1. Resolve Application Root Directory
$rootDir = __DIR__;
if (basename($rootDir) === 'docs') {
    $rootDir = dirname($rootDir);
}

// 2. Perform Checks
$envPath = $rootDir . '/.env';
$envWritable = is_writable($rootDir) || (file_exists($envPath) && is_writable($envPath));
$storageDir = $rootDir . '/storage';
$storageWritable = is_dir($storageDir) && is_writable($storageDir);
$publicDir = $rootDir . '/public';
$publicWritable = is_dir($publicDir) && is_writable($publicDir);

$checks = [
    'php_version' => [
        'name' => 'PHP Version',
        'required' => '>= 8.3',
        'current' => PHP_VERSION,
        'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'desc' => 'OwnPay is engineered for PHP 8.3+ to leverage strict types, typing performance, and security enhancements.'
    ],
    'pdo_mysql' => [
        'name' => 'PDO MySQL Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('pdo_mysql'),
        'desc' => 'Required to communicate securely with MySQL/MariaDB database layers using prepared statements.'
    ],
    'curl' => [
        'name' => 'cURL Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('curl') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('curl'),
        'desc' => 'Used to execute outbound API calls, webhook dispatches, and communicate with payment gateway APIs.'
    ],
    'openssl' => [
        'name' => 'OpenSSL Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('openssl') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('openssl'),
        'desc' => 'Critical cryptographic library for signature validations, JWT encoding, and HTTPS payloads.'
    ],
    'mbstring' => [
        'name' => 'Mbstring Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('mbstring') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('mbstring'),
        'desc' => 'Required for multibyte string manipulation and secure localization (i18n) handling.'
    ],
    'json' => [
        'name' => 'JSON Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('json') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('json'),
        'desc' => 'Required for payload parsing, database JSON generated columns, and REST API communications.'
    ],
    'bcmath' => [
        'name' => 'BCMath Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('bcmath') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('bcmath'),
        'desc' => 'Handles arbitrary-precision financial calculations to prevent floating-point rounding leakage.'
    ],
    'fileinfo' => [
        'name' => 'Fileinfo Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('fileinfo') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('fileinfo'),
        'desc' => 'Used to validate MIME types of gateway attachments and logo uploads securely.'
    ],
    'gd' => [
        'name' => 'GD Library Extension',
        'required' => 'Enabled',
        'current' => extension_loaded('gd') ? 'Enabled' : 'Disabled',
        'ok' => extension_loaded('gd'),
        'desc' => 'Required for on-the-fly QR code rendering (e.g. for Manual MFS payments) and logo cropping.'
    ],
    'writable_env' => [
        'name' => 'Writable Root Directory (.env)',
        'required' => 'Writable',
        'current' => $envWritable ? 'Writable' : 'Read-Only',
        'ok' => $envWritable,
        'desc' => 'The installer must be able to write the initial .env parameters to configure app settings.'
    ],
    'writable_storage' => [
        'name' => 'Writable Storage Directory',
        'required' => 'Writable',
        'current' => $storageWritable ? 'Writable' : 'Read-Only',
        'ok' => $storageWritable,
        'desc' => 'Required to save runtime logs, session caches, and the .installed marker block.'
    ],
    'writable_public' => [
        'name' => 'Writable Public Directory',
        'required' => 'Writable',
        'current' => $publicWritable ? 'Writable' : 'Read-Only',
        'ok' => $publicWritable,
        'desc' => 'Required for plugin managers to copy gateway asset badges and icons during dynamic resolution.'
    ],
    'composer_vendor' => [
        'name' => 'Composer Vendor Directory',
        'required' => 'Exists',
        'current' => is_dir($rootDir . '/vendor') ? 'Exists' : 'Missing',
        'ok' => is_dir($rootDir . '/vendor'),
        'desc' => 'Contains PSR-4 autoloaded dependencies. Run `composer install` to compile the vendor directory.'
    ]
];

$allPassed = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $allPassed = false;
    }
}

// 3. Render Output based on Interface SAPI
if (PHP_SAPI === 'cli') {
    renderCli($checks, $allPassed);
} else {
    renderWeb($checks, $allPassed);
}

/**
 * Render diagnostic results formatted for Command Line Interface (CLI).
 * 
 * @param array $checks Checked requirements.
 * @param bool $allPassed Flag indicating if all requirements are met.
 */
function renderCli(array $checks, bool $allPassed): void {
    $hasColors = DIRECTORY_SEPARATOR === '/' || getenv('ANSICON') !== false || getenv('TERM') !== false;
    $green = $hasColors ? "\033[32m" : "";
    $red = $hasColors ? "\033[31m" : "";
    $yellow = $hasColors ? "\033[33m" : "";
    $reset = $hasColors ? "\033[0m" : "";
    $bold = $hasColors ? "\033[1m" : "";

    echo "\n";
    echo "  ____                  _____             \n";
    echo " / __ \ _      ______  / ___/____ _____  \n";
    echo "/ / / / | /| / / __ \ \__ \/ __ `/ __ \ \n";
    echo "/ /_/ /| |/ |/ / / / /___/ / /_/ / /_/ / \n";
    echo "\____/ |__/|__/_/ /_//____/\__,_/ .___/  \n";
    echo "                               /_/       \n";
    echo "  System Requirements Diagnostics Tool\n";
    echo "  ====================================\n\n";

    $mask = "  %-35s | %-12s | %-12s | %s\n";
    printf($bold . $mask . $reset, "Requirement", "Required", "Current", "Status");
    printf("  %s\n", str_repeat("-", 75));

    foreach ($checks as $key => $c) {
        $statusText = $c['ok'] ? "{$green}[PASS]{$reset}" : "{$red}[FAIL]{$reset}";
        printf($mask, $c['name'], $c['required'], $c['current'], $statusText);
    }
    printf("  %s\n\n", str_repeat("-", 75));

    if ($allPassed) {
        echo "  {$green}{$bold}✓ EXCELLENT: Your server meets all required specifications to install OwnPay!{$reset}\n\n";
    } else {
        echo "  {$red}{$bold}✗ WARNING: One or more critical system parameters are missing or read-only.{$reset}\n";
        echo "  Please check the guide below to resolve these issues:\n\n";
        
        echo "  === RESOLUTION GUIDE ===\n";
        echo "  * cPanel:\n";
        echo "    1. Log in -> Software -> Select PHP Version.\n";
        echo "    2. Set PHP version to 8.3 or higher.\n";
        echo "    3. Go to 'Extensions' tab and check missing modules (e.g. bcmath, pdo_mysql, mbstring).\n\n";
        echo "  * Ubuntu / Debian VPS Server:\n";
        echo "    Run: sudo apt update && sudo apt install php8.3-bcmath php8.3-mysql php8.3-curl php8.3-mbstring php8.3-gd\n";
        echo "    Restart FPM/Web server: sudo systemctl restart php8.3-fpm\n\n";
        echo "  * Laragon (Local):\n";
        echo "    Right-click tray icon -> PHP -> Extensions -> Check missing modules -> Reload.\n\n";
    }
}

/**
 * Render diagnostic results inside a premium, responsive Web Page.
 * 
 * @param array $checks Checked requirements.
 * @param bool $allPassed Flag indicating if all requirements are met.
 */
function renderWeb(array $checks, bool $allPassed): void {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OwnPay System Requirements Diagnostics</title>
    <!-- Premium Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --op-bg: #f8fafc;
            --op-card-bg: #ffffff;
            --op-text: #0f172a;
            --op-text-muted: #64748b;
            --op-border: #e2e8f0;
            --op-primary: #4f46e5;
            --op-primary-light: #e0e7ff;
            --op-success: #10b981;
            --op-success-light: #d1fae5;
            --op-danger: #f43f5e;
            --op-danger-light: #ffe4e6;
            --op-warning: #f59e0b;
            --op-shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --op-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --op-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--op-bg);
            color: var(--op-text);
            line-height: 1.6;
            padding: 40px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        .op-container {
            width: 100%;
            max-width: 800px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* ── Header Branding ── */
        .op-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--op-card-bg);
            padding: 20px 32px;
            border-radius: 16px;
            border: 1px solid var(--op-border);
            box-shadow: var(--op-shadow-sm);
        }

        .op-logo-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .op-logo-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.4rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }

        .op-logo-text {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--op-text);
        }

        .op-logo-text span {
            color: var(--op-primary);
            font-weight: 800;
        }

        .op-badge-pill {
            background-color: var(--op-bg);
            border: 1px solid var(--op-border);
            padding: 6px 12px;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--op-text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .op-badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--op-primary);
        }

        /* ── Main Diagnostics Card ── */
        .op-card {
            background-color: var(--op-card-bg);
            border-radius: 20px;
            border: 1px solid var(--op-border);
            padding: 36px;
            box-shadow: var(--op-shadow-md);
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        .op-card-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .op-card-header p {
            color: var(--op-text-muted);
            font-size: 0.95rem;
        }

        /* ── Status Banner ── */
        .op-banner {
            padding: 20px 24px;
            border-radius: 12px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            font-size: 0.92rem;
        }

        .op-banner-success {
            background-color: var(--op-success-light);
            border-left: 5px solid var(--op-success);
            color: #065f46;
        }

        .op-banner-danger {
            background-color: var(--op-danger-light);
            border-left: 5px solid var(--op-danger);
            color: #9f1239;
        }

        .op-banner-icon {
            font-size: 1.4rem;
            line-height: 1;
        }

        .op-banner-body h3 {
            font-weight: 700;
            margin-bottom: 4px;
            font-size: 1rem;
        }

        /* ── Requirement List Table ── */
        .op-req-table {
            width: 100%;
            border-collapse: collapse;
        }

        .op-req-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--op-text-muted);
            border-bottom: 2px solid var(--op-border);
            font-weight: 700;
        }

        .op-req-row {
            border-bottom: 1px solid var(--op-border);
            transition: background-color 0.15s ease;
        }

        .op-req-row:hover {
            background-color: #f8fafc;
        }

        .op-req-row td {
            padding: 16px;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .op-req-name-cell {
            font-weight: 600;
        }

        .op-req-info {
            display: block;
            font-size: 0.78rem;
            color: var(--op-text-muted);
            margin-top: 4px;
            font-weight: 400;
        }

        .op-req-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
            line-height: 1;
        }

        .op-req-badge-ok {
            background-color: var(--op-success-light);
            color: #047857;
        }

        .op-req-badge-fail {
            background-color: var(--op-danger-light);
            color: #b91c1c;
        }

        /* ── Accordion Panels ── */
        .op-guide-section h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--op-text);
        }

        .op-accordion {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .op-accordion-item {
            border: 1px solid var(--op-border);
            border-radius: 12px;
            background: var(--op-card-bg);
            overflow: hidden;
            box-shadow: var(--op-shadow-sm);
        }

        .op-accordion-header {
            width: 100%;
            padding: 16px 20px;
            background: none;
            border: none;
            text-align: left;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--op-text);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background-color 0.15s ease;
        }

        .op-accordion-header:hover {
            background-color: #f8fafc;
        }

        .op-accordion-chevron {
            width: 16px;
            height: 16px;
            transition: transform 0.2s ease;
            color: var(--op-text-muted);
        }

        .op-accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease-out;
            border-top: 0 solid var(--op-border);
        }

        .op-accordion-body {
            padding: 20px;
            font-size: 0.9rem;
            color: #334155;
            background: #fafaf9;
        }

        .op-accordion-body ol {
            padding-left: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .op-accordion-body code {
            font-family: monospace;
            background-color: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.82rem;
            color: #0f172a;
        }

        .op-accordion-body pre {
            background-color: #0f172a;
            color: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            overflow-x: auto;
            margin-top: 8px;
            font-size: 0.8rem;
        }

        /* JavaScript controlled active state for accordion */
        .op-accordion-item.active .op-accordion-content {
            max-height: 500px;
            border-top: 1px solid var(--op-border);
        }

        .op-accordion-item.active .op-accordion-chevron {
            transform: rotate(180deg);
        }

        /* ── Footer Branding & Links ── */
        .op-footer {
            text-align: center;
            padding: 40px 20px;
            border-top: 1px solid var(--op-border);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            margin-top: 20px;
        }

        .op-footer-branding {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--op-text-muted);
        }

        .op-footer-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 16px 24px;
        }

        .op-footer-link {
            font-size: 0.85rem;
            color: var(--op-text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.15s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .op-footer-link:hover {
            color: var(--op-primary);
        }

        .op-footer-copyright {
            font-size: 0.8rem;
            color: var(--op-text-muted);
            margin-top: 10px;
        }

        @media (max-width: 640px) {
            body {
                padding: 16px 12px;
            }

            .op-header {
                padding: 16px 20px;
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }

            .op-card {
                padding: 20px;
            }

            .op-req-table th {
                padding: 8px;
            }

            .op-req-row td {
                padding: 12px 8px;
            }

            .op-req-table th:nth-child(2),
            .op-req-table td:nth-child(2) {
                display: none; /* Hide 'Required' column on mobile */
            }

            .op-footer-links {
                gap: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="op-container">
        <!-- ── Header ── -->
        <header class="op-header">
            <a href="https://ownpay.org" class="op-logo-wrap" target="_blank" rel="noopener">
                <img src="https://ownpay.org/ownpay_logo.png" alt="OwnPay Logo" style="height: 36px; max-width: 100%; object-fit: contain;">
            </a>
            <div class="op-badge-pill">
                <span class="op-badge-dot"></span>
                <span>System Diagnostic Tool</span>
            </div>
        </header>

        <!-- ── Main Card ── -->
        <main class="op-card">
            <div class="op-card-header">
                <h1>Server Compatibility Check</h1>
                <p>Verify that your server environment meets the requirements to install OwnPay safely.</p>
            </div>

            <!-- Status Banner -->
            <?php if ($allPassed): ?>
                <div class="op-banner op-banner-success">
                    <span class="op-banner-icon">✓</span>
                    <div class="op-banner-body">
                        <h3>All Requirements Met</h3>
                        <p>Your server is fully optimized and ready to run OwnPay! You can proceed with the standard installation wizard.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="op-banner op-banner-danger">
                    <span class="op-banner-icon">✗</span>
                    <div class="op-banner-body">
                        <h3>Action Required</h3>
                        <p>One or more system parameters require adjustment. Please review the failures in the list below and use the resolution guide to enable them.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Table of Parameters -->
            <table class="op-req-table">
                <thead>
                    <tr>
                        <th>Requirement</th>
                        <th>Required</th>
                        <th>Current</th>
                        <th style="text-align: right;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $c): ?>
                        <tr class="op-req-row">
                            <td class="op-req-name-cell">
                                <?= htmlspecialchars($c['name']) ?>
                                <span class="op-req-info"><?= htmlspecialchars($c['desc']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($c['required']) ?></td>
                            <td><?= htmlspecialchars($c['current']) ?></td>
                            <td style="text-align: right;">
                                <?php if ($c['ok']): ?>
                                    <span class="op-req-badge op-req-badge-ok">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="display: block; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg>
                                        <span>Pass</span>
                                    </span>
                                <?php else: ?>
                                    <span class="op-req-badge op-req-badge-fail">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="display: block; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        <span>Fail</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Resolution Guides Accordion -->
            <div class="op-guide-section">
                <h2>Control Panel Resolution Guides</h2>
                <div class="op-accordion">
                    <!-- cPanel -->
                    <div class="op-accordion-item">
                        <button class="op-accordion-header">
                            <span>How to enable extensions in <strong>cPanel</strong></span>
                            <svg class="op-accordion-chevron" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="op-accordion-content">
                            <div class="op-accordion-body">
                                <ol>
                                    <li>Log into your <strong>cPanel Dashboard</strong>.</li>
                                    <li>Search for and click on <strong>Select PHP Version</strong> (under the <em>Software</em> section).</li>
                                    <li>Ensure your PHP version is set to <strong>8.3</strong> or higher.</li>
                                    <li>Click on the <strong>Extensions</strong> tab at the top.</li>
                                    <li>Locate the missing extensions (e.g. <code>bcmath</code>, <code>pdo_mysql</code>, <code>gd</code>, <code>mbstring</code>) in the list and check their boxes to enable them. Changes are saved automatically.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- Plesk -->
                    <div class="op-accordion-item">
                        <button class="op-accordion-header">
                            <span>How to enable extensions in <strong>Plesk</strong></span>
                            <svg class="op-accordion-chevron" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="op-accordion-content">
                            <div class="op-accordion-body">
                                <ol>
                                    <li>Log into your <strong>Plesk Control Panel</strong>.</li>
                                    <li>Go to <strong>Websites & Domains</strong> and click on <strong>PHP Settings</strong> for the target domain.</li>
                                    <li>Ensure the PHP version is set to <strong>8.3</strong> or higher.</li>
                                    <li>Find the required modules in the extensions list and check the checkboxes next to them.</li>
                                    <li>Scroll down and click <strong>OK</strong> or <strong>Apply</strong> to restart PHP and load the extensions.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- Laragon -->
                    <div class="op-accordion-item">
                        <button class="op-accordion-header">
                            <span>How to enable extensions in <strong>Laragon (Local)</strong></span>
                            <svg class="op-accordion-chevron" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="op-accordion-content">
                            <div class="op-accordion-body">
                                <ol>
                                    <li>Locate the <strong>Laragon icon</strong> in your Windows System Tray (taskbar bottom-right).</li>
                                    <li>Right-click the icon to open the Laragon menu.</li>
                                    <li>Hover over <strong>PHP</strong> -> <strong>Extensions</strong>.</li>
                                    <li>Find the disabled extension (e.g. <code>bcmath</code>) and click on it. A checkmark will appear next to it.</li>
                                    <li>Click <strong>Stop</strong>, then <strong>Start All</strong> on the Laragon control panel to reload the services.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- VPS CLI -->
                    <div class="op-accordion-item">
                        <button class="op-accordion-header">
                            <span>How to install extensions on a <strong>VPS Server (Ubuntu/Debian)</strong></span>
                            <svg class="op-accordion-chevron" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="op-accordion-content">
                            <div class="op-accordion-body">
                                <ol>
                                    <li>Connect to your VPS server via SSH.</li>
                                    <li>Install the missing extensions using the aptitude repository manager:
                                        <pre>sudo apt update
sudo apt install php8.3-mysql php8.3-curl php8.3-mbstring php8.3-bcmath php8.3-gd php8.3-xml php8.3-zip</pre>
                                    </li>
                                    <li>Restart PHP-FPM or Apache to apply the changes:
                                        <pre># If using Nginx
sudo systemctl restart php8.3-fpm

# If using Apache
sudo systemctl restart apache2</pre>
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Installation Guide Section -->
            <div class="op-installation-section" style="margin-top: 32px; border-top: 1px solid var(--op-border); padding-top: 32px; display: flex; flex-direction: column; gap: 12px;">
                <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 700; color: var(--op-text);">How to Install OwnPay</h2>
                <p style="color: var(--op-text-muted); font-size: 0.92rem;">Once all server specifications and database credentials are met, you can initiate the multi-step installation wizard by visiting your domain's setup utility. To read our complete deployment walk-through, please visit our user guide:</p>
                <div>
                    <a href="https://learn.ownpay.org/user_guide/installation" class="op-btn-install" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 8px; background-color: var(--op-primary); color: #ffffff; padding: 12px 24px; border-radius: 10px; font-weight: 600; text-decoration: none; font-size: 0.9rem; transition: background-color 0.15s ease, transform 0.15s ease; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);">
                        <span>Installation</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>
            </div>
        </main>

        <!-- ── Footer ── -->
        <footer class="op-footer">
            <div class="op-footer-branding">
                <img src="https://ownpay.org/ownpay_logo.png" alt="OwnPay Logo" style="height: 24px; max-width: 100%; object-fit: contain;">
            </div>
            <div class="op-footer-links">
                <a href="https://ownpay.org" class="op-footer-link" target="_blank" rel="noopener">Home Page</a>
                <a href="https://github.com/own-pay/OwnPay" class="op-footer-link" target="_blank" rel="noopener">GitHub</a>
                <a href="https://youtube.com/@ownpayorg" class="op-footer-link" target="_blank" rel="noopener">YouTube</a>
                <a href="https://learn.ownpay.org" class="op-footer-link" target="_blank" rel="noopener">Learn & Guide</a>
                <a href="https://blog.ownpay.org" class="op-footer-link" target="_blank" rel="noopener">Blog</a>
                <a href="https://docs.ownpay.org" class="op-footer-link" target="_blank" rel="noopener">Documentation</a>
            </div>
            <div class="op-footer-copyright">
                &copy; <?= date('Y') ?> OwnPay Project. Released under the AGPL v3.0 License.
            </div>
        </footer>
    </div>

    <!-- Interactive Accordion Logic -->
    <script>
        document.querySelectorAll('.op-accordion-header').forEach(button => {
            button.addEventListener('click', () => {
                const accordionItem = button.parentElement;
                const isActive = accordionItem.classList.contains('active');
                
                // Close all other items
                document.querySelectorAll('.op-accordion-item').forEach(item => {
                    item.classList.remove('active');
                });

                // Open current item if it wasn't active
                if (!isActive) {
                    accordionItem.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>
    <?php
}
