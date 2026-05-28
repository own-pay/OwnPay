<?php
declare(strict_types=1);

$audit = json_decode(file_get_contents(__DIR__ . '/detailed_audit.json'), true);

echo "| Plugin Slug | Name | Namespace | Endpoints Checked | BCMath | Signature verification | Doc Source |\n";
echo "|-------------|------|-----------|-------------------|--------|------------------------|------------|\n";

foreach ($audit as $slug => $data) {
    // Determine a nice name
    $name = ucwords(str_replace('-', ' ', $slug));
    if ($slug === 'ccavenue') $name = 'CCAvenue';
    if ($slug === 'bkash-api') $name = 'bKash API';
    if ($slug === 'nagad-merchant-api') $name = 'Nagad Merchant';
    if ($slug === 'sslcommerz') $name = 'SSLCommerz';
    if ($slug === 'oxapay') $name = 'OxaPay';
    if ($slug === 'p24') $name = 'Przelewy24';
    if ($slug === 'blik') $name = 'BLIK';
    if ($slug === 'giropay') $name = 'Giropay';
    if ($slug === 'sofort') $name = 'Sofort';
    if ($slug === 'trustly') $name = 'Trustly';

    $endpointsStr = count($data['endpoints']) > 0 ? implode('<br>', array_map('htmlspecialchars', $data['endpoints'])) : '*None (Redirect/Direct/Off-site)*';
    $bcmath = $data['has_bcmath'] ? '✅ Yes' : '❌ No (Decimal/String directly)';
    $sig = htmlspecialchars($data['verify_method']);
    
    // Doc source mapping
    $docSource = 'Internet Search / Developer Guide';
    $ctx7List = ['stripe', 'apple-pay', 'google-pay', 'checkout-com', 'paypal-checkout', 'adyen', 'braintree', 'paddle', 'razorpay', 'bkash-api', 'nagad-merchant-api', 'mollie', 'klarna'];
    if (in_array($slug, $ctx7List)) {
        $docSource = '`ctx7` (Context7)';
    }

    echo "| {$slug} | {$name} | `{$data['namespace']}` | {$endpointsStr} | {$bcmath} | {$sig} | {$docSource} |\n";
}
