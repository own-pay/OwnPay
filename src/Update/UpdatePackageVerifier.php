<?php

declare(strict_types=1);

namespace OwnPay\Update;

/**
 * Verifies update package archives before any extraction happens.
 *
 * Two schemes exist:
 *
 * - v1 (legacy): RSA-SHA256 signature over sha256(version.json). The payload
 *   files are NOT authenticated. v1 is accepted only for packages that carry
 *   no executable/runtime content.
 * - ownpay-update-v2 (current): RSA-SHA256 signature over sha256(version.json)
 *   where version.json ALSO lists a `files` map of every packaged file and its
 *   SHA-256 digest, the package name, an issued-at timestamp, the signing key
 *   id and the target version. Every file in the archive must be listed, every
 *   listed file must be present, every hash must match, and the manifest's
 *   signature must verify against a trusted key id.
 *
 * Anti-rollback: a package whose version is lower than the installed version is
 * rejected by default (caller passes $installedVersion).
 *
 * Key rotation: packages carry `key_id`; the trust store maps key ids to PEM
 * public keys. Rotate by (1) generating the new key offline (openssl genrsa),
 * (2) embedding its public key as a new `ownpay-update-v<N>` entry, then
 * (3) signing future packages with the new key. See docs/UPDATING.md. The
 * v1 key is retained for continuity with the 0.3.0 bridge release.
 */
final class UpdatePackageVerifier
{
    /**
     * Embedded trust store: key id => PEM public key.
     *
     * `ownpay-update-v2-1` is the v2 signing key, generated offline by the owner
     * and provided to the release pipeline as tools/ownpay-update-v2-pub.pem. The
     * corresponding private key is held offline (or in CI secrets); only the public
     * key is embedded here.
     *
     * `ownpay-update-v1` is retained solely so the 0.3.0 bridge release can verify
     * legacy non-code packages. Executable packages must carry the v2 scheme.
     *
     * @var array<string, string>
     */
    private const TRUSTED_KEYS = [
        'ownpay-update-v1' => <<<'EOT'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzBVsd2Yd/xqMD00Dts/e
        OuSIjjYab3fRqEtRaPf9cAl0iFRR+o7RGloz6dh6M2trswiKx2s2mN4+JPL604Z/
        K86OxhovINo8KT4kQ3Tisq9uQ7J7x5YieoLfj4YpWwSv643Vw4QYMDagsMntXgbo
        ZnfuQ3Dk7EgeZ4/8psHN/SGb8E7/JyQRwQhNFpOOO++25kR/4fKm9kHiOH8URoYi
        gbp/HC6oTH5ObtTMwaXFk7ZHMyh6iHmYv4cLZtJR+/Xpkb1d5gz7IcsTklJPXSja
        a8U63KZm/fnwYBsV4JdX2qTPfZLSGhL7vEHA5U1y617RGdT3WaShURvv2o4eiyBb
        FQIDAQAB
        -----END PUBLIC KEY-----
        EOT,
        'ownpay-update-v2-1' => <<<'EOT'
        -----BEGIN PUBLIC KEY-----
        MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA1zdGVMkWLcYTD5tWQAkv
        RUjAbzPMY038LapWPAEp/vN+6UJ33ljhRV756jYLmUcb8Kc50HMC6oym7ovAtyis
        A1YZbkcXAQ1Wlo8wtFFe+TtKyyyy3wKegLYR329jYEMIEEyoyMnHc7NwCBSIiYEE
        jQCDEOF6W4KISu+GIfnQ3mz7eNfye2hZZK8hnhhvb6ps8JM6Uc4e3sXT8F0BEgls
        ni8q1iVWJGMIJQg1qm35hfpJ8SIIGp06X+/MXoytdOo+UdViUgbaC8kWHC5wLW2k
        c06V0mWMonIxga+SqN94Bh9ehU6zZbXw4QeRmwNYo9WXHbZjd8d03Q+IMgYuXp7T
        o51ReN3oBqFv8IQL6GpuH/khcSsFSTWDH6cREFNIP0oL7mFi9VIABuT7VKDaAo/Q
        WUkW/yQdPthewTQ4H3e4tS6agG/h75M41cihXp6WJFTaJwnkkJSoK+zBmbhparnU
        hGsNmlYwME8QoecLMB/+VWwkuXQuIdCyxKedwO4cDxZLpz/YDBJgZwjcKa0j16dL
        N8hedsBP/hfr5Jr7xrd1wgKC5p78WbBPgm6HOn9lOm2EsLIu5Jfy4Dow64uXTn9y
        EQqrZslmRsQPGFzc82uqpUo4TpS4glxcFNg1sCVEsPgHibTrniVn5XqlXjElZjv2
        abFXrDMQTV5GbBrLMDw2hcUCAwEAAQ==
        -----END PUBLIC KEY-----
        EOT,
    ];

    /**
     * File extensions that make a package executable at runtime or in the
     * browser. Packages containing any of these MUST carry a v2 signature.
     *
     * @var array<int, string>
     */
    private const EXECUTABLE_EXTENSIONS = [
        'php', 'phtml', 'phar', 'inc',
        'twig', 'sql',
        'js', 'mjs', 'cjs', 'htm', 'html', 'svg', 'xml',
        'sh', 'bash', 'py', 'pl', 'cgi',
    ];

    /**
     * Extensionless fixed-name executables (server config / environment).
     *
     * @var array<int, string>
     */
    private const EXECUTABLE_FILENAMES = [
        '.htaccess', '.user.ini', '.env',
    ];

    /**
     * @var array<string, string>
     */
    private array $trustedKeys;

    /**
     * @param array<string, string>|null $trustedKeys Trust store override (tests); null uses the embedded store.
     */
    public function __construct(?array $trustedKeys = null)
    {
        /** @var array<string, string> $keys */
        $keys = $trustedKeys ?? self::TRUSTED_KEYS;
        $this->trustedKeys = $keys;
    }

    /**
     * True when any entry carries executable/runtime content.
     *
     * An unreadable archive is treated as executable (fail closed).
     */
    public function containsExecutableContent(string $tmpPath): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return true;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $base = basename($name);
                if ($base === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $lowerBase = strtolower($base);
                if (in_array($lowerBase, self::EXECUTABLE_FILENAMES, true)) {
                    return true;
                }
                if (str_ends_with($lowerBase, '.htaccess') || str_ends_with($lowerBase, '.user.ini')) {
                    return true;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($ext !== '' && in_array($ext, self::EXECUTABLE_EXTENSIONS, true)) {
                    return true;
                }
            }
        } finally {
            $zip->close();
        }
        return false;
    }

    /**
     * Verifies the package and returns its metadata.
     *
     * @param string $tmpPath         Path to the uploaded/downloaded archive.
     * @param bool   $requiresV2      True when code-bearing content must be v2-signed.
     * @param string $installedVersion Installed OwnPay version, for the anti-rollback floor.
     * @return array{version: string, scheme: 'v1'|'v2', key_id: string|null}
     * @throws \RuntimeException When the package fails any verification step.
     */
    public function verify(string $tmpPath, bool $requiresV2, string $installedVersion): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new \RuntimeException('Cannot open update package.');
        }

        $manifestIndex = $zip->locateName('version.json');
        if ($manifestIndex === false) {
            $zip->close();
            throw new \RuntimeException('Package is missing a valid version.json manifest.');
        }
        $manifestBytes = $zip->getFromIndex($manifestIndex);
        if (!is_string($manifestBytes) || $manifestBytes === '') {
            $zip->close();
            throw new \RuntimeException('Package version.json manifest is empty.');
        }
        $signatureIndex = $zip->locateName('signature.sig');
        $zip->close();

        /** @var array<string, mixed>|null $meta */
        $meta = json_decode($manifestBytes, true);
        if (!is_array($meta)) {
            throw new \RuntimeException('Package version.json manifest is not valid JSON.');
        }
        $versionRaw = $meta['version'] ?? null;
        $version = is_string($versionRaw) && $versionRaw !== '' ? $versionRaw : null;
        if ($version === null) {
            throw new \RuntimeException('Package is missing a valid version.json manifest.');
        }

        // Anti-rollback: never apply a package older than what is installed.
        if (version_compare($version, $installedVersion, '<')) {
            throw new \RuntimeException(
                "Refusing to install version {$version} - it is older than the installed version {$installedVersion}. Downgrades require an explicit admin override."
            );
        }

        $scheme = (($meta['scheme'] ?? null) === 'ownpay-update-v2') ? 'v2' : 'v1';

        if ($requiresV2 && $scheme === 'v1') {
            throw new \RuntimeException(
                'This package contains executable content and must be signed with the v2 scheme. ' .
                'Unsigned or legacy-v1 code packages are rejected.'
            );
        }

        if ($scheme === 'v2') {
            return ['version' => $version, 'scheme' => 'v2', 'key_id' => $this->verifyV2($tmpPath, $manifestBytes, $meta)];
        }

        // v1: legacy signature over sha256(version.json), optional for non-code packages.
        $keyId = $this->verifyV1($tmpPath, $manifestBytes, $signatureIndex);
        return ['version' => $version, 'scheme' => 'v1', 'key_id' => $keyId];
    }

    /**
     * v1 verification: signature optional (returns null when absent), throws on invalid.
     */
    private function verifyV1(string $tmpPath, string $manifestBytes, int|false $signatureIndex): ?string
    {
        if ($signatureIndex === false || !function_exists('openssl_verify')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            return null;
        }
        $signatureBase64 = $zip->getFromIndex((int) $signatureIndex);
        $zip->close();
        if (!is_string($signatureBase64) || trim($signatureBase64) === '') {
            throw new \RuntimeException('Package signature.sig is empty. Invalid signature format.');
        }
        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || $signature === '') {
            throw new \RuntimeException('Package signature.sig is not valid base64.');
        }
        $signedPayload = hash('sha256', $manifestBytes);

        // v1 used a single embedded key with no key id; verify against every trusted entry.
        $matchedKeyId = null;
        foreach ($this->trustedKeys as $keyId => $pem) {
            $pubKey = openssl_pkey_get_public($pem);
            if ($pubKey === false) {
                continue;
            }
            if (openssl_verify($signedPayload, $signature, $pubKey, OPENSSL_ALGO_SHA256) === 1) {
                $matchedKeyId = $keyId;
                break;
            }
        }
        if ($matchedKeyId === null) {
            throw new \RuntimeException('Signature verification failed: signature does not match any trusted key.');
        }
        return $matchedKeyId;
    }

    /**
     * v2 verification: manifest lists every file; every file hash must match.
     *
     * @param array<string, mixed> $meta Decoded manifest array.
     */
    private function verifyV2(string $tmpPath, string $manifestBytes, array $meta): string
    {
        $packageRaw = $meta['package'] ?? null;
        if (!is_string($packageRaw) || $packageRaw !== 'ownpay') {
            throw new \RuntimeException('v2 manifest must name the package ("package": "ownpay").');
        }

        $issuedAtRaw = $meta['issued_at'] ?? null;
        if (!is_string($issuedAtRaw) || $issuedAtRaw === '') {
            throw new \RuntimeException('v2 manifest is missing "issued_at".');
        }

        $keyIdRaw = $meta['key_id'] ?? null;
        $keyId = is_string($keyIdRaw) && $keyIdRaw !== '' ? $keyIdRaw : null;
        if ($keyId === null) {
            throw new \RuntimeException('v2 manifest is missing "key_id".');
        }
        $pem = $this->trustedKeys[$keyId] ?? null;
        if ($pem === null) {
            throw new \RuntimeException("v2 manifest names untrusted key_id '{$keyId}'.");
        }

        $filesRaw = $meta['files'] ?? null;
        if (!is_array($filesRaw) || $filesRaw === []) {
            throw new \RuntimeException('v2 manifest is missing the "files" map.');
        }
        /** @var array<string, string> $files */
        $files = [];
        foreach ($filesRaw as $name => $hash) {
            if (!is_string($name) || $name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                throw new \RuntimeException('v2 manifest lists an unsafe file path.');
            }
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new \RuntimeException('v2 manifest lists an invalid SHA-256 digest for ' . $name . '.');
            }
            $files[$name] = $hash;
        }

        // Every entry in the archive (except root metadata) must be listed, and vice versa.
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new \RuntimeException('Cannot open update package for verification.');
        }
        try {
            $archived = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                if ($name === 'version.json' || $name === 'signature.sig') {
                    continue; // root metadata, covered by the manifest signature itself
                }
                $archived[] = $name;
                $hash = hash_init('sha256');
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new \RuntimeException('Cannot read file from package: ' . $name);
                }
                try {
                    while (!feof($stream)) {
                        $hashed = fread($stream, 65536);
                        if ($hashed === false) {
                            throw new \RuntimeException('Cannot read file from package: ' . $name);
                        }
                        hash_update($hash, $hashed);
                    }
                } finally {
                    fclose($stream);
                }
                $digest = hash_final($hash);
                $expected = $files[$name] ?? null;
                unset($files[$name]);
                if ($expected === null) {
                    throw new \RuntimeException('Package contains a file not listed in the signed manifest: ' . $name);
                }
                if (!hash_equals($expected, $digest)) {
                    throw new \RuntimeException('File does not match the signed manifest digest: ' . $name);
                }
            }
            if ($files !== []) {
                $missing = implode(', ', array_keys($files));
                throw new \RuntimeException('Signed manifest lists files missing from the package: ' . $missing);
            }
        } finally {
            $zip->close();
        }

        // Signature over the manifest itself.
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new \RuntimeException('Cannot open update package for signature check.');
        }
        try {
            $signatureIndex = $zip->locateName('signature.sig');
            $signatureBase64 = $signatureIndex === false ? false : $zip->getFromIndex($signatureIndex);
        } finally {
            $zip->close();
        }
        if (!is_string($signatureBase64) || trim($signatureBase64) === '') {
            throw new \RuntimeException('v2 package is missing a signature.sig.');
        }
        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || $signature === '') {
            throw new \RuntimeException('Package signature.sig is not valid base64.');
        }
        $pubKey = openssl_pkey_get_public($pem);
        if ($pubKey === false) {
            throw new \RuntimeException("Failed to load embedded public key for key_id '{$keyId}'.");
        }
        $verifyResult = openssl_verify(hash('sha256', $manifestBytes), $signature, $pubKey, OPENSSL_ALGO_SHA256);
        if ($verifyResult !== 1) {
            $err = ($verifyResult === 0) ? 'Signature mismatch' : 'OpenSSL verification error (' . openssl_error_string() . ')';
            throw new \RuntimeException("Signature verification failed: {$err}.");
        }

        return $keyId;
    }
}
