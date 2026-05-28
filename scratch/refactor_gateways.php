<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$files = glob("$dir/*/*.php");

if (!$files) {
    die("No gateway files found\n");
}

function getMethodRange(string $code, string $methodName): ?array {
    $pattern = '/function\s+' . $methodName . '\s*\(/i';
    if (!preg_match($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $funcPos = $matches[0][1];
    $startBrace = strpos($code, '{', $funcPos);
    if ($startBrace === false) return null;
    
    $len = strlen($code);
    $braceCount = 1;
    $endBrace = -1;
    for ($i = $startBrace + 1; $i < $len; $i++) {
        if ($code[$i] === '{') {
            $braceCount++;
        } elseif ($code[$i] === '}') {
            $braceCount--;
            if ($braceCount === 0) {
                $endBrace = $i;
                break;
            }
        }
    }
    if ($endBrace === -1) return null;
    return [
        'start' => $startBrace,
        'end' => $endBrace,
        'body' => substr($code, $startBrace + 1, $endBrace - $startBrace - 1)
    ];
}

$skipList = [
    'StripeGateway.php',
    'BkashApiGateway.php',
    'NagadMerchantApiGateway.php',
    'PaypalCheckoutGateway.php',
    'SslCommerzGateway.php',
    'BTCPayGateway.php',
    'CoinbaseGateway.php'
];

foreach ($files as $file) {
    $basename = basename($file);
    if (in_array($basename, $skipList)) {
        echo "Skipping $basename\n";
        continue;
    }

    echo "Refactoring $basename...\n";
    $code = file_get_contents($file);
    $originalCode = $code;

    // Pre-pass 1: Complex ternary error message pattern
    $pattern1 = '/\(is_array\(\s*(\$[a-zA-Z0-9_]+)\s*\)\s*&&\s*isset\(\s*\1\s*\[\s*\'([a-zA-Z0-9_-]+)\'\s*\]\s*\)\s*&&\s*is_scalar\(\s*\1\s*\[\s*\'\2\'\s*\]\s*\)\)\s*\?\s*(?:\(string\)\s*)?\1\s*\[\s*\'\2\'\s*\]\s*:\s*(\'[^\']+\'|"[^"]+")/';
    $code = preg_replace_callback($pattern1, function ($matches) {
        $var = $matches[1];
        $key = $matches[2];
        $default = $matches[3];
        return "\$this->getString(\$this->getArray($var)['$key'] ?? null, $default)";
    }, $code);

    // Pre-pass 2: is_array && isset && is_scalar boolean pattern (with optional is_array)
    $pattern2 = '/(?:is_array\(\s*(\$[a-zA-Z0-9_]+)\s*\)\s*&&\s*)?isset\(\s*(\$[a-zA-Z0-9_]+(?:\[\s*\'[a-zA-Z0-9_-]+\'\s*\])*)\s*\)\s*&&\s*is_scalar\(\s*\2\s*\)/';
    $code = preg_replace_callback($pattern2, function ($matches) {
        $target = $matches[2];
        if (str_contains($target, '[')) {
            return "is_scalar($target ?? null)";
        }
        return "is_scalar($target)";
    }, $code);

    // Pre-pass 3: isset && is_scalar ternary pattern with flexible fallback expression (allowing parenthesis)
    $pattern3 = '/(?:isset\([^\)]+\)\s*&&\s*)?is_scalar\(\s*(\$[a-zA-Z0-9_]+(?:\[\s*\'[a-zA-Z0-9_-]+\'\s*\])*)\s*\)\s*\?\s*(?:\(string\)\s*)?\1\s*:\s*([^;,\n\]\}]+)/';
    $code = preg_replace_callback($pattern3, function ($matches) {
        $target = $matches[1];
        $fallback = trim($matches[2]);
        if ($fallback === "''" || $fallback === '""' || $fallback === 'null') {
            $fallback = '';
        } else {
            $fallback = ', ' . $fallback;
        }
        if (str_contains($target, '[')) {
            return "\$this->getString($target ?? null$fallback)";
        }
        return "\$this->getString($target$fallback)";
    }, $code);

    // Custom patch for Adyen's webhook data extraction to handle the nested [0] index
    if ($basename === 'AdyenGateway.php') {
        $code = str_replace(
            "if (!is_array(\$data) || !isset(\$data['notificationItems'][0]['NotificationRequestItem'])) return false;",
            "if (!is_array(\$data)) return false;",
            $code
        );
        $code = str_replace(
            "\$item = \$data['notificationItems'][0]['NotificationRequestItem'];",
            "\$item = \$this->getArray(\$data, 'notificationItems', 0, 'NotificationRequestItem');\n        if (empty(\$item)) return false;",
            $code
        );
    }

    $methods = ['initiate', 'verify', 'verifyWebhook', 'refund'];
    $replacements = [];

    foreach ($methods as $method) {
        $range = getMethodRange($code, $method);
        if (!$range) {
            continue;
        }

        $body = $range['body'];
        $originalBody = $body;

        // 1. Skip isset/empty and replace raw $credentials, $callbackData, $data, $item, $params, $resultList access
        // We use the regex with optional fallback
        $pattern = '/(isset|empty)\s*\(\s*\$(credentials|callbackData|data|item|params|resultList)((?:\s*\[\s*(?:\'[a-zA-Z0-9_-]+\'|\d+|\$[a-zA-Z0-9_]+)\s*\])+)\s*\)|\$(credentials|callbackData|data|item|params|resultList)((?:\s*\[\s*(?:\'[a-zA-Z0-9_-]+\'|\d+|\$[a-zA-Z0-9_]+)\s*\])+)(?:\s*\?\?\s*(?:\'\'|\"\"|null))?/';
        
        $body = preg_replace_callback($pattern, function ($matches) use ($basename, $method) {
            // Group 1 matches isset/empty
            if (!empty($matches[1])) {
                return $matches[0];
            }
            
            // Group 4 matches the variable name: credentials, callbackData, data, item, params, resultList
            $varName = $matches[4];
            $brackets = $matches[5];
            
            // Parse individual keys
            preg_match_all('/\[\s*(\'[a-zA-Z0-9_-]+\'|\d+|\$[a-zA-Z0-9_]+)\s*\]/', $brackets, $bracketMatches);
            $keys = $bracketMatches[1];
            
            // If it is $params, we only want to refactor it if it is nested (more than 1 key)
            if ($varName === 'params' && count($keys) <= 1) {
                return $matches[0];
            }

            // If it is $resultList and accessed by index in Shurjopay verify
            if ($varName === 'resultList' && $brackets === "[0]") {
                return "\$this->getArray(\$resultList, 0)";
            }

            // If it is a single key
            if (count($keys) === 1) {
                $key = $keys[0];
                if ($varName === 'credentials' && $key === "'mode'") {
                    return "\$this->getString(\$credentials['mode'] ?? null, 'sandbox')";
                }
                return "\$this->getString(\${$varName}[$key] ?? null)";
            }
            
            // Multiple keys (nested array access)
            $last = array_pop($keys);
            $getArrayArgs = implode(', ', $keys);
            return "\$this->getString(\$this->getArray(\${$varName}, $getArrayArgs)[$last] ?? null)";
        }, $body);

        // 2. Remove redundant (string) casts around our helper methods
        $body = preg_replace('/\(string\)\s*\(\s*(\$this->(?:getString|getArray)\([^\)]+\))\s*\)/', '$1', $body);
        $body = preg_replace('/\(string\)\s*(\$this->(?:getString|getArray)\([^\)]+\))/', '$1', $body);

        // 3. Fix json_decode array check
        if (preg_match('/\$([a-zA-Z0-9_]+)\s*=\s*json_decode\(/', $body, $dataMatch)) {
            $dataVar = $dataMatch[1];
            if (!str_contains($body, "is_array(\$$dataVar)")) {
                $patternDecode = '/\$' . $dataVar . '\s*=\s*json_decode\([^\n]+;/';
                if (preg_match($patternDecode, $body, $decodeLineMatch, PREG_OFFSET_CAPTURE)) {
                    $insertPos = $decodeLineMatch[0][1] + strlen($decodeLineMatch[0][0]);
                    
                    $errorAction = "throw new \\RuntimeException('Gateway API error: Invalid response');";
                    if ($method === 'verify') {
                        $errorAction = "return ['success' => false, 'gateway_trx_id' => '', 'amount' => '', 'status' => 'failed'];";
                    } elseif ($method === 'verifyWebhook') {
                        $errorAction = "return false;";
                    } elseif ($method === 'refund') {
                        $errorAction = "return ['success' => false, 'refund_id' => '', 'error' => 'Invalid response format'];";
                    }
                    
                    $checkStr = "\n        if (!is_array(\$$dataVar)) {\n            $errorAction\n        }\n";
                    $body = substr($body, 0, $insertPos) . $checkStr . substr($body, $insertPos);
                }
            }
        }

        // 4. Fix CURLOPT_POSTFIELDS json_encode
        $body = preg_replace('/CURLOPT_POSTFIELDS\s*=>\s*json_encode\(/', 'CURLOPT_POSTFIELDS     => (string) json_encode(', $body);

        if ($body !== $originalBody) {
            $replacements[] = [
                'start' => $range['start'] + 1,
                'end' => $range['end'],
                'newBody' => $body
            ];
        }
    }

    usort($replacements, fn($a, $b) => $b['start'] <=> $a['start']);
    foreach ($replacements as $rep) {
        $code = substr($code, 0, $rep['start']) . $rep['newBody'] . substr($code, $rep['end']);
    }

    if ($code !== $originalCode) {
        file_put_contents($file, $code);
        echo "Successfully refactored $basename\n";
    }
}
echo "Refactoring finished!\n";
