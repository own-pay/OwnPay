<?php
declare(strict_types=1);

$requested = [
    // Global & Aggregators
    'dlocal', 'checkout-com', 'rapyd', 'braintree', 'paddle', 'fastspring', 'worldpay', 'global-payments', 'fiserv', 'first-data', 'authorize-net', 'bluesnap', 'shift4', 'payoneer', 'skrill', 'neteller', '2checkout', 'cybersource', 'trustcommerce',
    // North America
    'chase-paymentech', 'elavon', 'heartland', 'tsys', 'moneris', 'helcim', 'stax', 'payment-depot', 'payline-data', 'fattmerchant', 'nmi', 'paytrace', 'biller-genie',
    // Europe
    'trustly', 'nexi', 'multisafepay', 'payu', 'mangopay', 'securionpay', 'clearhaus', 'swedbank-pay', 'vipps', 'mobilepay', 'swish', 'blik', 'przelewy24', 'sofort', 'giropay', 'eps', 'mybank', 'paytrail', 'nets', 'cardinity',
    // Latin America
    'ebanx', 'kushki', 'payu-latam', 'cielo', 'rede', 'getnet', 'clip', 'conekta', 'kueski', 'pse', 'transbank', 'webpay',
    // Asia-Pacific
    'omise', '2c2p', 'midtrans', 'windcave', 'paytm', 'xendit', 'billdesk', 'payu-india', 'cashfree', 'pine-labs', 'paymate', 'hitpay', 'eway', 'ezidebit', 'securepay', 'paymark', 'poli', 'unionpay', 'tenpay', 'fonepay', 'esewa', 'khalti', 'ime-pay', 'aamarpay', 'portwallet', 'shurjopay', 'senangpay', 'ipay88', 'billplz',
    // Middle East & North Africa
    'paytabs', 'fawry', 'hyperpay', 'tap-payments', 'telr', 'amazon-pay', 'moyasar', 'myfatoorah', 'sadad', 'knet', 'benefit', 'naps',
    // Sub-Saharan Africa
    'dpo-group', 'peach-payments', 'interswitch', 'payfast', 'yoco', 'cellulant', 'opay', 'monnify', 'remita', 'paga', 'zapper', 'snapscan', 'selcom', 'pesapal', 'tingg'
];

$dirPath = __DIR__ . '/../modules/gateways';
$directories = [];
if (is_dir($dirPath)) {
    foreach (scandir($dirPath) as $d) {
        if ($d !== '.' && $d !== '..') {
            $directories[] = $d;
        }
    }
}

$present = [];
$missing = [];

foreach ($requested as $req) {
    // Normalise
    $found = false;
    foreach ($directories as $dir) {
        if (strcasecmp($dir, $req) === 0 || str_replace('-', '', $dir) === str_replace('-', '', $req)) {
            $present[$req] = $dir;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $missing[] = $req;
    }
}

echo "Total Requested: " . count($requested) . "\n";
echo "Present: " . count($present) . "\n";
echo "Missing: " . count($missing) . " (" . implode(', ', $missing) . ")\n\n";

echo "Subdirectories in modules/gateways not in requested list:\n";
foreach ($directories as $dir) {
    if (!in_array($dir, $present)) {
        echo "  - $dir\n";
    }
}
