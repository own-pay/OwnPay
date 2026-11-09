<?php

declare(strict_types=1);

namespace OwnPay\Modules\Gateways\BinancePersonal {
    // Keep provider responses local while exercising the adapter's actual verification path.
    function curl_init(string $url): \CurlHandle|false { return \curl_init($url); }
    function curl_exec(\CurlHandle $handle): string|bool {
        return array_shift(\Tests\Unit\BinancePersonalGatewayTest::$responses) ?? false;
    }
}

