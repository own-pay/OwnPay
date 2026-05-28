<?php
declare(strict_types=1);

$prevTranscript = 'C:/Users/iamna/.gemini/antigravity-ide/brain/1f795387-0812-44e3-9fd2-2d127dc0c3b2/.system_generated/logs/transcript.jsonl';
if (!file_exists($prevTranscript)) {
    die("Previous transcript not found at $prevTranscript\n");
}

$lines = file($prevTranscript);
echo "Total lines in previous log: " . count($lines) . "\n";

foreach ($lines as $idx => $line) {
    if (str_contains($line, 'BTCPayGateway.php') || str_contains($line, 'CoinbaseGateway.php')) {
        $data = json_decode($line, true);
        if (!is_array($data)) continue;
        echo "Line $idx: source={$data['source']}, type={$data['type']}\n";
        // If it contains code content, write it
        if (str_contains($line, '<?php') && str_contains($line, 'class')) {
            echo "  (Line contains PHP code block!)\n";
            file_put_contents(__DIR__ . "/prev_line_{$idx}.txt", $line);
        }
    }
}
