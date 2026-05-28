<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__) . '/modules/gateways';

$gatewayInfo = [
    'adyen' => [
        'category' => 'global',
        'color' => '#00CC66',
        'csp' => [
            'script_src' => ['https://*.adyen.com', 'https://*.adyenpayments.com'],
            'style_src' => ['https://*.adyen.com', 'https://*.adyenpayments.com'],
            'frame_src' => ['https://*.adyen.com', 'https://*.adyenpayments.com'],
            'connect_src' => ['https://*.adyen.com', 'https://*.adyenpayments.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'square' => [
        'category' => 'global',
        'color' => '#000000',
        'csp' => [
            'script_src' => ['https://*.squareup.com', 'https://*.squareupsandbox.com'],
            'style_src' => ['https://*.squareup.com', 'https://*.squareupsandbox.com'],
            'frame_src' => ['https://*.squareup.com', 'https://*.squareupsandbox.com'],
            'connect_src' => ['https://*.squareup.com', 'https://*.squareupsandbox.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'wise' => [
        'category' => 'global',
        'color' => '#00E676',
        'csp' => [
            'script_src' => ['https://*.wise.com', 'https://*.transferwise.tech'],
            'style_src' => ['https://*.wise.com', 'https://*.transferwise.tech'],
            'frame_src' => ['https://*.wise.com', 'https://*.transferwise.tech'],
            'connect_src' => ['https://*.wise.com', 'https://*.transferwise.tech']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'razorpay' => [
        'category' => 'bank',
        'color' => '#3399FF',
        'csp' => [
            'script_src' => ['https://*.razorpay.com', 'https://checkout.razorpay.com'],
            'style_src' => ['https://*.razorpay.com'],
            'frame_src' => ['https://*.razorpay.com', 'https://api.razorpay.com'],
            'connect_src' => ['https://*.razorpay.com', 'https://api.razorpay.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'phonepe' => [
        'category' => 'mfs',
        'color' => '#5F259F',
        'csp' => [
            'script_src' => ['https://*.phonepe.com'],
            'style_src' => ['https://*.phonepe.com'],
            'frame_src' => ['https://*.phonepe.com'],
            'connect_src' => ['https://*.phonepe.com', 'https://api.phonepe.com', 'https://api-preprod.phonepe.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'ccavenue' => [
        'category' => 'bank',
        'color' => '#F58220',
        'csp' => [
            'script_src' => ['https://*.ccavenue.com', 'https://test.ccavenue.com'],
            'style_src' => ['https://*.ccavenue.com'],
            'frame_src' => ['https://*.ccavenue.com'],
            'connect_src' => ['https://*.ccavenue.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'rocket' => [
        'category' => 'mfs',
        'color' => '#8C2070',
        'csp' => [
            'script_src' => ['https://*.dutchbanglabank.com'],
            'style_src' => ['https://*.dutchbanglabank.com'],
            'frame_src' => ['https://*.dutchbanglabank.com'],
            'connect_src' => ['https://*.dutchbanglabank.com', 'https://sandbox.dutchbanglabank.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'upay' => [
        'category' => 'mfs',
        'color' => '#FFCC00',
        'csp' => [
            'script_src' => ['https://*.upay.com.bd'],
            'style_src' => ['https://*.upay.com.bd'],
            'frame_src' => ['https://*.upay.com.bd'],
            'connect_src' => ['https://*.upay.com.bd', 'https://api.upay.com.bd', 'https://sandbox.upay.com.bd']
        ],
        'permissions' => ['gateway.process']
    ],
    'promptpay' => [
        'category' => 'mfs',
        'color' => '#003B70',
        'csp' => [
            'script_src' => ['https://*.promptpay.io'],
            'style_src' => ['https://*.promptpay.io'],
            'frame_src' => ['https://*.promptpay.io'],
            'connect_src' => ['https://*.promptpay.io']
        ],
        'permissions' => ['gateway.process']
    ],
    'gcash' => [
        'category' => 'mfs',
        'color' => '#1976D2',
        'csp' => [
            'script_src' => ['https://*.gcash.com', 'https://*.alipay.com'],
            'style_src' => ['https://*.gcash.com'],
            'frame_src' => ['https://*.gcash.com'],
            'connect_src' => ['https://*.gcash.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'ovo' => [
        'category' => 'mfs',
        'color' => '#4C2A86',
        'csp' => [
            'script_src' => ['https://*.ovo.id'],
            'style_src' => ['https://*.ovo.id'],
            'frame_src' => ['https://*.ovo.id'],
            'connect_src' => ['https://*.ovo.id']
        ],
        'permissions' => ['gateway.process']
    ],
    'dana' => [
        'category' => 'mfs',
        'color' => '#108EE9',
        'csp' => [
            'script_src' => ['https://*.dana.id'],
            'style_src' => ['https://*.dana.id'],
            'frame_src' => ['https://*.dana.id'],
            'connect_src' => ['https://*.dana.id']
        ],
        'permissions' => ['gateway.process']
    ],
    'maya' => [
        'category' => 'mfs',
        'color' => '#00F076',
        'csp' => [
            'script_src' => ['https://*.maya.ph', 'https://*.paymaya.com'],
            'style_src' => ['https://*.maya.ph'],
            'frame_src' => ['https://*.maya.ph'],
            'connect_src' => ['https://*.maya.ph', 'https://pg-sandbox.paymaya.com', 'https://pg.paymaya.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'grabpay' => [
        'category' => 'mfs',
        'color' => '#00B14F',
        'csp' => [
            'script_src' => ['https://*.grab.com', 'https://*.grabpay.com'],
            'style_src' => ['https://*.grab.com'],
            'frame_src' => ['https://*.grab.com'],
            'connect_src' => ['https://*.grab.com', 'https://api.grab.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'alipay' => [
        'category' => 'global',
        'color' => '#00A4FF',
        'csp' => [
            'script_src' => ['https://*.alipay.com', 'https://*.alipayobjects.com'],
            'style_src' => ['https://*.alipay.com'],
            'frame_src' => ['https://*.alipay.com'],
            'connect_src' => ['https://*.alipay.com', 'https://*.alipayobjects.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'wechat-pay' => [
        'category' => 'global',
        'color' => '#09BB07',
        'csp' => [
            'script_src' => ['https://*.tenpay.com', 'https://*.wechat.com'],
            'style_src' => ['https://*.wechat.com'],
            'frame_src' => ['https://*.wechat.com'],
            'connect_src' => ['https://*.tenpay.com', 'https://api.mch.weixin.qq.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'klarna' => [
        'category' => 'global',
        'color' => '#FFB3C7',
        'csp' => [
            'script_src' => ['https://*.klarna.com', 'https://*.klarnacdn.net'],
            'style_src' => ['https://*.klarna.com', 'https://*.klarnacdn.net'],
            'frame_src' => ['https://*.klarna.com'],
            'connect_src' => ['https://*.klarna.com', 'https://api.klarna.com', 'https://api.playground.klarna.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'mollie' => [
        'category' => 'global',
        'color' => '#202020',
        'csp' => [
            'script_src' => ['https://*.mollie.com'],
            'style_src' => ['https://*.mollie.com'],
            'frame_src' => ['https://*.mollie.com'],
            'connect_src' => ['https://*.mollie.com', 'https://api.mollie.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'bancontact' => [
        'category' => 'bank',
        'color' => '#FFE600',
        'csp' => [
            'script_src' => ['https://*.bancontact.com', 'https://*.mollie.com'],
            'style_src' => ['https://*.bancontact.com', 'https://*.mollie.com'],
            'frame_src' => ['https://*.bancontact.com', 'https://*.mollie.com'],
            'connect_src' => ['https://*.bancontact.com', 'https://*.mollie.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'ideal' => [
        'category' => 'bank',
        'color' => '#EC008C',
        'csp' => [
            'script_src' => ['https://*.ideal.nl', 'https://*.mollie.com'],
            'style_src' => ['https://*.ideal.nl', 'https://*.mollie.com'],
            'frame_src' => ['https://*.ideal.nl', 'https://*.mollie.com'],
            'connect_src' => ['https://*.ideal.nl', 'https://*.mollie.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'worldline' => [
        'category' => 'bank',
        'color' => '#0066B3',
        'csp' => [
            'script_src' => ['https://*.worldline-solutions.com', 'https://*.e-merchant.com'],
            'style_src' => ['https://*.worldline-solutions.com'],
            'frame_src' => ['https://*.worldline-solutions.com'],
            'connect_src' => ['https://*.worldline-solutions.com', 'https://payment.worldline-solutions.com', 'https://payment.sandbox.worldline-solutions.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'paystack' => [
        'category' => 'global',
        'color' => '#3ECF8E',
        'csp' => [
            'script_src' => ['https://*.paystack.co', 'https://js.paystack.co'],
            'style_src' => ['https://*.paystack.co'],
            'frame_src' => ['https://*.paystack.co', 'https://checkout.paystack.com'],
            'connect_src' => ['https://*.paystack.co', 'https://api.paystack.co']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'flutterwave' => [
        'category' => 'global',
        'color' => '#F5A623',
        'csp' => [
            'script_src' => ['https://*.flutterwave.com', 'https://api.flutterwave.com'],
            'style_src' => ['https://*.flutterwave.com'],
            'frame_src' => ['https://*.flutterwave.com'],
            'connect_src' => ['https://*.flutterwave.com', 'https://api.flutterwave.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'mercadopago' => [
        'category' => 'global',
        'color' => '#00B1EA',
        'csp' => [
            'script_src' => ['https://*.mercadopago.com', 'https://*.mercadolibre.com'],
            'style_src' => ['https://*.mercadopago.com'],
            'frame_src' => ['https://*.mercadopago.com'],
            'connect_src' => ['https://*.mercadopago.com', 'https://api.mercadopago.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'pagseguro' => [
        'category' => 'global',
        'color' => '#00B1EA',
        'csp' => [
            'script_src' => ['https://*.pagseguro.uol.com.br', 'https://assets.pagseguro.com.br'],
            'style_src' => ['https://*.pagseguro.uol.com.br'],
            'frame_src' => ['https://*.pagseguro.uol.com.br'],
            'connect_src' => ['https://*.pagseguro.uol.com.br', 'https://api.pagseguro.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'mercadolibre-wallet' => [
        'category' => 'global',
        'color' => '#FFE600',
        'csp' => [
            'script_src' => ['https://*.mercadolibre.com', 'https://*.mercadopago.com'],
            'style_src' => ['https://*.mercadolibre.com'],
            'frame_src' => ['https://*.mercadolibre.com'],
            'connect_src' => ['https://*.mercadolibre.com', 'https://api.mercadopago.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'mpesa' => [
        'category' => 'mfs',
        'color' => '#4EAD4A',
        'csp' => [
            'script_src' => ['https://*.safaricom.co.ke'],
            'style_src' => ['https://*.safaricom.co.ke'],
            'frame_src' => ['https://*.safaricom.co.ke'],
            'connect_src' => ['https://*.safaricom.co.ke', 'https://api.safaricom.co.ke']
        ],
        'permissions' => ['gateway.process']
    ],
    'airtel-money' => [
        'category' => 'mfs',
        'color' => '#FF0000',
        'csp' => [
            'script_src' => ['https://*.airtel.com', 'https://*.airtel.in'],
            'style_src' => ['https://*.airtel.com'],
            'frame_src' => ['https://*.airtel.com'],
            'connect_src' => ['https://*.airtel.com', 'https://openapi.airtel.africa']
        ],
        'permissions' => ['gateway.process']
    ],
    'jazzcash' => [
        'category' => 'mfs',
        'color' => '#FFCC00',
        'csp' => [
            'script_src' => ['https://*.jazzcash.com.pk'],
            'style_src' => ['https://*.jazzcash.com.pk'],
            'frame_src' => ['https://*.jazzcash.com.pk'],
            'connect_src' => ['https://*.jazzcash.com.pk', 'https://sandbox.jazzcash.com.pk']
        ],
        'permissions' => ['gateway.process']
    ],
    'easypaisa' => [
        'category' => 'mfs',
        'color' => '#009944',
        'csp' => [
            'script_src' => ['https://*.easypaisa.com.pk'],
            'style_src' => ['https://*.easypaisa.com.pk'],
            'frame_src' => ['https://*.easypaisa.com.pk'],
            'connect_src' => ['https://*.easypaisa.com.pk', 'https://easypay.easypaisa.com.pk']
        ],
        'permissions' => ['gateway.process']
    ],
    'kakaopay' => [
        'category' => 'global',
        'color' => '#FFCD00',
        'csp' => [
            'script_src' => ['https://*.kakao.com', 'https://*.kakaopay.com'],
            'style_src' => ['https://*.kakao.com'],
            'frame_src' => ['https://*.kakao.com'],
            'connect_src' => ['https://*.kakao.com', 'https://kapi.kakao.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'toss' => [
        'category' => 'global',
        'color' => '#0064FF',
        'csp' => [
            'script_src' => ['https://*.tosspayments.com', 'https://js.tosspayments.com'],
            'style_src' => ['https://*.tosspayments.com'],
            'frame_src' => ['https://*.tosspayments.com'],
            'connect_src' => ['https://*.tosspayments.com', 'https://api.tosspayments.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'payme' => [
        'category' => 'global',
        'color' => '#E60028',
        'csp' => [
            'script_src' => ['https://*.hsbc.com.hk', 'https://*.payme.hsbc.com.hk'],
            'style_src' => ['https://*.hsbc.com.hk'],
            'frame_src' => ['https://*.hsbc.com.hk'],
            'connect_src' => ['https://*.hsbc.com.hk', 'https://api.payme.hsbc.com.hk', 'https://sandbox.api.payme.hsbc.com.hk']
        ],
        'permissions' => ['gateway.process']
    ],
    'pix' => [
        'category' => 'bank',
        'color' => '#32B4A4',
        'csp' => [
            'script_src' => ['https://*.mercadopago.com', 'https://*.mercadolibre.com'],
            'style_src' => ['https://*.mercadopago.com'],
            'frame_src' => ['https://*.mercadopago.com'],
            'connect_src' => ['https://*.mercadopago.com', 'https://api.mercadopago.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'coinbase-commerce' => [
        'category' => 'global',
        'color' => '#0052FF',
        'csp' => [
            'script_src' => ['https://*.coinbase.com', 'https://*.commerce.coinbase.com'],
            'style_src' => ['https://*.coinbase.com'],
            'frame_src' => ['https://*.coinbase.com'],
            'connect_src' => ['https://*.coinbase.com', 'https://api.commerce.coinbase.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'btcpay' => [
        'category' => 'global',
        'color' => '#F5A623',
        'csp' => [
            'script_src' => ['https://*'],
            'style_src' => ['https://*'],
            'frame_src' => ['https://*'],
            'connect_src' => ['https://*']
        ],
        'permissions' => ['gateway.process']
    ],
    'opennode' => [
        'category' => 'global',
        'color' => '#1A1A1A',
        'csp' => [
            'script_src' => ['https://*.opennode.com'],
            'style_src' => ['https://*.opennode.com'],
            'frame_src' => ['https://*.opennode.com'],
            'connect_src' => ['https://*.opennode.com', 'https://api.opennode.com', 'https://dev-api.opennode.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'now-payments' => [
        'category' => 'global',
        'color' => '#4CC38A',
        'csp' => [
            'script_src' => ['https://*.nowpayments.io'],
            'style_src' => ['https://*.nowpayments.io'],
            'frame_src' => ['https://*.nowpayments.io'],
            'connect_src' => ['https://*.nowpayments.io', 'https://api.nowpayments.io', 'https://api-sandbox.nowpayments.io']
        ],
        'permissions' => ['gateway.process']
    ],
    'binance-merchant-api' => [
        'category' => 'global',
        'color' => '#F0B90B',
        'csp' => [
            'script_src' => ['https://*.binanceapi.com', 'https://*.binance.com'],
            'style_src' => ['https://*.binance.com'],
            'frame_src' => ['https://*.binance.com'],
            'connect_src' => ['https://*.binanceapi.com', 'https://bpay.binanceapi.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'binance-personal' => [
        'category' => 'global',
        'color' => '#F3BA2F',
        'csp' => [
            'script_src' => ['https://*.binance.com'],
            'style_src' => ['https://*.binance.com'],
            'frame_src' => ['https://*.binance.com'],
            'connect_src' => ['https://*.binance.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'paypal-checkout' => [
        'category' => 'global',
        'color' => '#003087',
        'csp' => [
            'script_src' => ['https://*.paypal.com', 'https://*.paypalobjects.com'],
            'style_src' => ['https://*.paypal.com'],
            'frame_src' => ['https://*.paypal.com'],
            'connect_src' => ['https://*.paypal.com', 'https://*.paypalobjects.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'billplz' => [
        'category' => 'mfs',
        'color' => '#00AFEC',
        'csp' => [
            'script_src' => ['https://*.billplz.com'],
            'style_src' => ['https://*.billplz.com'],
            'frame_src' => ['https://*.billplz.com'],
            'connect_src' => ['https://*.billplz.com', 'https://www.billplz.com/api']
        ],
        'permissions' => ['gateway.process']
    ],
    'momo' => [
        'category' => 'mfs',
        'color' => '#A50064',
        'csp' => [
            'script_src' => ['https://*.momo.vn'],
            'style_src' => ['https://*.momo.vn'],
            'frame_src' => ['https://*.momo.vn'],
            'connect_src' => ['https://*.momo.vn', 'https://payment.momo.vn']
        ],
        'permissions' => ['gateway.process']
    ],
    'mtn-momo' => [
        'category' => 'mfs',
        'color' => '#FFCC00',
        'csp' => [
            'script_src' => ['https://*.mtn.com', 'https://*.momodeveloper.mtn.com'],
            'style_src' => ['https://*.mtn.com'],
            'frame_src' => ['https://*.mtn.com'],
            'connect_src' => ['https://*.mtn.com', 'https://sandbox.momodeveloper.mtn.com', 'https://api.momodeveloper.mtn.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'myfatoorah' => [
        'category' => 'global',
        'color' => '#00A9E0',
        'csp' => [
            'script_src' => ['https://*.myfatoorah.com'],
            'style_src' => ['https://*.myfatoorah.com'],
            'frame_src' => ['https://*.myfatoorah.com'],
            'connect_src' => ['https://*.myfatoorah.com', 'https://api.myfatoorah.com', 'https://api-sandbox.myfatoorah.com']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'opay' => [
        'category' => 'mfs',
        'color' => '#00B5A3',
        'csp' => [
            'script_src' => ['https://*.opayweb.com'],
            'style_src' => ['https://*.opayweb.com'],
            'frame_src' => ['https://*.opayweb.com'],
            'connect_src' => ['https://*.opayweb.com', 'https://api.opayweb.com', 'https://sandbox-api.opayweb.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'orange-money' => [
        'category' => 'mfs',
        'color' => '#FF6600',
        'csp' => [
            'script_src' => ['https://*.orange.com', 'https://*.orange-money.com'],
            'style_src' => ['https://*.orange.com'],
            'frame_src' => ['https://*.orange.com'],
            'connect_src' => ['https://*.orange.com', 'https://api.orange.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'shopeepay' => [
        'category' => 'mfs',
        'color' => '#EE4D2D',
        'csp' => [
            'script_src' => ['https://*.shopee.com', 'https://*.shopeepay.com'],
            'style_src' => ['https://*.shopee.com'],
            'frame_src' => ['https://*.shopee.com'],
            'connect_src' => ['https://*.shopee.com', 'https://api.shopeepay.com']
        ],
        'permissions' => ['gateway.process']
    ],
    'tap-payments' => [
        'category' => 'global',
        'color' => '#00A3FF',
        'csp' => [
            'script_src' => ['https://*.tap.company'],
            'style_src' => ['https://*.tap.company'],
            'frame_src' => ['https://*.tap.company'],
            'connect_src' => ['https://*.tap.company', 'https://api.tap.company']
        ],
        'permissions' => ['gateway.process', 'gateway.refund']
    ],
    'touch-n-go' => [
        'category' => 'mfs',
        'color' => '#0052B4',
        'csp' => [
            'script_src' => ['https://*.tngdigital.com.my'],
            'style_src' => ['https://*.tngdigital.com.my'],
            'frame_src' => ['https://*.tngdigital.com.my'],
            'connect_src' => ['https://*.tngdigital.com.my', 'https://api.tngdigital.com.my']
        ],
        'permissions' => ['gateway.process']
    ],
    'truemoney' => [
        'category' => 'mfs',
        'color' => '#FF8F00',
        'csp' => [
            'script_src' => ['https://*.truemoney.com'],
            'style_src' => ['https://*.truemoney.com'],
            'frame_src' => ['https://*.truemoney.com'],
            'connect_src' => ['https://*.truemoney.com', 'https://api.truemoney.com']
        ],
        'permissions' => ['gateway.process']
    ]
];

$directories = scandir($baseDir);

foreach ($directories as $dir) {
    if ($dir === '.' || $dir === '..') {
        continue;
    }
    
    $path = $baseDir . '/' . $dir;
    if (is_dir($path)) {
        $manifestPath = $path . '/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (is_array($manifest)) {
                $slug = $manifest['slug'] ?? $dir;
                if (isset($gatewayInfo[$slug])) {
                    $info = $gatewayInfo[$slug];
                    $manifest['category'] = $info['category'];
                    $manifest['color'] = $info['color'];
                    $manifest['csp'] = $info['csp'];
                    $manifest['permissions'] = $info['permissions'];
                    $manifest['icon'] = 'icon.svg';
                    
                    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    echo "Updated manifest fields for: {$slug}\n";
                }
            }
        }
    }
}
echo "All manifests updated with full fields compatibility!\n";
