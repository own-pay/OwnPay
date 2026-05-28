<?php
declare(strict_types=1);

$handbooks = glob(__DIR__ . '/../docs/v2/plugins/gateways/*.md');
$handbookGateways = [];

foreach ($handbooks as $handbook) {
    $content = file_get_contents($handbook);
    $lines = explode("\n", $content);
    $currentGateway = '';
    
    foreach ($lines as $line) {
        if (preg_match('/^##\s+\d+\.\s+([A-Za-z0-9\s&\-\.\/]+)/', $line, $matches)) {
            $currentGateway = trim($matches[1]);
            $handbookGateways[$currentGateway] = [
                'handbook' => basename($handbook),
                'has_code' => false
            ];
        }
        if ($currentGateway !== '' && str_contains($line, '<?php')) {
            $handbookGateways[$currentGateway]['has_code'] = true;
        }
    }
}

echo "Found " . count($handbookGateways) . " gateways in handbooks:\n";
print_r($handbookGateways);
