<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Controller\Install\InstallerController;
use PHPUnit\Framework\TestCase;

final class InstallerEnvTokenTest extends TestCase
{
    private InstallerController $controller;
    private \ReflectionMethod $envToken;
    private \ReflectionMethod $parseTempEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new InstallerController();
        $this->envToken = new \ReflectionMethod(InstallerController::class, 'envToken');
        $this->parseTempEnv = new \ReflectionMethod(InstallerController::class, 'parseTempEnv');
    }

    private function token(string $raw): string
    {
        return (string) $this->envToken->invoke($this->controller, $raw);
    }

    public function testStripsNewlinesSoNoDirectiveCanBeInjected(): void
    {
        $token = $this->token("secret\nAPP_DEBUG=true");
        $this->assertStringNotContainsString("\n", $token);
        $this->assertSame('"secretAPP_DEBUG=true"', $token);
    }

    public function testStripsCarriageReturnsAndNullBytes(): void
    {
        $this->assertSame('"ab"', $this->token("a\r\nb"));
        $this->assertSame('"ab"', $this->token("a\0b"));
    }

    public function testEscapesQuotesBackslashesAndDollars(): void
    {
        $this->assertSame('"pa\\"ss"', $this->token('pa"ss'));
        $this->assertSame('"a\\\\b"', $this->token('a\\b'));
        $this->assertSame('"\\$ecret"', $this->token('$ecret'));
    }

    public function testRoundTripsThroughParseTempEnv(): void
    {
        $cases = [
            'simple' => 'p@ssw0rd',
            'with spaces' => 'pass word 123',
            'with hash' => 'pa#ss',
            'with equals' => 'pa=ss',
            'with quote' => 'pa"ss',
            'with backslash' => 'pa\\ss',
            'with dollar' => 'pa$ss',
            'injection attempt' => "secretAPP_DEBUG=true",
        ];

        $lines = [];
        $i = 0;
        foreach ($cases as $value) {
            $lines[] = 'DB_PASS' . $i . '=' . $this->token($value);
            $i++;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'op_env_token_test_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, implode("\n", $lines) . "\n");

        /** @var array<string, string> $parsed */
        $parsed = $this->parseTempEnv->invoke($this->controller, $tmp);
        @unlink($tmp);

        $this->assertArrayNotHasKey('APP_DEBUG', $parsed);

        $i = 0;
        foreach ($cases as $label => $value) {
            $this->assertSame($value, $parsed['DB_PASS' . $i] ?? null, "round-trip failed for: {$label}");
            $i++;
        }
    }

    public function testNewlineInjectedPasswordYieldsSingleEnvLine(): void
    {
        $payload = "harmless\nAPP_DEBUG=true\nJWT_SECRET=0000";
        $line = 'DB_PASS=' . $this->token($payload);

        $tmp = tempnam(sys_get_temp_dir(), 'op_env_inject_test_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $line . "\n");

        /** @var array<string, string> $parsed */
        $parsed = $this->parseTempEnv->invoke($this->controller, $tmp);
        @unlink($tmp);

        $this->assertArrayNotHasKey('APP_DEBUG', $parsed);
        $this->assertArrayNotHasKey('JWT_SECRET', $parsed);
        $this->assertSame('harmlessAPP_DEBUG=trueJWT_SECRET=0000', $parsed['DB_PASS'] ?? null);
    }
}
