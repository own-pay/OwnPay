<?php
declare(strict_types=1);

/**
 * OwnPay Daily Cron Sitemap Generator
 * File: cron/sitemap.php
 */

require_once dirname(__DIR__) . '/config/config.php';

try {
    $sitemapFile = PUBLIC_PATH . '/sitemap.xml';
    $callbackUrl = APP_URL . '/sitemap.xml';

    $ch = curl_init($callbackUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);
    
    $xml = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($xml)) {
        throw new RuntimeException("HTTP request failed with status: {$httpCode}");
    }

    file_put_contents($sitemapFile, $xml);
    echo "Sitemap successfully generated at: {$sitemapFile}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Sitemap Generation Error: " . $e->getMessage() . "\n");
    exit(1);
}
