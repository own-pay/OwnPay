<?php

declare(strict_types=1);

/**
 * Signs an OwnPay plugin registry package (the format verified by PluginPackageVerifier).
 *
 * RUNBOOK (see docs/PLUGINS.md):
 *   1. Build the plugin directory on the ONLINE machine (dir named exactly like the slug,
 *      containing manifest.json and every file the plugin ships).
 *   2. Copy the directory to the OFFLINE signing machine.
 *   3. On the OFFLINE machine, with the registry private key as a local file:
 *        php tools/sign-plugin-package.php <plugin-dir> --version=1.2.0 --slug=my-plugin [--key-id=ownpay-plugin-registry-v1] [--tier=official|verified]
 *   4. Copy the directory back and zip it FOR THE REGISTRY (the directory name must equal
 *      the slug at pack time).
 *
 * The output rewrites manifest.json and signature.sig inside the directory in-place.
 * The digest covers every file except manifest.json and signature.sig themselves, mirroring
 * PluginPackageVerifier::verifyPackageBytes().
 *
 * Nothing here reaches the network. The private key never leaves the offline machine.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool runs on the CLI only.\n");
    exit(1);
}

if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php tools/sign-plugin-package.php <plugin-dir> --version=X.Y.Z [--slug=NAME] [--key-id=ownpay-plugin-registry-v1] [--tier=official|verified] [--key-file=<path>]\n");
    fwrite(STDERR, "Private key: OWNPAY_PLUGIN_PRIVATE_KEY environment variable, or --key-file=<path>.\n");
    exit(1);
}

$pluginDir = rtrim($argv[1], "/");
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.+)$/', $arg, $m) !== 1) {
        fwrite(STDERR, "Unrecognized argument: {$arg}\n");
        exit(1);
    }
    $options[$m[1]] = $m[2];
}

$version = $options['version'] ?? null;
$keyId = $options['key-id'] ?? 'ownpay-plugin-registry-v1';
$tier = $options['tier'] ?? 'verified';
$slug = $options['slug'] ?? basename($pluginDir);
$keyFile = $options['key-file'] ?? (getenv('OWNPAY_PLUGIN_PRIVATE_KEY') ?: null);

if ($version === null || !preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
    fwrite(STDERR, "--version must look like X.Y.Z (got: " . ($version ?? 'missing') . ")\n");
    exit(1);
}
if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug)) {
    fwrite(STDERR, "--slug must be a lowercase kebab/slug id (got: {$slug})\n");
    exit(1);
}
if (!in_array($tier, ['official', 'verified'], true)) {
    fwrite(STDERR, "--tier must be 'official' or 'verified'\n");
    exit(1);
}
if (!is_dir($pluginDir)) {
    fwrite(STDERR, "Directory not found: {$pluginDir}\n");
    exit(1);
}
if (basename($pluginDir) !== $slug) {
    fwrite(STDERR, "Directory name ('" . basename($pluginDir) . "') must equal the slug ('{$slug}').\n");
    exit(1);
}
if (!is_string($keyFile) || !is_file($keyFile)) {
    fwrite(STDERR, "Private key not found. Set OWNPAY_PLUGIN_PRIVATE_KEY or pass --key-file=<path>.\n");
    exit(1);
}
$keyContents = file_get_contents($keyFile);
$privateKey = is_string($keyContents) ? openssl_pkey_get_private($keyContents) : false;
if ($privateKey === false) {
    fwrite(STDERR, "Cannot parse private key (expected unencrypted RSA PEM): {$keyFile}\n");
    exit(1);
}
$keyDetails = openssl_pkey_get_details($privateKey);
$bits = is_array($keyDetails) && isset($keyDetails['bits']) ? (int) $keyDetails['bits'] : 0;
if ($bits < 2048) {
    fwrite(STDERR, "Refusing to sign: RSA key is smaller than 2048 bits ({$bits}).\n");
    exit(1);
}

$digests = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
);
$root = str_replace('\\', '/', realpath($pluginDir) ?: $pluginDir);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $relative = substr(str_replace('\\', '/', $fileInfo->getPathname()), strlen($root));
    $relative = ltrim($relative, '/');
    if ($relative === 'manifest.json' || $relative === 'signature.sig') {
        continue;
    }
    $contents = file_get_contents($fileInfo->getPathname());
    if ($contents === false) {
        fwrite(STDERR, "Cannot read plugin file: {$relative}\n");
        exit(1);
    }
    $digests[$relative] = hash('sha256', $contents);
}
ksort($digests);
$canonical = '';
foreach ($digests as $path => $digest) {
    $canonical .= $path . ":" . $digest . "\n";
}
$packageHash = hash('sha256', $canonical);
$manifest = [
    'name'           => $slug,
    'version'        => $version,
    'slug'           => $slug,
    'issued_at'      => gmdate('c'),
    'key_id'         => $keyId,
    'package_sha256' => $packageHash,
    'tier'           => $tier,
];
$manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($manifestBytes)) {
    fwrite(STDERR, "Failed to encode the manifest JSON.\n");
    exit(1);
}
$signature = '';
if (openssl_sign(hash('sha256', $manifestBytes), $signature, $privateKey, OPENSSL_ALGO_SHA256) === false) {
    fwrite(STDERR, "Signing failed: " . (openssl_error_string() ?: 'unknown error') . "\n");
    exit(1);
}
// Written exactly as signed: any extra byte (even a trailing newline) changes the
// manifest digest that was signed and the package will fail verification.
if (file_put_contents($pluginDir . '/manifest.json', $manifestBytes) === false) {
    fwrite(STDERR, "Cannot write manifest.json\n");
    exit(1);
}
if (file_put_contents($pluginDir . '/signature.sig', base64_encode($signature)) === false) {
    fwrite(STDERR, "Cannot write signature.sig\n");
    exit(1);
}

fwrite(STDERR, "Signed plugin package: {$pluginDir}\n");
fwrite(STDERR, "  slug         : {$slug}\n");
fwrite(STDERR, "  version      : {$version}\n");
fwrite(STDERR, "  tier         : {$tier}\n");
fwrite(STDERR, "  files hashed : " . count($digests) . "\n");
fwrite(STDERR, "  key_id       : {$keyId}\n");
fwrite(STDERR, "  package sha  : {$packageHash}\n");
fwrite(STDERR, "\nNext: zip the directory as {$slug}.zip and transfer it to the registry.\n");
exit(0);
