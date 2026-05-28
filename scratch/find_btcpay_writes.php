<?php
declare(strict_types=1);

$transcript = 'C:/Users/iamna/.gemini/antigravity-ide/brain/39939dca-395d-4858-8141-a4728de74dc4/.system_generated/logs/transcript.jsonl';
$lines = file($transcript);

foreach ($lines as $idx => $line) {
    $data = json_decode($line, true);
    if (!is_array($data)) continue;
    $content = $data['content'] ?? '';
    
    if (str_contains($content, 'write_to_file') && str_contains($content, 'BTCPayGateway.php')) {
        echo "Line $idx (Step {$data['step_index']}): write_to_file for BTCPayGateway.php\n";
        echo "  Snippet: " . substr($content, 0, 200) . "\n\n";
    }
}
