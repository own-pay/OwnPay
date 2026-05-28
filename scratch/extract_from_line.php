<?php
declare(strict_types=1);

$transcript = 'C:/Users/iamna/.gemini/antigravity-ide/brain/39939dca-395d-4858-8141-a4728de74dc4/.system_generated/logs/transcript.jsonl';
$lines = file($transcript);

// Extract line 409 (BTCPay)
$lineBtcpay = json_decode($lines[409], true);
$contentBtcpay = $lineBtcpay['content'] ?? '';
$cleanBtcpay = cleanExtractedContent($contentBtcpay);
file_put_contents(__DIR__ . '/extracted_BTCPayGateway.php', $cleanBtcpay);
echo "Extracted BTCPay from line 409 (length: " . strlen($cleanBtcpay) . ")\n";

// Extract line 411 (Coinbase)
$lineCoinbase = json_decode($lines[411], true);
$contentCoinbase = $lineCoinbase['content'] ?? '';
$cleanCoinbase = cleanExtractedContent($contentCoinbase);
file_put_contents(__DIR__ . '/extracted_CoinbaseGateway.php', $cleanCoinbase);
echo "Extracted Coinbase from line 411 (length: " . strlen($cleanCoinbase) . ")\n";

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
            if (preg_match('/^\d+:\s?(.*)$/', $line, $matches)) {
                $outputLines[] = $matches[1];
            } else {
                $outputLines[] = $line;
            }
        }
    }
    return implode("\n", $outputLines);
}
