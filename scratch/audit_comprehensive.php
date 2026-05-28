<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';
$report = [];

foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) {
        $report[$slug] = [
            'severity' => 'Critical',
            'issue' => 'Missing entrypoint file',
            'description' => 'Gateway directory has no PHP files.',
            'code' => 'N/A',
            'fix' => 'Create entrypoint class conforming to GatewayAdapterInterface.'
        ];
        continue;
    }
    
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    if ($content === false) continue;
    
    $lines = explode("\n", $content);
    
    // 1. Check for Simulation Bypass Fallback in initiate()
    // A gateway initiates cURL and if it fails, falls back to a SIM_ redirect. We want to check if it blocks this in live mode.
    if (str_contains($content, 'function initiate(')) {
        $pos = strpos($content, 'function initiate(');
        $initiateBlock = substr($content, $pos, 2500); // scan next 2500 chars
        
        // Find if initiate has a fallback to SIM_ on cURL failure
        if (preg_match('/(curl_exec|\$httpCode|\!\$response).*?SIM_/s', $initiateBlock)) {
            // Check if it checks live mode in the fallback
            // Typically: if ($mode === 'live') { throw ... }
            // Let's see if the word 'live' or '$mode' or 'RuntimeException' is close to 'SIM_' in the fallback
            if (!preg_match('/(\$mode\s*===\s*\'live\'.*?throw)|(throw\s+new\s+\\\\?RuntimeException.*?live)/s', $initiateBlock)) {
                // Fawry, Kushki, Xendit fallback checks
                $report[$slug][] = [
                    'severity' => 'Critical',
                    'issue' => 'Live Mode Simulation Bypass in initiate() cURL Fallback',
                    'description' => 'If the outbound API connection fails or times out, the gateway silently falls back to generating a successful UAT simulation URL with a SIM_ transaction ID. Under live production, this enables malicious actors or simple network glitches to authorize unpaid invoices.',
                    'code' => 'if ($httpCode !== 200 || !$response) { return [ \'redirect_url\' => ... SIM_ ... ]; }',
                    'fix' => 'Throw a RuntimeException or return failed status if $mode === \'live\' inside the cURL error fallback check block.'
                ];
            }
        }
    }
    
    // 2. Check for UAT/Simulation Validation Bypass in verify()
    if (str_contains($content, 'function verify(')) {
        $pos = strpos($content, 'function verify(');
        $verifyBlock = substr($content, $pos, 1500);
        
        if (str_contains($verifyBlock, 'SIM_') || str_contains($verifyBlock, 'SIM_TXN_')) {
            if (!str_contains($verifyBlock, 'live') && !str_contains($verifyBlock, 'RuntimeException')) {
                $report[$slug][] = [
                    'severity' => 'Critical',
                    'issue' => 'Live Mode Simulation Bypass in verify()',
                    'description' => 'The verify() function accepts UAT simulator transaction IDs (like SIM_) and returns successful verification without asserting that the gateway is strictly running in sandbox/test mode. This enables malicious actors to forge paid notifications in production.',
                    'code' => 'if ($token === \'\' || str_starts_with($token, \'SIM_\')) { return [ \'success\' => true, ... ]; }',
                    'fix' => 'Hardcode a strict check: if ($mode === \'live\') { return [\'success\' => false]; } inside the SIM_ verification block.'
                ];
            }
        }
    }
    
    // 3. Check for cURL Timeout configuration issues
    // Check if curl_init is present but CURLOPT_TIMEOUT is missing or too high
    if (str_contains($content, 'curl_init')) {
        $hasTimeout = str_contains($content, 'CURLOPT_TIMEOUT');
        if (!$hasTimeout) {
            $report[$slug][] = [
                'severity' => 'High',
                'issue' => 'Missing Outbound Connection Timeout',
                'description' => 'Gateway executes outbound cURL calls without setting CURLOPT_TIMEOUT. In high-traffic scenarios, slow gateway servers can cause PHP threads to hang indefinitely, exhausting resource limits and triggering server-wide Denial of Service.',
                'code' => 'curl_setopt_array($ch, [...]); // Missing CURLOPT_TIMEOUT',
                'fix' => 'Always configure CURLOPT_TIMEOUT => 15 (or 10) inside all curl_setopt_array blocks.'
            ];
        } else {
            // Check if timeout is excessively high (e.g. > 30 seconds)
            if (preg_match('/CURLOPT_TIMEOUT\s*=>\s*(\d+)/', $content, $matches)) {
                $timeout = (int)$matches[1];
                if ($timeout > 30) {
                    $report[$slug][] = [
                        'severity' => 'Medium',
                        'issue' => 'Excessive Outbound Connection Timeout',
                        'description' => 'The configured cURL timeout is higher than 30 seconds (configured: ' . $timeout . 's). This exposes the host thread pool to prolonged resource starvation if the processor experiences slow response times.',
                        'code' => $matches[0],
                        'fix' => 'Reduce CURLOPT_TIMEOUT to 10 or 15 seconds.'
                    ];
                }
            }
        }
    }
    
    // 4. Check for Safe Array Access on Decoded JSON payloads
    // Check if it accesses $data['something'] directly after json_decode without is_array or type check
    // e.g. $data = json_decode($response, true); $redirectUrl = $data['url'];
    if (str_contains($content, 'json_decode')) {
        // Find json_decode occurrences and scan the surrounding block
        $pos = 0;
        while (($pos = strpos($content, 'json_decode', $pos)) !== false) {
            $surrounding = substr($content, $pos, 800);
            // Check if it accesses offset like $data['something'] directly
            // Regex to find variable names assigned near json_decode, e.g. $data = json_decode(...)
            if (preg_match('/\$(\w+)\s*=\s*json_decode/i', $surrounding, $varMatches)) {
                $varName = $varMatches[1];
                // Check if we access offset e.g. $varName['key'] without checking if is_array($varName)
                if (preg_match('/\$' . $varName . '\[\'\w+\'\]/', $surrounding)) {
                    // Check if is_array($varName) or similar guard is present
                    if (!str_contains($surrounding, 'is_array($' . $varName . ')') && !str_contains($surrounding, 'is_array( $' . $varName . ')')) {
                        $report[$slug][] = [
                            'severity' => 'High',
                            'issue' => 'Unsafe Offset Access on Mixed JSON Payload',
                            'description' => 'The gateway decodes JSON response payloads and accesses array offsets directly without confirming if the decoded result is a valid array. If the remote API returns an error response, null, or invalid JSON, this triggers unhandled TypeErrors or OffsetAccess on mixed exceptions under PHP 8.x.',
                            'code' => '$data = json_decode($response, true); $url = $data[\'url\'];',
                            'fix' => 'Wrap offset access within is_array() checks or utilize $this->getArray() and $this->getString() helper guards.'
                        ];
                        break;
                    }
                }
            }
            $pos += 11;
        }
    }
}

file_put_contents(__DIR__ . '/comprehensive_audit_report.json', json_encode($report, JSON_PRETTY_PRINT));
echo "Successfully compiled comprehensive audit report for " . count($report) . " gateways.\n";
