<?php
declare(strict_types=1);

$transcript = 'C:/Users/iamna/.gemini/antigravity-ide/brain/39939dca-395d-4858-8141-a4728de74dc4/.system_generated/logs/transcript.jsonl';
$lines = file($transcript);
foreach ($lines as $idx => $line) {
    if (str_contains($line, 'BTCPayGateway.php') && str_contains($line, 'MODEL')) {
        echo "Line $idx: MODEL step mentions BTCPayGateway.php\n";
        file_put_contents(__DIR__ . "/model_line_{$idx}.txt", $line);
    }
}
