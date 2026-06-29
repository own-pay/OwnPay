<?php
/**
 * OwnPay Update Server - Manifest Generator
 *
 * Place this in the update server root alongside your releases/ directory.
 * Run: php generate_manifest.php
 *
 * It scans releases/ and builds manifest.json automatically.
 *
 * Directory structure expected:
 *   releases/
 *     0.1.0/
 *       ownpay-0.1.0.zip
 *       checksum.sha256
 *       changelog.md
 *       release.json        ← Per-release metadata
 *     0.2.0/
 *       ownpay-0.2.0.zip
 *       checksum.sha256
 *       changelog.md
 *       release.json
 */

$baseUrl = 'https://update.ownpay.org';
$releasesDir = __DIR__ . '/releases';
$outputFile = __DIR__ . '/manifest.json';

// Scan release directories
$releases = [];
$dirs = glob($releasesDir . '/*', GLOB_ONLYDIR);
sort($dirs); // Oldest first

foreach ($dirs as $dir) {
    $version = basename($dir);
    $zipFile = $dir . '/ownpay-' . $version . '.zip';
    $checksumFile = $dir . '/checksum.sha256';
    $changelogFile = $dir . '/changelog.md';
    $metaFile = $dir . '/release.json';

    if (!file_exists($zipFile)) {
        echo "SKIP: {$version} - no ZIP found\n";
        continue;
    }

    // Read checksum
    $checksum = '';
    if (file_exists($checksumFile)) {
        $raw = trim(file_get_contents($checksumFile));
        $checksum = explode(' ', $raw)[0]; // sha256sum format: "hash  filename"
    }

    // Read changelog
    $changelog = '';
    if (file_exists($changelogFile)) {
        $changelog = file_get_contents($changelogFile);
    }

    // Read release metadata
    $meta = [];
    if (file_exists($metaFile)) {
        $meta = json_decode(file_get_contents($metaFile), true) ?: [];
    }

    // Detect channel from version string
    $isBeta = str_contains($version, 'beta') || str_contains($version, 'alpha') || str_contains($version, 'rc');
    $channel = $isBeta ? 'beta' : 'stable';

    // Count migrations
    $migrations = [];
    $migDir = $dir . '/migrations';
    if (is_dir($migDir)) {
        $migFiles = glob($migDir . '/*.sql');
        $migrations = array_map('basename', $migFiles ?: []);
        sort($migrations);
    }

    $releases[] = [
        'version'        => $version,
        'version_code'   => preg_replace('/-(alpha|beta|rc)\d*$/', '', $version),
        'channel'        => $channel,
        'download_url'   => "{$baseUrl}/releases/{$version}/ownpay-{$version}.zip",
        'checksum_url'   => "{$baseUrl}/releases/{$version}/checksum.sha256",
        'checksum_sha256'=> $checksum,
        'changelog_url'  => "{$baseUrl}/releases/{$version}/changelog.md",
        'changelog'      => $changelog,
        'release_date'   => $meta['release_date'] ?? date('Y-m-d', filemtime($zipFile)),
        'size_bytes'     => filesize($zipFile),
        'min_php_version'=> $meta['min_php_version'] ?? '8.2.0',
        'min_ownpay_version' => $meta['min_ownpay_version'] ?? null,
        'migrations'     => $migrations,
        'breaking_changes' => $meta['breaking_changes'] ?? false,
        'breaking_notes' => $meta['breaking_notes'] ?? null,
    ];

    echo "OK: {$version} ({$channel}) - " . number_format(filesize($zipFile)) . " bytes\n";
}

// Find latest per channel
$channels = [];
foreach (['stable', 'beta'] as $ch) {
    $channelReleases = array_filter($releases, fn($r) => $r['channel'] === $ch);
    if (empty($channelReleases)) {
        continue;
    }

    // Sort by version descending
    usort($channelReleases, fn($a, $b) => version_compare($b['version_code'], $a['version_code']));
    $latest = $channelReleases[0];

    $channels[$ch] = [
        'latest_version_name'  => $latest['version'],
        'latest_version_code'  => $latest['version_code'],
        'min_php_version'      => $latest['min_php_version'],
        'min_ownpay_version'   => $latest['min_ownpay_version'],
        'download_url'         => $latest['download_url'],
        'checksum_url'         => $latest['checksum_url'],
        'checksum_sha256'      => $latest['checksum_sha256'],
        'signature_url'        => null,
        'changelog_url'        => $latest['changelog_url'],
        'changelog'            => $latest['changelog'],
        'release_date'         => $latest['release_date'],
        'size_bytes'           => $latest['size_bytes'],
        'migrations'           => $latest['migrations'],
        'breaking_changes'     => $latest['breaking_changes'],
        'breaking_notes'       => $latest['breaking_notes'],
    ];
}

// Build manifest
$manifest = [
    'schema_version' => 1,
    'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
    'channels'       => $channels,
    'announcements'  => [],
    'public_key_url' => null,
];

// Also add backward-compat fields for UpdateService::check() which reads top-level
// version, download_url, changelog
if (isset($channels['stable'])) {
    $manifest['version']      = $channels['stable']['latest_version_name'];
    $manifest['download_url'] = $channels['stable']['download_url'];
    $manifest['changelog']    = $channels['stable']['changelog'];
}

file_put_contents($outputFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\nManifest written to: {$outputFile}\n";
echo "Channels: " . implode(', ', array_keys($channels)) . "\n";
