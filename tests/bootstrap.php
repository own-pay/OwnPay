<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for OwnPay.
 *
 * Responsibilities:
 * 1. Load the Composer autoloader.
 * 2. Auto-discover and configure OPENSSL_CONF when it is not already set
 *    by the environment.
 *
 * Why this is needed
 * ------------------
 * PHP's OpenSSL extension can load public/private keys from PEM strings
 * without any configuration file. However, *generating* new key material
 * (openssl_pkey_new) requires OpenSSL to locate its default configuration
 * file at startup. On some local setups (Laragon, XAMPP on Windows; custom
 * PHP builds on Linux/macOS) the OPENSSL_CONF environment variable is not
 * set globally, so openssl_pkey_new() silently returns false.
 *
 * Rather than hard-coding a machine-specific path in phpunit.xml we probe a
 * ranked list of well-known locations at bootstrap time. The first existing
 * file wins. Any contributor whose system already has OPENSSL_CONF set (e.g.
 * via a .env file, their shell profile, or a CI environment variable) is
 * unaffected -- this code becomes a no-op.
 *
 * Discovery order
 * ---------------
 * 1. Respect the existing environment (OPENSSL_CONF already set).
 * 2. Ask the `openssl` CLI binary which directory it uses (cross-platform).
 * 3. Probe candidate paths ordered by platform popularity:
 *    Windows  : Laragon (wildcard), XAMPP, Strawberry Perl, Scoop
 *    macOS    : Homebrew (Intel + Apple Silicon), MacPorts, system
 *    Linux    : Debian/Ubuntu, Fedora/RHEL, Alpine, Arch
 */

// ---------------------------------------------------------------------------
// 1. Composer autoloader
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 2. OPENSSL_CONF auto-discovery
// ---------------------------------------------------------------------------
if (getenv('OPENSSL_CONF') !== false && getenv('OPENSSL_CONF') !== '') {
    // Already configured -- nothing to do.
    return;
}

/**
 * Ask the openssl CLI which default directory it compiled in.
 * `openssl version -d` outputs e.g.:
 *   OPENSSLDIR: "/usr/lib/ssl"
 *   OPENSSLDIR: "C:\Program Files\OpenSSL-Win64"
 *
 * @return string|null Full path to openssl.cnf if the CLI is available, null otherwise.
 */
function _ownpay_openssl_conf_from_cli(): ?string
{
    $command = PHP_OS_FAMILY === 'Windows' ? 'openssl version -d 2>NUL' : 'openssl version -d 2>/dev/null';
    $output  = @shell_exec($command);
    if ($output === null || $output === '') {
        return null;
    }
    // Extract the directory from: OPENSSLDIR: "..."
    if (preg_match('/OPENSSLDIR:\s*"([^"]+)"/', $output, $m)) {
        $candidate = rtrim($m[1], '/\\') . DIRECTORY_SEPARATOR . 'openssl.cnf';
        return is_file($candidate) ? $candidate : null;
    }
    return null;
}

/**
 * Returns a prioritised list of candidate openssl.cnf paths for the
 * current platform. Glob patterns are expanded; non-existent paths are
 * skipped automatically by the caller.
 *
 * @return string[]
 */
function _ownpay_openssl_conf_candidates(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return [
            // Laragon (any PHP version wildcard)
            ...glob('C:\\laragon\\bin\\php\\php-*\\extras\\ssl\\openssl.cnf', GLOB_NOSORT) ?: [],
            // XAMPP
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
            // Strawberry Perl bundled OpenSSL
            'C:\\Strawberry\\c\\ssl\\openssl.cnf',
            // Scoop
            getenv('USERPROFILE') . '\\scoop\\apps\\openssl\\current\\ssl\\openssl.cnf',
            // OpenSSL Win64 standalone installer
            'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.cfg',
            'C:\\OpenSSL-Win64\\bin\\openssl.cfg',
        ];
    }

    if (PHP_OS_FAMILY === 'Darwin') {
        return [
            // Homebrew on Apple Silicon
            '/opt/homebrew/etc/openssl@3/openssl.cnf',
            '/opt/homebrew/etc/openssl@1.1/openssl.cnf',
            '/opt/homebrew/etc/openssl/openssl.cnf',
            // Homebrew on Intel
            '/usr/local/etc/openssl@3/openssl.cnf',
            '/usr/local/etc/openssl@1.1/openssl.cnf',
            '/usr/local/etc/openssl/openssl.cnf',
            // MacPorts
            '/opt/local/etc/openssl/openssl.cnf',
            // System
            '/etc/ssl/openssl.cnf',
        ];
    }

    // Linux and everything else
    return [
        // Debian / Ubuntu / Mint
        '/etc/ssl/openssl.cnf',
        // Fedora / RHEL / CentOS / Amazon Linux
        '/etc/pki/tls/openssl.cnf',
        // Alpine Linux
        '/etc/ssl1.1/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        // Arch Linux
        '/etc/openssl/openssl.cnf',
        // Generic local build
        '/usr/local/ssl/openssl.cnf',
        '/usr/lib/ssl/openssl.cnf',
    ];
}

// ---------------------------------------------------------------------------
// Discovery: CLI first, then candidate paths.
// ---------------------------------------------------------------------------
$discovered = _ownpay_openssl_conf_from_cli();

if ($discovered === null) {
    foreach (_ownpay_openssl_conf_candidates() as $candidate) {
        if (is_file($candidate)) {
            $discovered = $candidate;
            break;
        }
    }
}

if ($discovered !== null) {
    putenv('OPENSSL_CONF=' . $discovered);
    // Note: putenv() updates the process environment. OpenSSL reads
    // OPENSSL_CONF the first time it needs a configuration (e.g. on the
    // first call to openssl_pkey_new()), not at PHP startup. So calling
    // putenv() here, before any test code runs, is effective.
}
