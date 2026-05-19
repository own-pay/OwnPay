<?php
/**
 * PHPStan fix batch 3 — Response::plain, DateTimeService::getCurrentDatetime, more.
 */
declare(strict_types=1);
$root = dirname(__DIR__);

$fixes = [
    // Response — add plain() alias for text()
    'src/Http/Response.php' => '
    /**
     * Plain text response (alias for text).
     * Used by CronController.
     */
    public static function plain(string $text, int $status = 200): self
    {
        return self::text($text, $status);
    }
',

    // DateTimeService — add static getCurrentDatetime()
    'src/Service/System/DateTimeService.php' => '
    /**
     * Get current datetime formatted as string (static convenience).
     * Used by SystemUpdateJob cron.
     */
    public static function getCurrentDatetime(string $format = \'Y-m-d H:i:s\'): string
    {
        $tz = getenv(\'APP_TIMEZONE\') ?: \'UTC\';
        return (new \DateTimeImmutable(\'now\', new \DateTimeZone($tz)))->format($format);
    }
',
];

$ok = 0;
foreach ($fixes as $relPath => $newCode) {
    $filePath = $root . '/' . $relPath;
    if (!file_exists($filePath)) {
        echo "SKIP: {$relPath} — not found\n";
        continue;
    }

    $content = file_get_contents($filePath);
    
    // Extract method name to prevent double insertion
    if (preg_match('/public (?:static )?function (\w+)/', $newCode, $m)) {
        if (strpos($content, "function {$m[1]}(") !== false) {
            echo "SKIP: {$relPath} — {$m[1]}() already exists\n";
            continue;
        }
    }

    $lastBrace = strrpos($content, '}');
    if ($lastBrace === false) {
        echo "SKIP: {$relPath} — no closing brace\n";
        continue;
    }
    
    $content = substr($content, 0, $lastBrace) . $newCode . substr($content, $lastBrace);
    file_put_contents($filePath, $content);
    echo "OK: {$relPath}\n";
    $ok++;
}
echo "\nDone: {$ok} files.\n";
