<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use OwnPay\Service\System\HttpClient;

class HttpClientTest extends TestCase
{
    public function testGetReturnsNullForInvalidUrl(): void
    {
        $result = HttpClient::get('https://this-domain-does-not-exist-12345.invalid', 2);
        $this->assertNull($result);
    }

    public function testGetReturnsStringForValidUrl(): void
    {
        // Use a reliable public URL
        $result = HttpClient::get('https://httpbin.org/get', 5);

        if ($result === null) {
            $this->markTestSkipped('Network unavailable â€” cannot reach httpbin.org');
        }

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
    }
}

