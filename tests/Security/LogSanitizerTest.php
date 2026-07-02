<?php

declare(strict_types=1);

namespace Tests\Security;

use OwnPay\Security\LogSanitizer;
use PHPUnit\Framework\TestCase;

final class LogSanitizerTest extends TestCase
{
    private LogSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new LogSanitizer();
    }

    public function test_sanitize_email_in_string(): void
    {
        $input = 'User john@example.com failed login';
        $result = $this->sanitizer->sanitizeString($input);

        $this->assertStringNotContainsString('john@example.com', $result);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $result);
    }

    public function test_sanitize_phone_in_string(): void
    {
        $input = 'SMS sent to +8801712345678';
        $result = $this->sanitizer->sanitizeString($input);

        $this->assertStringNotContainsString('+8801712345678', $result);
        $this->assertStringContainsString('[PHONE_REDACTED]', $result);
    }

    public function test_sanitize_array_redacts_sensitive_fields(): void
    {
        $data = [
            'username' => 'john',
            'password' => 'secret123',
            'api_key' => 'sk_live_abc123',
        ];
        $result = $this->sanitizer->sanitizeArray($data);

        $this->assertSame('john', $result['username']);
        $this->assertSame('[REDACTED]', $result['password']);
        $this->assertSame('[REDACTED]', $result['api_key']);
    }

    public function test_sanitize_json_string(): void
    {
        $json = '{"email":"test@test.com","amount":"100"}';
        $result = $this->sanitizer->sanitizeJson($json);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $decoded['email']);
        $this->assertSame('100', $decoded['amount']);
    }

    public function test_sanitize_19_digit_maestro_card(): void
    {
        $input = 'Card: 6759 6498 2643 8453 1239 charged $100';
        $result = $this->sanitizer->sanitizeString($input);
        $this->assertStringContainsString('[CARD_REDACTED]', $result);
        $this->assertStringNotContainsString('6759 6498 2643 8453 1239', $result);
    }

    public function test_sanitize_13_digit_nid_in_strict_mode(): void
    {
        // BD NID-13: the phone regex may pre-empt and redact a 10-digit substring;
        // either redaction is acceptable as long as the raw 13-digit NID does not survive.
        $strict = new LogSanitizer(true);
        $input = 'NID 1990123456789 verified';
        $result = $strict->sanitizeString($input);
        $this->assertStringNotContainsString('1990123456789', $result);
    }

    public function test_sanitize_17_digit_nid_in_strict_mode(): void
    {
        $strict = new LogSanitizer(true);
        $input = 'Smart NID 19880123456781234 issued 2024';
        $result = $strict->sanitizeString($input);
        $this->assertStringNotContainsString('19880123456781234', $result);
    }

    public function test_sanitize_field_named_signing_secret(): void
    {
        $data = [
            'webhook_url'    => 'https://example.com/hook',
            'signing_secret' => 'whsec_abc123def456',
            'event_type'     => 'payment.completed',
        ];
        $result = $this->sanitizer->sanitizeArray($data);
        $this->assertSame('[REDACTED]', $result['signing_secret']);
        $this->assertSame('https://example.com/hook', $result['webhook_url']);
    }

    public function test_sanitize_authorization_field(): void
    {
        $data = ['authorization' => 'Bearer eyJhbGc...'];
        $result = $this->sanitizer->sanitizeArray($data);
        $this->assertSame('[REDACTED]', $result['authorization']);
    }

    public function test_sanitize_nested_array_redacts_at_any_depth(): void
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
        $this->assertSame('[REDACTED]', $result['request']['headers']['authorization']);
        $this->assertStringContainsString('[EMAIL_REDACTED]', $result['request']['body']['email']);
    }

    public function test_sanitize_preserves_non_sensitive_fields(): void
    {
        $data = [
            'user_id'    => 12345,
            'merchant'   => 'Acme Co',
            'event_type' => 'invoice.created',
            'amount'     => '99.99',
            'currency'   => 'USD',
        ];
        $result = $this->sanitizer->sanitizeArray($data);
        $this->assertSame(12345, $result['user_id']);
        $this->assertSame('Acme Co', $result['merchant']);
        $this->assertSame('invoice.created', $result['event_type']);
        $this->assertSame('99.99', $result['amount']);
        $this->assertSame('USD', $result['currency']);
    }
}
