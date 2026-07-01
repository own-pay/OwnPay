<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Service\System\InputSanitizer;

class SecurityTest extends TestCase
{
    public function testInputSanitizerString(): void
    {
        $this->assertSame('hello world', InputSanitizer::string('<script>hello world</script>'));
    }

    public function testInputSanitizerSlug(): void
    {
        $slug = InputSanitizer::slug('Hello World! @#$');
        $this->assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $slug);
    }

    public function testInputSanitizerDecimal(): void
    {
        $this->assertSame('100.50', InputSanitizer::decimal('100.50'));
        $this->assertSame('0.00', InputSanitizer::decimal('not-a-number'));
    }

    public function testCsrfTokenGeneration(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testArgon2idHashVerify(): void
    {
        $password = 'TestP@ssw0rd123';
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrong', $hash));
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testXssEscaping(): void
    {
        $input = '<img src=x onerror=alert(1)>';
        $escaped = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('<', $escaped);
        $this->assertStringNotContainsString('>', $escaped);
        $this->assertStringContainsString('&lt;', $escaped);
    }

    public function testSqlInjectionInPrefix(): void
    {
        // Installer validates prefix with regex
        $valid = preg_match('/^[a-z0-9_]{1,30}$/i', 'op_');
        $this->assertSame(1, $valid);

        $invalid = preg_match('/^[a-z0-9_]{1,30}$/i', "op_'; DROP TABLE--");
        $this->assertSame(0, $invalid);
    }
}
