<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use OwnPay\Service\System\HttpClient;

class HttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        HttpClient::$mockResponses = null;
        parent::tearDown();
    }

    public function testGetReturnsNullForInvalidUrl(): void
    {
        HttpClient::$mockResponses = [];
        $result = HttpClient::get('https://this-domain-does-not-exist-12345.invalid', 2);
        $this->assertNull($result);
    }

    public function testGetReturnsStringForValidUrl(): void
    {
        HttpClient::$mockResponses = [
            'https://httpbin.org/get' => [
                'status' => 200,
                'body' => (string) json_encode(['url' => 'https://httpbin.org/get']),
                'headers' => ['Content-Type' => 'application/json']
            ]
        ];

        $result = HttpClient::get('https://httpbin.org/get', 5);

        $this->assertNotNull($result);
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
    }
}
