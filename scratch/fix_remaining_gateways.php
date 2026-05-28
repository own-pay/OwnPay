<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';

$slugs = [
    '2checkout', 'biller-genie', 'bluesnap', 'chase-paymentech', 'cybersource', 'dlocal', 'elavon',
    'fastspring', 'fattmerchant', 'first-data', 'fiserv', 'global-payments', 'heartland', 'helcim',
    'midtrans', 'moneris', 'neteller', 'nmi', 'payline-data', 'payment-depot', 'payoneer', 'paytrace',
    'rapyd', 'shift4', 'skrill', 'stax', 'trustcommerce', 'tsys', 'worldpay'
];

foreach ($slugs as $slug) {
    $path = $dir . '/' . $slug;
    $phpFiles = glob($path . '/*.php');
    if (empty($phpFiles)) {
        echo "[{$slug}] No PHP files found\n";
        continue;
    }
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    $orig = $content;
    
    // 1. Fix initiate() TCode visual fallback block
    $targetInitiateFallback = '        if ($httpCode !== 200 || !$response) {
            // Emulate fallback visual window for simulated checkout
            return [
                \'redirect_url\' => $params[\'redirect_url\'] . \'?status=PAID&reference=\' . $params[\'trx_id\'] . \'&gateway_trx_id=SIM_\' . uniqid()
            ];
        }';
    
    $replacementInitiateFallback = '        if ($httpCode !== 200 || !$response) {
            if ($mode === \'live\') {
                throw new \RuntimeException(\'Payment initiation failed\');
            }
            // Emulate fallback visual window for simulated checkout
            return [
                \'redirect_url\' => $params[\'redirect_url\'] . \'?status=PAID&reference=\' . $params[\'trx_id\'] . \'&gateway_trx_id=SIM_\' . uniqid()
            ];
        }';
        
    $content = str_replace($targetInitiateFallback, $replacementInitiateFallback, $content);
    
    // 2. Fix initiate() end fallback return block
    $targetInitiateEnd = '        return [
            \'redirect_url\' => $params[\'redirect_url\'] . \'?status=PAID&reference=\' . $params[\'trx_id\'] . \'&gateway_trx_id=SIM_\' . uniqid()
        ];
    }';
    
    $replacementInitiateEnd = '        if ($mode === \'live\') {
            throw new \RuntimeException(\'Payment initiation failed\');
        }
        return [
            \'redirect_url\' => $params[\'redirect_url\'] . \'?status=PAID&reference=\' . $params[\'trx_id\'] . \'&gateway_trx_id=SIM_\' . uniqid()
        ];
    }';
    
    $content = str_replace($targetInitiateEnd, $replacementInitiateEnd, $content);
    
    // 3. Fix verify() !$response fallback block
    $targetVerifyFallback = '        if (!$response) {
            // Simulation Mode: Accept callbacks as valid
            return [
                \'success\'        => true,
                \'gateway_trx_id\' => $this->getString($callbackData[\'gateway_trx_id\'] ?? \'SIM_TXN_\' . uniqid()),
                \'amount\'         => $this->getString($callbackData[\'amount\'] ?? \'0.00\'),
                \'status\'         => \'completed\',
            ];
        }';
        
    $replacementVerifyFallback = '        if (!$response) {
            if ($mode === \'live\') {
                return [
                    \'success\'        => false,
                    \'gateway_trx_id\' => \'\',
                    \'status\'         => \'failed\',
                ];
            }
            // Simulation Mode: Accept callbacks as valid
            return [
                \'success\'        => true,
                \'gateway_trx_id\' => $this->getString($callbackData[\'gateway_trx_id\'] ?? \'SIM_TXN_\' . uniqid()),
                \'amount\'         => $this->getString($callbackData[\'amount\'] ?? \'0.00\'),
                \'status\'         => \'completed\',
            ];
        }';
        
    $content = str_replace($targetVerifyFallback, $replacementVerifyFallback, $content);
    
    // Custom check for Braintree which has unique SIM_ blocks
    if ($slug === 'braintree') {
        // Wait, is Braintree already modified? Let's check.
    }
    
    // Custom check for Midtrans
    if ($slug === 'midtrans') {
        // Let's print out what Midtrans has
    }
    
    if ($content !== $orig) {
        file_put_contents($file, $content);
        echo "[{$slug}] Remediated and saved successfully.\n";
    } else {
        echo "[{$slug}] No changes made (already compliant or signature mismatch).\n";
    }
}
