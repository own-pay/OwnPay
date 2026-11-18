<?php

declare(strict_types=1);

/**
 * Signs an OwnPay update package in the ownpay-update-v2 scheme.
 *
 * RUNBOOK (see docs/UPDATING.md):
 *   1. On an ONLINE vendor machine, build the release zip:
 *        ownpay-X.Y.Z.zip with paths under src/ config/ templates/ public/ database/ modules/
 *   2. Transfer the unsigned zip to the OFFLINE signing machine (USB stick, never network).
 *   3. On the OFFLINE machine, with the signing private key available as a local file:
 *        php tools/sign-update-package.php ownpay-X.Y.Z.zip --version=X.Y.Z --key-id=ownpay-update-v2-1
 *   4. Transfer the signed zip back; publish it with the release manifest (version, download_url,
 *      checksum_sha256, signature, min_php).
 *
 * The output overwrites `version.json` and `signature.sig` inside the ZIP in-place.
 * Existing entries are replaced; every other file is hashed into the manifest as-is.
 *
 * Nothing here reaches the network. The private key never leaves the offline machine.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool runs on the CLI only.\n");
    exit(1);
}

if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php tools/sign-update-package.php <package.zip> --version=X.Y.Z [--key-id=ownpay-update-v2-1] [--package=ownpay]\n");
    fwrite(STDERR, "Private key: set OWNPAY_UPDATE_PRIVATE_KEY to the key path, or pass --key-file=<path>.\n");
    exit(1);
}

$zipPath = $argv[1];
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.+)$/', $arg, $m) !== 1) {
        fwrite(STDERR, "Unrecognized argument: {$arg}\n");
        exit(1);
    }
    $options[$m[1]] = $m[2];
}

$version = $options['version'] ?? null;
$keyId = $options['key-id'] ?? 'ownpay-update-v2-1';
$package = $options['package'] ?? 'ownpay';
$keyFile = $options['key-file'] ?? (getenv('OWNPAY_UPDATE_PRIVATE_KEY') ?: null);

if ($version === null || !preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
    fwrite(STDERR, "--version must look like X.Y.Z (got: " . ($version ?? 'missing') . ")\n");
    exit(1);
}
if (!is_file($zipPath)) {
    fwrite(STDERR, "File not found: {$zipPath}\n");
    exit(1);
}
if (!is_string($keyFile) || !is_file($keyFile)) {
    fwrite(STDERR, "Private key not found. Set OWNPAY_UPDATE_PRIVATE_KEY or pass --key-file=<path>.\n");
    exit(1);
}
$keyContents = file_get_contents($keyFile);
if ($keyContents === false) {
    fwrite(STDERR, "Cannot read private key: {$keyFile}\n");
    exit(1);
}
$privateKey = openssl_pkey_get_private($keyContents);
if ($privateKey === false) {
    fwrite(STDERR, "Cannot parse private key (expected unencrypted RSA PEM): {$keyFile}\n");
    fwrite(STDERR, "Unencrypted PKCS#8/PKCS#1 RSA keys are supported. Encrypted keys are not.\n");
    exit(1);
}
$keyDetails = openssl_pkey_get_details($privateKey);
$bits = is_array($keyDetails) && isset($keyDetails['bits']) ? (int) $keyDetails['bits'] : 0;
if ($bits < 2048) {
    fwrite(STDERR, "Refusing to sign: RSA key is smaller than 2048 bits ({$bits}).\n");
    exit(1);
}

// Directories the release pipeline is allowed to ship.
$allowedDirectories = ['src', 'config', 'templates', 'public', 'database', 'modules'];

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "Cannot open {$zipPath}\n");
    exit(1);
}

$files = [];
$forbidden = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (!is_string($name) || $name === '' || str_ends_with($name, '/')) {
        continue;
    }
    if ($name === 'version.json' || $name === 'signature.sig') {
        continue; // replaced below
    }
    if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
        fwrite(STDERR, "Unsafe path in package: {$name}\n");
        exit(1);
    }
    $first = explode('/', $name)[0];
    if (!in_array($first, $allowedDirectories, true)) {
        fwrite(STDERR, "Package contains a file outside approved directories: {$name}\n");
        $forbidden++;
    }
    $files[$name] = hash('sha256', (string) $zip->getFromIndex($i));
}
if ($forbidden > 0) {
    $zip->close();
    fwrite(STDERR, "{$forbidden} disallowed file(s). Re-pack the release before signing.\n");
    exit(1);
}
$zip->close();

$manifest = [
    'scheme'    => 'ownpay-update-v2',
    'version'   => $version,
    'package'   => $package,
    'issued_at' => gmdate('c'),
    'key_id'    => $keyId,
    'files'     => $files,
];
$manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (!is_string($manifestBytes)) {
    fwrite(STDERR, "Failed to encode the manifest JSON.\n");
    exit(1);
}

$signedPayload = hash('sha256', $manifestBytes);
$signature = '';
if (openssl_sign($signedPayload, $signature, $privateKey, OPENSSL_ALGO_SHA256) === false) {
    fwrite(STDERR, "Signing failed: " . openssl_error_string() . "\n");
    exit(1);
}

// Reopen in read-write mode: existing entries are preserved. Avoid OVERWRITE here,
// it truncates the archive and would drop the payload files.
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "Cannot reopen {$zipPath} for writing.\n");
    exit(1);
}
// Replace root metadata entries in-place so they do not linger as stale duplicates.
$zip->deleteName('version.json');
$zip->deleteName('signature.sig');
$zip->addFromString('version.json', $manifestBytes);
$zip->addFromString('signature.sig', base64_encode($signature));
$zip->close();

$manifestSha = hash('sha256', $manifestBytes);
// Reuse the same digest for the manifest signature and for display.
$verifyPayload = hash('sha256', file_get_contents($zipPath));
fwrite(STDERR, "Signed package: {$zipPath}\n");
fwrite(STDERR, "  version      : {$version}\n");
fwrite(STDERR, "  package      : {$package}\n");
fwrite(STDERR, "  files hashed : " . count($files) . "\n");
fwrite(STDERR, "  key_id       : {$keyId}\n");
fwrite(STDERR, "  manifest sha : {$manifestSha}\n");
fwrite(STDERR, "  zip sha256   : {$verifyPayload}\n");
fwrite(STDERR, "\nNext: publish the zip and reference it in the release manifest (checksum_sha256, signature).\n");
exit(0);
