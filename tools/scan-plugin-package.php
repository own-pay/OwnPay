<?php

declare(strict_types=1);

/**
 * Pre-signing static scanner for OwnPay plugin packages.
 *
 * The plugin registry (plugins.ownpay.org) runs this scanner on every submission
 * before the owner reviews it and privately signs an approved package. It is
 * shipped in-repo so operators and CI can run the exact same checks locally
 * before uploading to the registry:
 *
 *   php tools/scan-plugin-package.php /path/to/plugin-dir-or.zip
 *   php tools/scan-plugin-package.php /path/to/plugin-dir-or.zip --json
 *
 * What it flags (high signal, low false positives):
 *
 *   SEC-01  direct .env reads: file I/O against paths containing ".env", or reads
 *           of common secret keys via getenv()
 *   SEC-02  bypassing EventManager: direct calls into core database / service
 *           singletons from plugin code (plugins must go through EventManager)
 *   SEC-03  outbound network calls (curl_init, fsockopen, HTTP wrappers)
 *   SEC-04  obfuscation: base64 + eval, gzinflate/str_rot13 chains, variable-
 *           function invocation, chr() chains
 *   SEC-05  shell/process execution: exec, shell_exec, passthru, proc_open,
 *           backticks, system()
 *
 * Exit codes: 0 = clean, 2 = findings (review required before signing), 1 = usage/error.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool runs on the CLI only.\n");
    exit(1);
}

$target = $argv[1] ?? null;
$asJson = in_array('--json', $argv, true);

if ($target === null) {
    fwrite(STDERR, "Usage: php tools/scan-plugin-package.php /path/to/plugin-dir-or.zip [--json]\n");
    exit(1);
}

/** @var array<int, array{rule: string, severity: string, file: string, line: int, snippet: string}> $findings */
$findings = [];

$patterns = [
    // [rule, severity, in-line PCRE]
    ['SEC-01', 'high', <<<'PCRE'
/\bfile_get_contents\s*\(\s*['"][^'"]*\.env[^'"]*['"]/i
PCRE
    ],
    ['SEC-01', 'high', <<<'PCRE'
/\bfopen\s*\(\s*['"][^'"]*\.env[^'"]*['"]/i
PCRE
    ],
    ['SEC-01', 'high', <<<'PCRE'
/\bnew\s+\\?SplFileObject\s*\(\s*['"][^'"]*\.env[^'"]*['"]/i
PCRE
    ],
    ['SEC-01', 'medium', <<<'PCRE'
/getenv\s*\(\s*['"](?:ENCRYPTION_KEY|APP_KEY|DB_PASSWORD|JWT_SECRET|PII_ENCRYPTION_KEY)['"]/
PCRE
    ],
    ['SEC-02', 'high', <<<'PCRE'
/\bnew\s+\\OwnPay\\Core\\Database\b/
PCRE
    ],
    ['SEC-02', 'high', <<<'PCRE'
/\b\\OwnPay\\Core\\Database::getInstance\b/
PCRE
    ],
    ['SEC-02', 'medium', <<<'PCRE'
/\bnew\s+\\OwnPay\\(?:Service|Repository|Security|Update)\\[A-Za-z0-9_]*\b/
PCRE
    ],
    ['SEC-03', 'medium', <<<'PCRE'
/\bcurl_init\s*\(/
PCRE
    ],
    ['SEC-03', 'medium', <<<'PCRE'
/\b(?:fsockopen|pfsockopen|stream_socket_client)\s*\(/
PCRE
    ],
    ['SEC-03', 'medium', <<<'PCRE'
/\b(?:fopen|file_get_contents|readfile|copy)\s*\(\s*['"]https?:\/\//i
PCRE
    ],
    ['SEC-03', 'medium', <<<'PCRE'
/\bGuzzleHttp\\\bGuzzle\b|\bGuzzleHttp\\Middleware/i
PCRE
    ],
    ['SEC-04', 'high', <<<'PCRE'
/\beval\s*\(/i
PCRE
    ],
    ['SEC-04', 'high', <<<'PCRE'
/\b(?:gzinflate|gzdecode|str_rot13|base64_decode)\s*\(\s*(?:\$[A-Za-z_][A-Za-z0-9_]*|'[^']+')\s*\)\s*;?\s*eval/i
PCRE
    ],
    ['SEC-04', 'high', <<<'PCRE'
/\$\$[A-Za-z_][A-Za-z0-9_]*/
PCRE
    ],
    ['SEC-04', 'medium', <<<'PCRE'
/\bchr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(/
PCRE
    ],
    ['SEC-04', 'medium', <<<'PCRE'
/\b(?:assert|call_user_func|call_user_func_array)\s*\(\s*\$/i
PCRE
    ],
    ['SEC-05', 'high', <<<'PCRE'
/\b(?:exec|shell_exec|passthru|proc_open|popen|pcntl_exec)\s*\(/i
PCRE
    ],
    ['SEC-05', 'high', <<<'PCRE'
/`[^`]+`/
PCRE
    ],
    ['SEC-05', 'high', <<<'PCRE'
/\bsystem\s*\(/
PCRE
    ],
    ['SEC-05', 'medium', <<<'PCRE'
/\bproc_open\s*\(/
PCRE
    ],
];

$files = [];

if (is_file($target) && strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'zip') {
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) {
        fwrite(STDERR, "Cannot open zip: {$target}\n");
        exit(1);
    }
    $tempDir = sys_get_temp_dir() . '/op_scan_' . bin2hex(random_bytes(6));
    mkdir($tempDir, 0755, true);
    $zip->extractTo($tempDir);
    $zip->close();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower(pathinfo($fileInfo->getPathname(), PATHINFO_EXTENSION)) === 'php') {
            $files[] = $fileInfo->getPathname();
        }
    }
} elseif (is_dir($target)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower(pathinfo($fileInfo->getPathname(), PATHINFO_EXTENSION)) === 'php') {
            $files[] = $fileInfo->getPathname();
        }
    }
} else {
    fwrite(STDERR, "Not a directory or zip: {$target}\n");
    exit(1);
}

if ($files === []) {
    fwrite(STDERR, "No PHP files found in {$target}\n");
    exit(1);
}

$baseDir = is_dir($target) ? rtrim($target, '/') . '/' : '';

foreach ($files as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        continue;
    }
    $lines = explode("\n", $contents);

    $displayName = $baseDir !== '' && str_starts_with($file, $baseDir)
        ? substr($file, strlen($baseDir))
        : basename($file);

    foreach ($patterns as [$rule, $severity, $regex]) {
        foreach ($lines as $lineNumber => $line) {
            if (preg_match($regex, $line) === 1) {
                $findings[] = [
                    'rule' => $rule,
                    'severity' => $severity,
                    'file' => $displayName,
                    'line' => $lineNumber + 1,
                    'snippet' => trim(substr($line, 0, 200)),
                ];
            }
        }
    }
}

// De-duplicate identical findings (same rule+file+line)
$unique = [];
foreach ($findings as $f) {
    $key = $f['rule'] . ':' . $f['file'] . ':' . $f['line'];
    if (!isset($unique[$key])) {
        $unique[$key] = $f;
    }
}
$findings = array_values($unique);

$high = count(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'high'));
$medium = count($findings) - $high;

if ($asJson) {
    fwrite(STDOUT, json_encode([
        'target' => $target,
        'files_scanned' => count($files),
        'findings' => $findings,
        'high' => $high,
        'medium' => $medium,
        'verdict' => $findings === [] ? 'clean' : 'review_required',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} else {
    if ($findings === []) {
        fwrite(STDOUT, "CLEAN: {$target} (" . count($files) . " files scanned, 0 findings)\n");
    } else {
        fwrite(STDOUT, "REVIEW REQUIRED: {$target} (" . count($files) . " files scanned)\n\n");
        foreach ($findings as $f) {
            fwrite(STDOUT, "[{$f['severity']}] {$f['rule']} {$f['file']}:{$f['line']}\n");
            fwrite(STDOUT, "       {$f['snippet']}\n\n");
        }
    }
}

exit($findings === [] ? 0 : 2);
