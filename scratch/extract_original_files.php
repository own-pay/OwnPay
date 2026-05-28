<?php
declare(strict_types=1);

$transcript = 'C:/Users/iamna/.gemini/antigravity-ide/brain/39939dca-395d-4858-8141-a4728de74dc4/.system_generated/logs/transcript.jsonl';
if (!file_exists($transcript)) {
    die("Transcript not found at $transcript\n");
}

$lines = file($transcript);
foreach ($lines as $idx => $line) {
    $data = json_decode($line, true);
    if (!is_array($data)) continue;
    $content = json_encode($data);
    if (str_contains($content, 'BTCPayGateway.php')) {
        echo "Found BTCPayGateway at line $idx\n";
    }
    if (str_contains($content, 'CoinbaseGateway.php')) {
        echo "Found CoinbaseGateway at line $idx\n";
    }
}
