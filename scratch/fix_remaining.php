<?php
declare(strict_types=1);

$dirPath = __DIR__ . '/../modules/gateways';

$slugs = [
    'authorize-net',
    'blik',
    'braintree',
    'ebanx',
    'fawry',
    'giropay',
    'kushki',
    'paddle',
    'payfast',
    'paytabs',
    'przelewy24',
    'sofort',
    'trustly',
    'xendit'
];

foreach ($slugs as $slug) {
    $path = $dirPath . '/' . $slug;
    $phpFiles = glob($path . '/*.php');
    if (empty($phpFiles)) {
        echo "No PHP files found for $slug\n";
        continue;
    }
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    if ($content === false) {
        echo "Failed to read $file\n";
        continue;
    }

    echo "Processing $slug...\n";

    // 1. In verify(), check if SIM_ bypass is present and inject live mode check
    // We will do a generic replacement of the SIM_ check in verify().
    // E.g. search for verify function and its internal SIM_ check.
    
    // For verify():
    // Let's find the position of function verify(
    $verifyPos = strpos($content, 'public function verify(');
    if ($verifyPos === false) {
        $verifyPos = strpos($content, 'function verify(');
    }

    if ($verifyPos !== false) {
        $verifyBlock = substr($content, $verifyPos, 1500);
        
        // Find if there is a SIM_ check block
        // Typical structure:
        // if ($gatewayTrxId === '' || str_starts_with($gatewayTrxId, 'SIM_')) {
        // or: if ($hash === '' || str_starts_with($hash, 'SIM_')) {
        // or: if ($token === '' || str_starts_with($token, 'SIM_')) {
        // or: if ($pfPaymentId === '' || str_starts_with($pfPaymentId, 'SIM_')) {
        // or: if ($tranRef === '' || str_starts_with($tranRef, 'SIM_')) {
        // or: if (str_starts_with($gatewayTrxId, 'SIM_')) {
        
        // Let's see if we can find: if (... || str_starts_with(..., 'SIM_')) {
        // or if (str_starts_with(..., 'SIM_')) {
        
        $replacedVerify = false;
        
        // Pattern 1: if ($VAR === '' || str_starts_with($VAR, 'SIM_')) {
        if (preg_match('/if\s*\(\s*\$(\w+)\s*===\s*[\'"][\'"]\s*\|\|\s*str_starts_with\(\s*\$\1\s*,\s*[\'"]SIM_[\'"]\)\s*\)\s*\{/', $verifyBlock, $matches)) {
            $varName = $matches[1];
            $target = $matches[0];
            $replacement = "if (\${$varName} === '' || str_starts_with(\${$varName}, 'SIM_')) {\n            \$mode = \$this->getString(\$credentials['mode'] ?? 'sandbox');\n            if (\$mode === 'live') {\n                return [\n                    'success'        => false,\n                    'gateway_trx_id' => '',\n                    'status'         => 'failed',\n                ];\n            }";
            $content = str_replace($target, $replacement, $content);
            $replacedVerify = true;
            echo "  - Replaced verify pattern 1 (var: $varName)\n";
        }
        // Pattern 2: if (str_starts_with($VAR, 'SIM_')) {
        elseif (preg_match('/if\s*\(\s*str_starts_with\(\s*\$(\w+)\s*,\s*[\'"]SIM_[\'"]\)\s*\)\s*\{/', $verifyBlock, $matches)) {
            $varName = $matches[1];
            $target = $matches[0];
            $replacement = "if (str_starts_with(\${$varName}, 'SIM_')) {\n            \$mode = \$this->getString(\$credentials['mode'] ?? 'sandbox');\n            if (\$mode === 'live') {\n                return [\n                    'success'        => false,\n                    'gateway_trx_id' => '',\n                    'status'         => 'failed',\n                ];\n            }";
            $content = str_replace($target, $replacement, $content);
            $replacedVerify = true;
            echo "  - Replaced verify pattern 2 (var: $varName)\n";
        }
        // Pattern 3: Braintree case: if ($nonce === '') {
        elseif ($slug === 'braintree' && str_contains($verifyBlock, "if (\$nonce === '') {")) {
            $target = "if (\$nonce === '') {";
            $replacement = "if (\$nonce === '') {\n            \$mode = \$this->getString(\$credentials['mode'] ?? 'sandbox');\n            if (\$mode === 'live') {\n                return [\n                    'success'        => false,\n                    'gateway_trx_id' => '',\n                    'status'         => 'failed',\n                ];\n            }";
            $content = str_replace($target, $replacement, $content);
            $replacedVerify = true;
            echo "  - Replaced verify Braintree pattern\n";
        }

        if (!$replacedVerify) {
            echo "  - WARNING: verify pattern not matched for $slug!\n";
        }
    }

    // 2. In initiate(), check if there's fallback to SIM_ and throw RuntimeException in live mode.
    // Let's find initiate position
    $initPos = strpos($content, 'public function initiate(');
    if ($initPos === false) {
        $initPos = strpos($content, 'function initiate(');
    }
    
    if ($initPos !== false) {
        $initBlock = substr($content, $initPos, 2000);
        $replacedInitiate = false;
        
        // Find curl failure block:
        // if ($httpCode !== 200 || !$response) {
        //     return [
        //         'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
        //     ];
        // }
        // or: if ($httpCode !== 200 && $httpCode !== 201) { ...
        // We can search for the redirect_url with status=PAID...SIM_ inside initiate.
        
        // Let's do a regex replacement on:
        // if ($httpCode !== ... || !$response) {
        //     return [
        //         'redirect_url' => $params['redirect_url'] . ... SIM_ ...
        //     ];
        // }
        // Wait, let's just search for the specific lines.
        
        // E.g. check for:
        // if ($httpCode !== 200 || !$response) {
        //     return [
        //         'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
        //     ];
        // }
        
        $pattern = '/if\s*\(\s*\$httpCode\s*!==\s*(\d+)(?:\s*&&\s*\$httpCode\s*!==\s*\d+)?\s*(?:\|\|\s*!\$response)?\s*\)\s*\{\s*return\s*\[\s*[\'"]redirect_url[\'"]\s*=>\s*\$params\[[\'"]redirect_url[\'"]\]\s*\.\s*[\'"].*?SIM_.*?[\'"]\s*\];\s*\}/s';
        if (preg_match($pattern, $initBlock, $matches)) {
            $target = $matches[0];
            $httpVal = $matches[1];
            // Determine the HTTP code condition
            $cond = "\$httpCode !== $httpVal";
            if (str_contains($target, '&&')) {
                $cond = "\$httpCode !== 200 && \$httpCode !== 201";
            }
            if (str_contains($target, '!$response')) {
                $cond .= " || !\$response";
            }
            
            $replacement = "if ($cond) {\n            \$mode = \$this->getString(\$credentials['mode'] ?? 'sandbox');\n            if (\$mode === 'live') {\n                throw new \RuntimeException('Payment gateway api error: HTTP ' . \$httpCode);\n            }\n            return [\n                'redirect_url' => \$params['redirect_url'] . '?status=PAID&reference=' . \$params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()\n            ];\n        }";
            $content = str_replace($target, $replacement, $content);
            $replacedInitiate = true;
            echo "  - Replaced initiate curl fail fallback\n";
        }
        
        // Check for general fallthrough at end of initiate:
        // return [
        //     'redirect_url' => $params['redirect_url'] . '?status=PAID&reference=' . $params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()
        // ];
        $fallthroughTarget = "return [\n            'redirect_url' => \$params['redirect_url'] . '?status=PAID&reference=' . \$params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()\n        ];";
        if (str_contains($content, $fallthroughTarget)) {
            $replacement = "\$mode = \$this->getString(\$credentials['mode'] ?? 'sandbox');\n        if (\$mode === 'live') {\n            throw new \RuntimeException('Payment initiation failed');\n        }\n        return [\n            'redirect_url' => \$params['redirect_url'] . '?status=PAID&reference=' . \$params['trx_id'] . '&gateway_trx_id=SIM_' . uniqid()\n        ];";
            $content = str_replace($fallthroughTarget, $replacement, $content);
            $replacedInitiate = true;
            echo "  - Replaced initiate fallthrough fallback\n";
        }
        
        if (!$replacedInitiate) {
            echo "  - WARNING: initiate pattern not matched for $slug!\n";
        }
    }

    file_put_contents($file, $content);

    // Validate syntax
    $output = [];
    $retVal = 0;
    exec("php -l " . escapeshellarg($file), $output, $retVal);
    if ($retVal !== 0) {
        echo "  - ERROR: syntax error in modified file: " . implode("\n", $output) . "\n";
    } else {
        echo "  - Syntax OK\n";
    }
}
