<?php
// Helper script to add missing methods to repositories
declare(strict_types=1);

$changes = [
    // TransactionRepository: findPendingMatch
    'src/Repository/TransactionRepository.php' => [
        'before_last_brace' => '
    /**
     * Find a pending transaction matching SMS amount/gateway for auto-verification.
     * Used by SmsVerificationJob cron.
     */
    public function findPendingMatch(int $merchantId, string $amount, string $gatewaySlug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table}
             WHERE merchant_id = :mid AND status = \'pending\'
               AND amount = :amt AND gateway_slug = :gw
             ORDER BY created_at DESC LIMIT 1",
            [\'mid\' => $merchantId, \'amt\' => $amount, \'gw\' => $gatewaySlug]
        );
    }',
    ],
    // GatewayConfigRepository: listActiveWithGateway
    'src/Repository/GatewayConfigRepository.php' => [
        'before_last_brace' => '
    /**
     * List active gateway configs with gateway details (JOIN).
     * Used by GatewayRendererService.
     */
    public function listActiveWithGateway(): array
    {
        return $this->listActive();
    }',
    ],
];

foreach ($changes as $file => $ops) {
    $path = dirname(__DIR__) . '/' . $file;
    $content = file_get_contents($path);
    if ($content === false) {
        echo "SKIP: {$file} not found\n";
        continue;
    }

    if (isset($ops['before_last_brace'])) {
        // Find last closing brace
        $lastBrace = strrpos($content, '}');
        if ($lastBrace !== false) {
            $insert = $ops['before_last_brace'] . "\r\n";
            $content = substr($content, 0, $lastBrace) . $insert . substr($content, $lastBrace);
        }
    }

    file_put_contents($path, $content);
    echo "OK: {$file}\n";
}

echo "DONE\n";
