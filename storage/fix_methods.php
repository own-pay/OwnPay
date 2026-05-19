<?php
/**
 * Comprehensive PHPStan fix script — adds missing methods to classes.
 * Uses last-brace injection: inserts new method code before the final `}`.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$fixes = [
    // BaseRepository — add insert() alias for create()
    'src/Repository/BaseRepository.php' => '
    /**
     * Insert a new record (alias for create).
     * Used by WebhookInboundProcessor.
     *
     * @param array<string, mixed> $data
     * @return string Last insert ID
     */
    public function insert(array $data): string
    {
        return $this->create($data);
    }
',

    // DomainService — add verifyDomain() alias for verify()
    'src/Service/Domain/DomainService.php' => '
    /**
     * Verify domain DNS (alias for verify).
     * Called by Api\Admin\DomainController.
     */
    public function verifyDomain(int $domainId, int $merchantId): array
    {
        return $this->verify($domainId, $merchantId);
    }
',

    // SmsParserService — add parse() and parseAndStore()
    'src/Service/Sms/SmsParserService.php' => '
    /**
     * Parse a single SMS message without storing.
     * Used by MfsService.
     *
     * @param string $rawMessage  The raw SMS body
     * @param string $sender      Sender identifier
     * @param int    $brandId     Merchant/brand ID
     * @return array|null Parsed data or null on failure
     */
    public function parse(string $rawMessage, string $sender, int $brandId): ?array
    {
        return $this->attemptParse($rawMessage, $sender, $brandId);
    }

    /**
     * Parse and store a single SMS.
     * Used by Mobile SmsController.
     */
    public function parseAndStore(string $deviceUuid, int $brandId, array $message): array
    {
        return $this->processBatch($deviceUuid, $brandId, [$message]);
    }
',
];

$ok = 0;
$fail = 0;

foreach ($fixes as $relPath => $newCode) {
    $filePath = $root . '/' . $relPath;
    if (!file_exists($filePath)) {
        echo "SKIP: {$relPath} — file not found\n";
        $fail++;
        continue;
    }

    $content = file_get_contents($filePath);
    
    // Find the LAST closing brace
    $lastBrace = strrpos($content, '}');
    if ($lastBrace === false) {
        echo "SKIP: {$relPath} — no closing brace\n";
        $fail++;
        continue;
    }

    // Check if method already exists (prevent double insertion)
    // Extract first method name from new code
    if (preg_match('/public (?:static )?function (\w+)/', $newCode, $m)) {
        if (strpos($content, "function {$m[1]}(") !== false) {
            echo "SKIP: {$relPath} — method {$m[1]}() already exists\n";
            continue;
        }
    }

    $content = substr($content, 0, $lastBrace) . $newCode . substr($content, $lastBrace);
    file_put_contents($filePath, $content);
    echo "OK: {$relPath}\n";
    $ok++;
}

echo "\nDone: {$ok} files modified, {$fail} skipped.\n";
