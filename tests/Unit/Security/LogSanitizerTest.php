<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use AnirbanPay\Security\LogSanitizer;

class LogSanitizerTest extends TestCase
{
    private LogSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new LogSanitizer();
    }

    public function testSanitizeEmailInString(): void
    {
        $input = 'User john@example.com failed login';
        $result = $this->sanitizer->sanitizeString($input);

        $this->assertStringNotContainsString('john@example.com', $result);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $result);
    }

    public function testSanitizePhoneInString(): void
    {
        $input = 'SMS sent to +8801712345678';
        $result = $this->sanitizer->sanitizeString($input);

        $this->assertStringNotContainsString('+8801712345678', $result);
        $this->assertStringContainsString('[PHONE_REDACTED]', $result);
    }

    public function testSanitizeArrayRedactsSensitiveFields(): void
    {
        $data = [
            'username' => 'john',
            'password' => 'secret123',
            'api_key' => 'sk_live_abc123',
        ];
        $result = $this->sanitizer->sanitizeArray($data);

        $this->assertEquals('john', $result['username']);
        $this->assertEquals('[REDACTED]', $result['password']);
        $this->assertEquals('[REDACTED]', $result['api_key']);
    }

    public function testSanitizeJsonString(): void
    {
        $json = '{"email":"test@test.com","amount":"100"}';
        $result = $this->sanitizer->sanitizeJson($json);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $decoded['email']);
        $this->assertEquals('100', $decoded['amount']);
    }
}
