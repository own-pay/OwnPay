<?php

declare(strict_types=1);

namespace Tests\Security;

use OwnPay\Security\PiiMasker;
use PHPUnit\Framework\TestCase;

final class PiiMaskerTest extends TestCase
{
    private PiiMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new PiiMasker();
    }

    public function test_mask_email(): void
    {
        $data = ['email' => 'john@example.com'];
        $result = $this->masker->mask($data);

        $this->assertNotEquals('john@example.com', $result['email']);
        $this->assertStringContainsString('@', $result['email']);
        $this->assertStringContainsString('***', $result['email']);
    }

    public function test_mask_phone(): void
    {
        $data = ['phone' => '+8801712345678'];
        $result = $this->masker->mask($data);

        $this->assertNotEquals('+8801712345678', $result['phone']);
        $this->assertStringContainsString('***', $result['phone']);
    }

    public function test_mask_name(): void
    {
        $data = ['name' => 'John Doe'];
        $result = $this->masker->mask($data);

        $this->assertNotEquals('John Doe', $result['name']);
        $this->assertStringStartsWith('J', $result['name']);
    }

    public function test_non_sensitive_fields_untouched(): void
    {
        $data = ['amount' => '100.50', 'currency' => 'BDT'];
        $result = $this->masker->mask($data);

        $this->assertSame('100.50', $result['amount']);
        $this->assertSame('BDT', $result['currency']);
    }

    public function test_nested_array_masking(): void
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
