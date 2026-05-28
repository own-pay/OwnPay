<?php
declare(strict_types=1);

$transcript = 'C:/Users/iamna/.gemini/antigravity-ide/brain/39939dca-395d-4858-8141-a4728de74dc4/.system_generated/logs/transcript.jsonl';
$lines = file($transcript);
foreach ($lines as $idx => $line) {
    if (str_contains($line, 'BTCPayGateway.php')) {
        $data = json_decode($line, true);
        echo "Line $idx: Keys: " . implode(', ', array_keys($data)) . "\n";
        if (isset($data['tool_calls'])) {
            echo "  tool_calls count: " . count($data['tool_calls']) . "\n";
        }
        // print first 200 chars of line
        echo "  Snippet: " . substr($line, 0, 300) . "\n\n";
        break;
    }
}
