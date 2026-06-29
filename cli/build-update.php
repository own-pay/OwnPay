<?php
declare(strict_types=1);

/**
 * OwnPay Self-Update Release Generator
 *
 * Interactive PHP CLI tool to package releases, update the manifest,
 * copy migrations, run composer update, and output server-ready assets.
 */

// Define project paths
$projectRoot = dirname(__DIR__);
$updateDir = $projectRoot . '/update';
$releasesDir = $updateDir . '/releases';

// Set up output styling helpers
define('CLI_RESET', "\033[0m");
define('CLI_BOLD', "\033[1m");
define('CLI_GREEN', "\033[32m");
define('CLI_RED', "\033[31m");
define('CLI_YELLOW', "\033[33m");
define('CLI_BLUE', "\033[34m");
define('CLI_CYAN', "\033[36m");

function printHeader(): void
{
    echo CLI_CYAN . CLI_BOLD;
    echo "========================================================\n";
    echo "             OwnPay Self-Update Builder CLI             \n";
    echo "========================================================\n" . CLI_RESET;
}

function printStep(string $title): void
{
    echo "\n" . CLI_BLUE . CLI_BOLD . "=== " . $title . " ===" . CLI_RESET . "\n";
}

function cleanInput(string $input): string
{
    // Strip UTF-8 BOM if present
    if (str_starts_with($input, "\xEF\xBB\xBF")) {
        $input = substr($input, 3);
    }
    // Remove Zero-Width spaces, Joiners, Non-Joiners, and BOM
    $input = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $input);
    // Remove control characters (0-31 and 127 ASCII)
    $input = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $input);
    return trim($input);
}

function prompt(string $message, ?string $default = null): string
{
    $promptMsg = CLI_BOLD . $message . CLI_RESET;
    if ($default !== null) {
        $promptMsg .= " [" . CLI_GREEN . $default . CLI_RESET . "]";
    }
    $promptMsg .= ": ";
    echo $promptMsg;
    
    $rawInput = fgets(STDIN);
    $input = cleanInput($rawInput === false ? '' : $rawInput);
    
    if ($input === '' && $default !== null) {
        return $default;
    }
    return $input;
}

function confirm(string $message, bool $default = true): bool
{
    $choices = $default ? 'Y/n' : 'y/N';
    echo CLI_BOLD . "{$message}" . CLI_RESET . " (" . CLI_YELLOW . $choices . CLI_RESET . "): ";
    
    $rawInput = fgets(STDIN);
    $input = strtolower(cleanInput($rawInput === false ? '' : $rawInput));
    
    if ($input === '') {
        return $default;
    }
    return $input === 'y' || $input === 'yes';
}

/**
 * @param array<int, string> $options
 */
function selectOption(string $message, array $options, int $default = 1): int
{
    echo CLI_BOLD . $message . CLI_RESET . "\n";
    foreach ($options as $key => $val) {
        echo "  [" . CLI_GREEN . $key . CLI_RESET . "] {$val}\n";
    }
    while (true) {
        $input = prompt("Select option", (string)$default);
        $choice = (int)$input;
        if (isset($options[$choice])) {
            return $choice;
        }
        echo CLI_RED . "Invalid selection. Please try again." . CLI_RESET . "\n";
    }
}

// -------------------------------------------------------------
// 1. Manage Signing Keys
// -------------------------------------------------------------
printHeader();
printStep("Verifying Cryptographic Signing Keys");

$privateKeyPath = $projectRoot . '/update/update_private_key.pem';
$publicKeyPath = $projectRoot . '/update/update_public_key.pem';
$privateKey = null;

if (!file_exists($privateKeyPath)) {
    echo CLI_YELLOW . "No update signing private key found at {$privateKeyPath}." . CLI_RESET . "\n";
    if (confirm("Would you like to generate a new 2048-bit RSA key pair for signing updates?", true)) {
        echo "Generating key pair... ";
        
        $opensslConfig = null;
        $possiblePaths = [
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/ssl/openssl.cnf',
            dirname(PHP_BINARY) . '/openssl.cnf',
            'C:/laragon/bin/git/usr/ssl/openssl.cnf',
            'C:/laragon/bin/git/mingw64/etc/ssl/openssl.cnf',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $opensslConfig = $path;
                break;
            }
        }

        $options = [
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        if ($opensslConfig !== null) {
            $options["config"] = $opensslConfig;
        }

        $res = openssl_pkey_new($options);
        if ($res === false) {
            echo CLI_RED . "Failed to generate key pair!" . CLI_RESET . "\n";
            exit(1);
        }
        openssl_pkey_export($res, $privateKeyContent, null, $options);
        $details = openssl_pkey_get_details($res);
        if ($details === false) {
            echo CLI_RED . "Failed to extract public key!" . CLI_RESET . "\n";
            exit(1);
        }
        $publicKeyContent = $details["key"];

        file_put_contents($privateKeyPath, $privateKeyContent);
        file_put_contents($publicKeyPath, $publicKeyContent);
        
        echo CLI_GREEN . "Success!" . CLI_RESET . "\n\n";
        echo CLI_BOLD . "=== IMPORTANT SECURITY NOTICE ===" . CLI_RESET . "\n";
        echo "A new Private/Public key pair has been saved to your project root.\n";
        echo "1. " . CLI_CYAN . "update_private_key.pem" . CLI_RESET . " (KEEP THIS PRIVATE! Do not commit or share it)\n";
        echo "2. " . CLI_CYAN . "update_public_key.pem" . CLI_RESET . " (Share this or bundle it in client/UpdateService.php)\n\n";
        echo CLI_YELLOW . "To enable cryptographic verification, copy this Public Key and paste it into " . CLI_BOLD . "src/Update/UpdateService.php" . CLI_RESET . CLI_YELLOW . " as the UPDATE_PUBLIC_KEY constant:" . CLI_RESET . "\n\n";
        echo CLI_CYAN . $publicKeyContent . CLI_RESET . "\n";
        prompt("Press Enter once you have copied/noted the public key to continue");
        $privateKey = $privateKeyContent;
    } else {
        echo CLI_RED . "Warning: Updates cannot be cryptographically signed without a private key." . CLI_RESET . "\n";
        if (!confirm("Do you want to continue packaging without signing?", false)) {
            exit(1);
        }
    }
} else {
    echo CLI_GREEN . "Signing key update_private_key.pem found." . CLI_RESET . "\n";
    $privateKey = file_get_contents($privateKeyPath);
}

// -------------------------------------------------------------
// 2. Run Composer Update
// -------------------------------------------------------------
printStep("Running Composer Update");

echo "Updating composer packages to ensure all dependencies are up to date...\n";
// Run composer update and passthrough stdout/stderr directly
$composerCommand = 'composer update';
echo CLI_YELLOW . "Executing: {$composerCommand}" . CLI_RESET . "\n\n";

passthru($composerCommand, $returnVar);

if ($returnVar !== 0) {
    echo CLI_RED . "\nComposer update failed with exit code {$returnVar}.\n" . CLI_RESET;
    if (!confirm("Do you want to ignore this error and continue packaging?", false)) {
        exit(1);
    }
} else {
    echo CLI_GREEN . "\nComposer update completed successfully.\n" . CLI_RESET;
}

// -------------------------------------------------------------
// 2. Resolve Versions
// -------------------------------------------------------------
printStep("Configuring Release Version");

$configAppPath = $projectRoot . '/config/app.php';
if (!file_exists($configAppPath)) {
    echo CLI_RED . "Error: config/app.php not found!" . CLI_RESET . "\n";
    exit(1);
}

$config = require $configAppPath;
$currentVersion = $config['version'] ?? '0.1.0';
echo "Active local version: " . CLI_GREEN . $currentVersion . CLI_RESET . "\n";

// Suggest version bumps
$parts = explode('.', $currentVersion);
if (count($parts) === 3) {
    $patchBump = $parts[0] . '.' . $parts[1] . '.' . ((int)$parts[2] + 1);
    $minorBump = $parts[0] . '.' . ((int)$parts[1] + 1) . '.0';
} else {
    $patchBump = $currentVersion . '.1';
    $minorBump = $currentVersion . '.1';
}

$version = prompt("Enter target release version (suggested: {$patchBump} or {$minorBump})", $patchBump);

// Determine channel
$isBeta = (bool)preg_match('/-(alpha|beta|rc|dev)\d*$/i', $version);
$channelDefault = $isBeta ? 'beta' : 'stable';
$channel = prompt("Enter update channel (stable/beta)", $channelDefault);
$channel = strtolower($channel) === 'beta' ? 'beta' : 'stable';

echo "Target version: " . CLI_GREEN . $version . CLI_RESET . " | Channel: " . CLI_GREEN . $channel . CLI_RESET . "\n";

// -------------------------------------------------------------
// 3. Metadata Configuration
// -------------------------------------------------------------
printStep("Release Metadata");

$breakingChanges = confirm("Does this release introduce breaking changes?", false);
$breakingNotes = null;
if ($breakingChanges) {
    $breakingNotes = prompt("Enter breaking changes notes", "Requires manual intervention or database alterations.");
}

$minPhp = prompt("Minimum required PHP version", "8.2.0");
$minOwnPay = prompt("Minimum required OwnPay version", "0.1.0");

// The release zip is published as a GitHub Release asset (the canonical update
// source). The git tag is conventionally "v{version}" and the asset is
// "ownpay-{version}.zip". UpdateService whitelists github.com /
// objects.githubusercontent.com / release-assets.githubusercontent.com and
// follows the 302 to the asset CDN automatically.
$defaultUrl = "https://github.com/own-pay/OwnPay/releases/download/v{$version}/ownpay-{$version}.zip";
$customUrl = prompt("Enter download URL (GitHub Release asset URL)", $defaultUrl);
$customUrl = trim($customUrl) !== "" ? trim($customUrl) : $defaultUrl;

// Derive the metadata URL base from the chosen download URL so checksum.sha256 /
// signature.sig / changelog.md are referenced alongside the zip (upload them as
// release assets too). The client verifies using the INLINE checksum_sha256 +
// signature fields below; these *_url fields are informational/auditing aids.
$releaseAssetBase = rtrim(str_replace('\\', '/', dirname($customUrl)), '/') . '/';

// -------------------------------------------------------------
// 4. Changelog Extraction & Editor Integration
// -------------------------------------------------------------
printStep("Changelog Configuration");

$changelogFile = $projectRoot . '/CHANGELOG.md';
$changelogContent = '';

if (file_exists($changelogFile)) {
    $content = file_get_contents($changelogFile);
    // Robust parsing regex to find the section under ## [vX.Y.Z] or ## [X.Y.Z] or ## vX.Y.Z or ## X.Y.Z
    $pattern = '/##\s*\[?v?' . preg_quote($version, '/') . '\]?(?:\s*-\s*\d{4}-\d{2}-\d{2})?\s*(.*?)(?=##\s*\[?v?\d|\z)/is';
    if (is_string($content) && preg_match($pattern, $content, $matches)) {
        $changelogContent = trim($matches[1]);
    }
}

if (!empty($changelogContent)) {
    echo CLI_GREEN . "Changelog found in CHANGELOG.md for version {$version}:\n" . CLI_RESET;
    echo "----------------------------------------\n";
    echo $changelogContent . "\n";
    echo "----------------------------------------\n";
    
    $changelogOption = selectOption("What would you like to do with this changelog?", [
        1 => "Keep it as-is",
        2 => "Edit it in the terminal console",
        3 => "Open OS default editor to edit it",
    ], 1);
} else {
    echo CLI_YELLOW . "No changelog section found in CHANGELOG.md matching version {$version}.\n" . CLI_RESET;
    $changelogOption = selectOption("How would you like to define the changelog?", [
        1 => "Write it manually in the terminal console",
        2 => "Open OS default editor to write it",
        3 => "Use a placeholder generic message",
    ], 1);
    
    // Remap choice indices so manual/editor align
    if ($changelogOption === 1) $changelogOption = 2; // terminal
    elseif ($changelogOption === 2) $changelogOption = 3; // editor
    else $changelogOption = 4; // placeholder
}

if ($changelogOption === 2) {
    echo CLI_YELLOW . "\nEnter your changelog. Type 'EOF' on a new line and press Enter to save:\n" . CLI_RESET;
    $lines = [];
    while (true) {
        $line = fgets(STDIN);
        if ($line === false || trim($line) === 'EOF') {
            break;
        }
        $lines[] = rtrim($line);
    }
    $changelogContent = implode("\n", $lines);
} elseif ($changelogOption === 3) {
    $tempDir = $projectRoot . '/storage/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $tempFile = $tempDir . '/changelog_edit.md';
    file_put_contents($tempFile, $changelogContent);
    
    echo "Opening your default editor for editing...\n";
    if (stripos(PHP_OS, 'win') !== false) {
        exec('start "" "' . $tempFile . '"');
    } else {
        exec('xdg-open "' . $tempFile . '" > /dev/null 2>&1 &');
    }
    
    prompt("Please edit, save, and close the file, then press Enter to continue");
    $changelogContent = (string) file_get_contents($tempFile);
    @unlink($tempFile);
} elseif ($changelogOption === 4) {
    $changelogContent = "## v{$version} Release\n\n- Incremental improvements and stability fixes.";
}

$changelogContent = trim($changelogContent);
echo CLI_GREEN . "\nFinal changelog saved (" . strlen($changelogContent) . " bytes).\n" . CLI_RESET;

// -------------------------------------------------------------
// 5. Detect and Copy Migrations
// -------------------------------------------------------------
printStep("Checking Database Migrations");

// Helper to load manifest
$localManifestPath = $updateDir . '/manifest.json';
$existingManifestData = null;
if (file_exists($localManifestPath)) {
    $existingManifestData = json_decode((string) file_get_contents($localManifestPath), true);
}
$existingManifest = is_array($existingManifestData) ? $existingManifestData : [];
/** @var array{releases?: array<int, array{migrations?: array<int, string>}>} $existingManifest */

// Extract registered migrations
/** @var array<int, string> $registeredMigrations */
$registeredMigrations = [];
if (isset($existingManifest['releases'])) {
    foreach ($existingManifest['releases'] as $rel) {
        if (isset($rel['migrations'])) {
            $registeredMigrations = array_merge($registeredMigrations, $rel['migrations']);
        }
    }
}
$registeredMigrations = array_unique($registeredMigrations);

// Scan local migrations
$projectMigrationsDir = $projectRoot . '/database/migrations';
$localMigrations = [];
if (is_dir($projectMigrationsDir)) {
    $files = glob($projectMigrationsDir . '/*.sql');
    $localMigrations = array_map('basename', $files ?: []);
    sort($localMigrations);
}

$newMigrations = array_diff($localMigrations, $registeredMigrations);
echo "Total local migrations found: " . count($localMigrations) . "\n";
echo "New migrations to include in this release: " . CLI_GREEN . count($newMigrations) . CLI_RESET . "\n";
foreach ($newMigrations as $mig) {
    echo "  -> " . CLI_YELLOW . $mig . CLI_RESET . "\n";
}

// -------------------------------------------------------------
// 6. Packaging & Zip Generation
// -------------------------------------------------------------
printStep("Packaging Files (ZipArchive)");

// Set up target directory structure
$releaseVersionDir = $releasesDir . '/' . $version;
if (!is_dir($releaseVersionDir)) {
    mkdir($releaseVersionDir, 0755, true);
}

$zipName = "ownpay-{$version}.zip";
$zipPath = $releaseVersionDir . '/' . $zipName;

echo "Building zip at: " . CLI_CYAN . $zipPath . CLI_RESET . "\n";

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo CLI_RED . "Error: Could not create ZipArchive!" . CLI_RESET . "\n";
    exit(1);
}

// ─── Load build ignore rules from cli/.buildignore (gitignore-style, project-root-relative) ───
$buildIgnorePath = __DIR__ . '/.buildignore';
$ignorePatterns = [];
if (file_exists($buildIgnorePath)) {
    $ignoreLines = file($buildIgnorePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($ignoreLines as $ignoreLine) {
        $ignoreLine = trim($ignoreLine);
        if ($ignoreLine === '' || str_starts_with($ignoreLine, '#')) {
            continue;
        }
        $ignorePatterns[] = $ignoreLine;
    }
    echo CLI_GREEN . "Loaded " . count($ignorePatterns) . " ignore rule(s) from cli/.buildignore." . CLI_RESET . "\n";
} else {
    echo CLI_RED . "WARNING: cli/.buildignore not found - only the built-in secret hard-deny rules will apply." . CLI_RESET . "\n";
}

// ─── Hard-deny safety net: these NEVER ship, regardless of .buildignore (prevents secret leakage on misconfig) ───
$hardDenyExact = ['.env', '.env.temp', '.env.local', '.env.backup'];
$hardDenyGlob  = ['*private_key*.pem'];
$hardDenyDirs  = ['.git/'];

/**
 * Returns true if a project-root-relative path matches a .buildignore pattern.
 *
 * Pragmatic .gitignore subset (project-root-relative):
 *  - trailing "/"  → directory: matches the dir and everything beneath it
 *  - contains "/"  → matched against the full relative path (fnmatch globs allowed)
 *  - no "/"        → matched against the basename at any depth (fnmatch globs allowed)
 *
 * @param array<int, string> $patterns
 */
function buildIsIgnored(string $relativePath, array $patterns): bool
{
    $base = basename($relativePath);
    foreach ($patterns as $pattern) {
        if (str_ends_with($pattern, '/')) {
            $dir = rtrim($pattern, '/');
            if ($relativePath === $dir || str_starts_with($relativePath, $dir . '/')) {
                return true;
            }
            continue;
        }
        if (str_contains($pattern, '/')) {
            if ($relativePath === $pattern || fnmatch($pattern, $relativePath)) {
                return true;
            }
        } elseif ($base === $pattern || fnmatch($pattern, $base)) {
            return true;
        }
    }
    return false;
}

/**
 * Hard-deny check for secrets/VCS that must never ship regardless of .buildignore.
 *
 * @param array<int, string> $exact Exact basename or relative-path matches.
 * @param array<int, string> $globs fnmatch globs applied to the basename.
 * @param array<int, string> $dirs  Directory prefixes (trailing "/").
 */
function buildIsHardDenied(string $relativePath, array $exact, array $globs, array $dirs): bool
{
    $base = basename($relativePath);
    foreach ($dirs as $d) {
        $d = rtrim($d, '/');
        if ($relativePath === $d || str_starts_with($relativePath, $d . '/')) {
            return true;
        }
    }
    if (in_array($base, $exact, true) || in_array($relativePath, $exact, true)) {
        return true;
    }
    foreach ($globs as $g) {
        if (fnmatch($g, $base)) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<int, string> $ignorePatterns
 * @param array<int, string> $hardExact
 * @param array<int, string> $hardGlob
 * @param array<int, string> $hardDirs
 */
function scanAndZip(string $dir, ZipArchive $zip, string $projectRoot, array $ignorePatterns, array $hardExact, array $hardGlob, array $hardDirs, string $newVersion): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $count = 0;
    /** @var \SplFileInfo $fileinfo */
    foreach ($files as $fileinfo) {
        $absolutePath = $fileinfo->getPathname();
        $relativePath = substr($absolutePath, strlen($projectRoot) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        // Secrets/VCS can never ship - checked first, before .buildignore.
        if (buildIsHardDenied($relativePath, $hardExact, $hardGlob, $hardDirs)) {
            continue;
        }

        // .buildignore rules.
        if (buildIsIgnored($relativePath, $ignorePatterns)) {
            continue;
        }

        if ($fileinfo->isFile()) {
            if ($relativePath === 'config/app.php') {
                // In-memory version bump
                $appConfigContent = (string) file_get_contents($absolutePath);
                $updatedAppConfigContent = (string) preg_replace(
                    "/('version'\s*=>\s*')[^']*(')/",
                    "\${1}{$newVersion}\${2}",
                    $appConfigContent
                );
                $zip->addFromString('config/app.php', $updatedAppConfigContent);
            } else {
                $zip->addFile($absolutePath, $relativePath);
            }
            $count++;
        }
    }
    echo "Added {$count} files to the zip archive.\n";
}

scanAndZip($projectRoot, $zip, $projectRoot, $ignorePatterns, $hardDenyExact, $hardDenyGlob, $hardDenyDirs, $version);

// ─── Preserve the runtime directory skeleton so the zip also works as a clean fresh-install ───
// storage CONTENTS stay excluded (.buildignore), but the empty dirs must exist on extract or the
// installer's "Writable: storage/" check fails and .installed cannot be written.
$skeletonDirs = [
    'storage', 'storage/cache', 'storage/logs', 'storage/temp',
    'storage/languages', 'storage/framework', 'storage/backups',
    'public/assets/uploads',
];
foreach ($skeletonDirs as $skelDir) {
    $zip->addEmptyDir($skelDir);
    $zip->addFromString($skelDir . '/.gitkeep', '');
}
echo "Added runtime directory skeleton (" . count($skeletonDirs) . " dirs) for fresh-install support.\n";

$zip->close();

echo CLI_GREEN . "ZipArchive created successfully.\n" . CLI_RESET;

// -------------------------------------------------------------
// 7. Calculate Hash, Copy Migrations, & Save Local Release Metadata
// -------------------------------------------------------------
printStep("Generating Integrity Checks & Metadata");

// SHA-256 Checksum
$sha256 = hash_file('sha256', $zipPath);
echo "SHA-256 Checksum: " . CLI_GREEN . $sha256 . CLI_RESET . "\n";
file_put_contents($releaseVersionDir . '/checksum.sha256', $sha256 . "  {$zipName}\n");

// Cryptographic Signature
$signatureBase64 = null;
if ($privateKey !== null) {
    echo "Signing ZIP package with private key... ";
    $zipData = (string) file_get_contents($zipPath);
    if (openssl_sign($zipData, $binarySignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        $signatureBase64 = base64_encode($binarySignature);
        file_put_contents($releaseVersionDir . '/signature.sig', $signatureBase64);
        echo CLI_GREEN . "Signed OK (signature.sig saved)" . CLI_RESET . "\n";
    } else {
        echo CLI_RED . "Signing failed!" . CLI_RESET . "\n";
    }
}

// Copy new migrations to updates release directory
$migrationsList = [];
if (!empty($newMigrations)) {
    $releaseMigrationsDir = $releaseVersionDir . '/migrations';
    if (!is_dir($releaseMigrationsDir)) {
        mkdir($releaseMigrationsDir, 0755, true);
    }
    foreach ($newMigrations as $mig) {
        copy($projectMigrationsDir . '/' . $mig, $releaseMigrationsDir . '/' . $mig);
        $migrationsList[] = $mig;
    }
    echo "Copied " . count($newMigrations) . " migrations to release migrations folder.\n";
}

// Generate release.json
$releaseMetadata = [
    'release_date' => date('Y-m-d'),
    'min_php_version' => $minPhp,
    'min_ownpay_version' => $minOwnPay,
    'breaking_changes' => $breakingChanges,
    'breaking_notes' => $breakingNotes,
    'notes' => "Verified"
];
file_put_contents(
    $releaseVersionDir . '/release.json',
    json_encode($releaseMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// Save changelog.md
file_put_contents($releaseVersionDir . '/changelog.md', $changelogContent);

// -------------------------------------------------------------
// 8. Build/Update manifest.json
// -------------------------------------------------------------
printStep("Updating manifest.json");

// Load existing manifest (ensure self-healing if empty/corrupted)
$manifestData = null;
if (file_exists($localManifestPath)) {
    $manifestData = json_decode((string) file_get_contents($localManifestPath), true);
}
$manifest = is_array($manifestData) ? $manifestData : [];
/** @var array{releases?: array<int, array<string, mixed>>, channels?: array{stable?: array<string, mixed>, beta?: array<string, mixed>}, announcements?: array<int, mixed>, public_key_url?: string|null, version?: string, download_url?: string, changelog?: string, schema_version?: int, generated_at?: string} $manifest */

if (!isset($manifest['schema_version'])) {
    $manifest['schema_version'] = 1;
}
$manifest['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');

if (!isset($manifest['channels'])) {
    $manifest['channels'] = [];
}
if (!isset($manifest['releases'])) {
    $manifest['releases'] = [];
}

// Construct new release node for historical releases log
$newReleaseRecord = [
    'version'            => $version,
    'version_code'       => preg_replace('/-(alpha|beta|rc|dev)\d*$/i', '', $version),
    'channel'            => $channel,
    'download_url'       => $customUrl,
    'checksum_url'       => $releaseAssetBase . 'checksum.sha256',
    'checksum_sha256'    => $sha256,
    'signature_url'      => $signatureBase64 !== null ? $releaseAssetBase . 'signature.sig' : null,
    'signature'          => $signatureBase64,
    'changelog_url'      => $releaseAssetBase . 'changelog.md',
    'changelog'          => $changelogContent,
    'release_date'       => $releaseMetadata['release_date'],
    'size_bytes'         => filesize($zipPath),
    'min_php_version'    => $minPhp,
    'min_ownpay_version' => $minOwnPay,
    'migrations'         => $migrationsList,
    'breaking_changes'   => $breakingChanges,
    'breaking_notes'     => $breakingNotes
];

// Merge into manifest releases history (replace if version matches, else append)
$updatedReleases = [];
$replaced = false;
foreach ($manifest['releases'] as $rel) {
    if (isset($rel['version']) && $rel['version'] === $version) {
        $updatedReleases[] = $newReleaseRecord;
        $replaced = true;
    } else {
        $updatedReleases[] = $rel;
    }
}
if (!$replaced) {
    $updatedReleases[] = $newReleaseRecord;
}
$manifest['releases'] = $updatedReleases;

// Find latest stable and latest beta versions across all releases
$stableReleases = array_filter($manifest['releases'], fn($r) => $r['channel'] === 'stable');
$betaReleases = array_filter($manifest['releases'], fn($r) => $r['channel'] === 'beta');

// Sort descending by version code
$sortByVersion = function (array $a, array $b): int {
    return version_compare((string) $b['version'], (string) $a['version']);
};

if (!empty($stableReleases)) {
    usort($stableReleases, $sortByVersion);
    $latestStable = reset($stableReleases);
    $manifest['channels']['stable'] = [
        'latest_version_name' => $latestStable['version'],
        'latest_version_code' => $latestStable['version_code'],
        'min_php_version'     => $latestStable['min_php_version'],
        'min_ownpay_version'  => $latestStable['min_ownpay_version'],
        'download_url'        => $latestStable['download_url'],
        'checksum_url'        => $latestStable['checksum_url'],
        'checksum_sha256'     => $latestStable['checksum_sha256'],
        'signature_url'       => $latestStable['signature_url'] ?? null,
        'signature'           => $latestStable['signature'] ?? null,
        'changelog_url'       => $latestStable['changelog_url'],
        'changelog'           => $latestStable['changelog'],
        'release_date'        => $latestStable['release_date'],
        'size_bytes'          => $latestStable['size_bytes'],
        'migrations'          => $latestStable['migrations'],
        'breaking_changes'    => $latestStable['breaking_changes'],
        'breaking_notes'      => $latestStable['breaking_notes'],
    ];
}

if (!empty($betaReleases)) {
    usort($betaReleases, $sortByVersion);
    $latestBeta = reset($betaReleases);
    $manifest['channels']['beta'] = [
        'latest_version_name' => $latestBeta['version'],
        'latest_version_code' => $latestBeta['version_code'],
        'min_php_version'     => $latestBeta['min_php_version'],
        'min_ownpay_version'  => $latestBeta['min_ownpay_version'],
        'download_url'        => $latestBeta['download_url'],
        'checksum_url'        => $latestBeta['checksum_url'],
        'checksum_sha256'     => $latestBeta['checksum_sha256'],
        'signature_url'       => $latestBeta['signature_url'] ?? null,
        'signature'           => $latestBeta['signature'] ?? null,
        'changelog_url'       => $latestBeta['changelog_url'],
        'changelog'           => $latestBeta['changelog'],
        'release_date'        => $latestBeta['release_date'],
        'size_bytes'          => $latestBeta['size_bytes'],
        'migrations'          => $latestBeta['migrations'],
        'breaking_changes'    => $latestBeta['breaking_changes'],
        'breaking_notes'      => $latestBeta['breaking_notes'],
    ];
}

// Backward compatibility fields for legacy clients (pointing to stable latest)
if (isset($manifest['channels']['stable'])) {
    $manifest['version'] = $manifest['channels']['stable']['latest_version_name'];
    $manifest['download_url'] = $manifest['channels']['stable']['download_url'];
    $manifest['changelog'] = $manifest['channels']['stable']['changelog'];
}

// Announcements key
if (!isset($manifest['announcements'])) {
    $manifest['announcements'] = [];
}
if (!isset($manifest['public_key_url'])) {
    $manifest['public_key_url'] = null;
}

// Write the updated manifest.json pretty printed and unescaped
file_put_contents(
    $localManifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo CLI_GREEN . "manifest.json updated successfully.\n" . CLI_RESET;

// -------------------------------------------------------------
// 9. Summary
// -------------------------------------------------------------
printStep("Release Packaging Successful!");
echo "Release version: " . CLI_GREEN . $version . CLI_RESET . " (" . CLI_GREEN . $channel . CLI_RESET . ")\n";
echo "Release Zip file: " . CLI_CYAN . $zipPath . CLI_RESET . " (" . number_format((int) filesize($zipPath)) . " bytes)\n";
echo "Integrity hash:   " . CLI_YELLOW . $sha256 . CLI_RESET . "\n\n";

echo "Next Steps:\n";
echo "  1. Create the GitHub Release and upload the zip as a release asset:\n";
echo "       Tag:   " . CLI_CYAN . "v{$version}" . CLI_RESET . "\n";
echo "       Asset: " . CLI_CYAN . $zipName . CLI_RESET . " (from {$zipPath})\n";
echo "       (optionally also attach checksum.sha256, signature.sig, changelog.md)\n";
echo "       The download URL must match the manifest: " . CLI_CYAN . $customUrl . CLI_RESET . "\n";
echo "  2. Publish the manifest so clients can discover the release. Upload\n";
echo "     " . CLI_CYAN . "update/manifest.json" . CLI_RESET . " to your update server (the host in UPDATE_CHECK_URL,\n";
echo "     default https://update.ownpay.org/manifest.json). The client verifies the\n";
echo "     download using the INLINE checksum_sha256 + signature embedded in the manifest.\n";
echo "  3. The SAME zip doubles as a first-time install: unzip into a web root and\n";
echo "     browse to /install. Done - ready for production!\n\n";
