<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use OwnPay\Security\PiiMasker;

class PiiMaskerTest extends TestCase
{
    private PiiMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new PiiMasker();
    }

    public function testMaskEmail(): void
    {
        $data = ['email' => 'john@example.com'];
        $result = $this->masker->mask($data);

        $this->assertNotEquals('john@example.com', $result['email']);
        $this->assertStringContainsString('@', $result['email']);
        $this->assertStringContainsString('***', $result['email']);
    }

    public function testMaskPhone(): void
    {
        $data = ['phone' => '+8801712345678'];
        $result = $this->masker->mask($data);

        $this->assertNotEquals('+8801712345678', $result['phone']);
        $this->assertStringContainsString('***', $result['phone']);
    }

    public function testMaskName(): void
    {
        $data = ['name' => 'John Doe'];
        $result = $this->masker->mask($data);

        $this->assertNotEquals('John Doe', $result['name']);
        $this->assertStringStartsWith('J', $result['name']);
    }

    public function testNonSensitiveFieldsUntouched(): void
    {
        $data = ['amount' => '100.50', 'currency' => 'BDT'];
        $result = $this->masker->mask($data);

        $this->assertEquals('100.50', $result['amount']);
        $this->assertEquals('BDT', $result['currency']);
    }

    public function testNestedArrayMasking(): void
    {
        $data = [
            'customer' => [
                'email' => 'test@test.com',
                'name' => 'Test User',
            ],
        ];
        $result = $this->masker->mask($data);

        $this->assertStringContainsString('***', $result['customer']['email']);
        $this->assertStringContainsString('***', $result['customer']['name']);
    }
}

