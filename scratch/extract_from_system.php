<?php
declare(strict_types=1);

$transcript = 'C:/Users/iamna/.gemini/antigravity-ide/brain/39939dca-395d-4858-8141-a4728de74dc4/.system_generated/logs/transcript.jsonl';
$lines = file($transcript);

$btcpayDone = false;
$coinbaseDone = false;

foreach ($lines as $idx => $line) {
    $data = json_decode($line, true);
    if (!is_array($data)) continue;
    $content = $data['content'] ?? '';
    
    if (!$btcpayDone && str_contains($content, 'File Path: `file:///c:/laragon/www/ownpay/modules/gateways/btcpay/BTCPayGateway.php`')) {
        echo "Found BTCPayGateway content in SYSTEM message at line $idx\n";
        $out = cleanExtractedContent($content);
        file_put_contents(__DIR__ . '/extracted_BTCPayGateway.php', $out);
        $btcpayDone = true;
    }
    
    if (!$coinbaseDone && str_contains($content, 'File Path: `file:///c:/laragon/www/ownpay/modules/gateways/coinbase-commerce/CoinbaseGateway.php`')) {
        echo "Found CoinbaseGateway content in SYSTEM message at line $idx\n";
        $out = cleanExtractedContent($content);
        file_put_contents(__DIR__ . '/extracted_CoinbaseGateway.php', $out);
        $coinbaseDone = true;
    }
}

function cleanExtractedContent(string $content): string {
    $lines = explode("\n", $content);
    $outputLines = [];
    $collect = false;
    foreach ($lines as $line) {
        if (str_contains($line, 'The following code has been modified to include a line number')) {
            $collect = true;
            continue;
        }
        if (str_contains($line, 'The above content shows the entire')) {
            $collect = false;
            break;
        }
        if ($collect) {
            // Match "1: <?php"
            if (preg_match('/^\d+:\s?(.*)$/', $line, $matches)) {
                $outputLines[] = $matches[1];
            } else {
                $outputLines[] = $line;
            }
        }
    }
    return implode("\n", $outputLines);
}
