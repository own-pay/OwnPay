<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use OwnPay\Security\LogSanitizer;

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

    // ── F14: extended PII coverage ──────────────────────────────────────────

    public function testSanitize19DigitMaestroCard(): void
    {
        // Maestro / UnionPay can be 19 digits
        $input = 'Card: 6759 6498 2643 8453 1239 charged $100';
        $result = $this->sanitizer->sanitizeString($input);
        $this->assertStringContainsString('[CARD_REDACTED]', $result);
        $this->assertStringNotContainsString('6759 6498 2643 8453 1239', $result);
    }

    public function testSanitize13DigitNidInStrictMode(): void
    {
        // Bangladesh NID-13. Note: the BD-phone regex may pre-empt and redact
        // a 10-digit substring as [PHONE_REDACTED]; either redaction is acceptable
        // — the key property is that the raw 13-digit NID does NOT survive.
        $strict = new LogSanitizer(true);
        $input = 'NID 1990123456789 verified';
        $result = $strict->sanitizeString($input);
        $this->assertStringNotContainsString('1990123456789', $result);
    }

    public function testSanitize17DigitNidInStrictMode(): void
    {
        // Bangladesh NID-17 (smart NID)
        $strict = new LogSanitizer(true);
        $input = 'Smart NID 19880123456781234 issued 2024';
        $result = $strict->sanitizeString($input);
        $this->assertStringNotContainsString('19880123456781234', $result);
    }

    public function testSanitizeFieldNamedSigningSecret(): void
    {
        $data = [
            'webhook_url'    => 'https://example.com/hook',
            'signing_secret' => 'whsec_abc123def456',
            'event_type'     => 'payment.completed',
        ];
        $result = $this->sanitizer->sanitizeArray($data);
        $this->assertEquals('[REDACTED]', $result['signing_secret']);
        $this->assertEquals('https://example.com/hook', $result['webhook_url']);
    }

    public function testSanitizeAuthorizationField(): void
    {
        $data = ['authorization' => 'Bearer eyJhbGc...'];
        $result = $this->sanitizer->sanitizeArray($data);
        $this->assertEquals('[REDACTED]', $result['authorization']);
    }

    public function testSanitizeNestedArrayRedactsAtAnyDepth(): void
    {
        $data = [
            'request' => [
                'headers' => [
                    'authorization' => 'Bearer secret',
                ],
                'body' => [
                    'email' => 'leak@test.com',
                ],
            ],
        ];
        $result = $this->sanitizer->sanitizeArray($data);
        $this->assertEquals('[REDACTED]', $result['request']['headers']['authorization']);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $result['request']['body']['email']);
    }

    public function testSanitizePreservesNonSensitiveFields(): void
    {
        $data = [
            'user_id'    => 12345,
            'merchant'   => 'Acme Co',
            'event_type' => 'invoice.created',
            'amount'     => '99.99',
            'currency'   => 'USD',
        ];
        $result = $this->sanitizer->sanitizeArray($data);
        $this->assertEquals(12345, $result['user_id']);
        $this->assertEquals('Acme Co', $result['merchant']);
        $this->assertEquals('invoice.created', $result['event_type']);
        $this->assertEquals('99.99', $result['amount']);
        $this->assertEquals('USD', $result['currency']);
    }
}
