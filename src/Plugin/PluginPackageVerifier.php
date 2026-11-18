<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Plugin\PluginManifest;

/**
 * Verifies signed plugin packages distributed through plugins.ownpay.org.
 *
 * A signed registry package is a directory (already extracted from the release
 * bundle) containing manifest.json + signature.sig and whose manifest declares:
 *
 * - slug, version
 * - issued_at (ISO-8601 timestamp)
 * - key_id (identifies which trusted key signed the manifest)
 * - package_sha256 (SHA-256 of every file in the package, sorted by path,
 *   excluding the manifest itself and signature.sig)
 *
 * Verification steps:
 *
 * 1. Parse manifest.json.
 * 2. Recompute package_sha256 from the bytes on disk.
 * 3. Signature (RSA-SHA256 over sha256(manifest bytes)) must validate against the
 *    key identified by key_id.
 * 4. Slug in the manifest must equal the directory name (protects against
 *    "renaming a trusted package" surprises).
 * 5. Downgrade is rejected: the package version may not be older than the
 *    currently installed/deployed version (caller passes installedVersion).
 *
 * Trust store: separate from the app update trust store on purpose. A registry
 * key compromise must not imply the ability to sign core updates, and vice versa.
 * The interim bootstrap reuses the owner's v1 update key until the registry key
 * ceremony creates 'ownpay-plugin-registry-v2'; rotate thereafter by adding a new
 * entry and keeping the old one for legacy packages.
 *
 * Tier resolution: a package with a valid signature is 'official' when the
 * manifest tier claims it (OwnPay-owned packages) or 'verified' for vetted
 * community packages (manifest tier 'verified'); anything else is 'unverified'.
 */
final class PluginPackageVerifier
{
    /**
     * Embedded plugin registry trust store: key id => PEM public key.
     *
     * 'ownpay-plugin-registry-v1' is the registry signing key, generated offline by the
     * owner and provided to the release pipeline as tools/ownpay-plugin-registry-pub.pem.
     * The corresponding private key never exists on this machine, in this repository, or
     * in CI (it is held offline); only the public key is embedded here. Registry-signed
     * packages verify exactly against this key.
     *
     * @var array<string, string>
     */
    private const TRUSTED_KEYS = [
        'ownpay-plugin-registry-v1' => <<<'EOT'
        -----BEGIN PUBLIC KEY-----
        MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAzphZPPsZLuA4SBcVLHNu
        WR3zdv+fYwsiIV7lgOSdZATHZphlmKfNuDmn3/UazUwROPY2s+1hXFKgIBYk30x5
        J0guPuAXm1ylCGyQksH35p9Ldwuz23kyUtRA7Rxsogi/5lD1f596BP9bYCKQwMwZ
        KlcylVNANeCa6wyZm2xv/5W+UnelYtW+jsIstgjyAPXXAOr3VCfW2yWFIq0JPyni
        2FGGOEgTRbn3OgiDmNW65FOhsYn96PKymKfwqHvWhWwU/VZq81WpHKSsaxierT7/
        VE9nw5Z1pHzK7ff51UMqQEOhiLAJUCU7hAOCRF+CIBdeJMunsNoCFcLhsq5PVc5Z
        OiIpn7VSwXjczGpmDw7jc6qZEQd7Yh3UyhuvVbxnEB1xOe7S0ExuGVqYSUIw0Thk
        nBmzmZHOs0PK0CrnCc2MOKvAeNfF0Do3ojo32x29cuBtFopl8CoTcPsaVP98DaT0
        UCy1M3nPWMDt9f2GIi9w1JWk2JKCanqCqWMHQHU/6lauXTkkks3XOGF7dD8xfT35
        r79kGB6X+yCfWpkJx8qWsfY6i92dGh6y+uj0y26x9vWf8u6oDJXWIxyKiIjcIKYg
        8Rt2z40SAz6VTNPr2Q0YZJfugE7U/M+OcJCsf7EpnxkLsrEdD45ZL3TUHsW3WFdR
        V9gkY72HZSa/CrTl8ATZnXkCAwEAAQ==
        -----END PUBLIC KEY-----
        EOT,
    ];

    /** @var array<string, string> */
    private array $trustedKeys;

    /**
     * @param array<string, string>|null $trustedKeys Test-only override; production passes null.
     */
    public function __construct(?array $trustedKeys = null)
    {
        /** @var array<string, string> $keys */
        $keys = $trustedKeys ?? self::TRUSTED_KEYS;
        $this->trustedKeys = $keys;
    }

    /**
     * The trust tier of a directory on disk.
     *
     * @return string 'official' | 'verified' | 'unverified'
     */
    public function tierFor(string $pluginDir): string
    {
        $signatureBytes = @file_get_contents($pluginDir . '/signature.sig');
        if (!is_string($signatureBytes) || trim($signatureBytes) === '') {
            // Nothing to verify; the package is unverified by definition.
            return 'unverified';
        }

        $manifestBytes = @file_get_contents($pluginDir . '/manifest.json');
        if (!is_string($manifestBytes) || $manifestBytes === '') {
            return 'unverified';
        }

        try {
            $manifest = $this->verifyPackageBytes($pluginDir, $manifestBytes, null);
        } catch (\Throwable) {
            return 'unverified';
        }

        $tier = $manifest['tier'];
        return in_array($tier, ['official', 'verified'], true) ? $tier : 'unverified';
    }

    /**
     * Verifies an extracted plugin directory against the registry signature and,
     * when a currently installed version is supplied, enforces the anti-downgrade rule.
     *
     * @param string $pluginDir Extracted plugin directory (must contain manifest.json + signature.sig).
     * @param string|null $installedVersion Currently installed version, or null to skip the downgrade check.
     * @return array{version: string, tier: 'official'|'verified'|'unverified', key_id: string, slug: string}
     * @throws \RuntimeException When any verification step fails.
     */
    public function verifyPackage(string $pluginDir, ?string $installedVersion = null): array
    {
        $manifestBytes = @file_get_contents($pluginDir . '/manifest.json');
        if (!is_string($manifestBytes) || $manifestBytes === '') {
            throw new \RuntimeException('Plugin package is missing manifest.json.');
        }

        $manifest = $this->verifyPackageBytes($pluginDir, $manifestBytes, $installedVersion);
        $tier = $manifest['tier'];
        return [
            'version' => (string) $manifest['version'],
            'tier'    => in_array($tier, ['official', 'verified'], true) ? $tier : 'unverified',
            'key_id'  => (string) $manifest['key_id'],
            'slug'    => (string) $manifest['slug'],
        ];
    }

    /**
     * @return array{version: string, slug: string, key_id: string, tier: string}
     */
    private function verifyPackageBytes(string $pluginDir, string $manifestBytes, ?string $installedVersion): array
    {
        /** @var array<string, mixed>|null $manifest */
        $manifest = json_decode($manifestBytes, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Plugin manifest.json is not valid JSON.');
        }

        $slugRaw = $manifest['slug'] ?? null;
        $versionRaw = $manifest['version'] ?? null;
        $issuedRaw = $manifest['issued_at'] ?? null;
        $keyIdRaw = $manifest['key_id'] ?? null;
        $packageHashRaw = $manifest['package_sha256'] ?? null;
        $tierRaw = $manifest['tier'] ?? '';

        if (!is_string($slugRaw) || $slugRaw === ''
            || !is_string($versionRaw) || $versionRaw === ''
            || !is_string($issuedRaw) || $issuedRaw === ''
            || !is_string($keyIdRaw) || $keyIdRaw === ''
            || !is_string($packageHashRaw) || preg_match('/^[a-f0-9]{64}$/', $packageHashRaw) !== 1) {
            throw new \RuntimeException('Plugin manifest is missing required fields (slug, version, issued_at, key_id, package_sha256).');
        }

        $directoryName = basename(rtrim($pluginDir, '/'));
        if ($directoryName !== $slugRaw) {
            throw new \RuntimeException("Manifest slug '{$slugRaw}' does not match directory '{$directoryName}'.");
        }

        $trusted = $this->trustedKeys[$keyIdRaw] ?? null;
        if ($trusted === null) {
            throw new \RuntimeException("Plugin manifest names untrusted key_id '{$keyIdRaw}'.");
        }

        // Recompute the whole-package digest over every entry except the manifest
        // itself and the signature file.
        $digestEntries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO)
        );
        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $fileInfo->getPathname()), strlen(str_replace('\\', '/', $pluginDir)));
            $relative = ltrim($relative, '/');
            if ($relative === 'manifest.json' || $relative === 'signature.sig') {
                continue;
            }
            $contents = file_get_contents($fileInfo->getPathname());
            if ($contents === false) {
                throw new \RuntimeException('Cannot read plugin file: ' . $relative);
            }
            $digestEntries[$relative] = hash('sha256', $contents);
        }
        ksort($digestEntries);
        $canonical = '';
        foreach ($digestEntries as $path => $digest) {
            $canonical .= $path . ":" . $digest . "\n";
        }
        $computed = hash('sha256', $canonical);
        if (!hash_equals($packageHashRaw, $computed)) {
            throw new \RuntimeException('Package contents do not match the manifest digest (files were modified after signing).');
        }

        // Signature over the manifest bytes themselves.
        $signatureBytes = @file_get_contents($pluginDir . '/signature.sig');
        if (!is_string($signatureBytes) || trim($signatureBytes) === '') {
            throw new \RuntimeException('Plugin package is missing a signature.sig.');
        }
        $signature = base64_decode((string) trim($signatureBytes), true);
        if ($signature === false || $signature === '') {
            throw new \RuntimeException('Plugin signature.sig is not valid base64.');
        }

        $publicKey = openssl_pkey_get_public($trusted);
        if ($publicKey === false) {
            throw new \RuntimeException('Failed to load the embedded public key for the registry.');
        }
        $ok = openssl_verify(hash('sha256', $manifestBytes), $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            $errText = $ok === 0 ? 'Signature mismatch' : 'OpenSSL verification error (' . openssl_error_string() . ')';
            throw new \RuntimeException("Plugin signature verification failed: {$errText}.");
        }

        // Anti-downgrade: a registry package may never resurrect an older version.
        if (is_string($installedVersion) && $installedVersion !== ''
            && version_compare($versionRaw, $installedVersion, '<')) {
            throw new \RuntimeException(
                "Refusing to install plugin version {$versionRaw} - it is older than the installed version {$installedVersion}."
            );
        }

        return [
            'version' => $versionRaw,
            'slug'    => $slugRaw,
            'key_id'  => $keyIdRaw,
            'tier'    => is_string($tierRaw) && in_array($tierRaw, ['official', 'verified'], true) ? $tierRaw : 'unverified',
        ];
    }
}
