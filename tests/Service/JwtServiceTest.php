<?php

declare(strict_types=1);

namespace Tests\Service;

use OwnPay\Service\Auth\JwtService;
use PHPUnit\Framework\TestCase;

class JwtServiceTest extends TestCase
{
    private JwtService $jwt;
    private string $secret;

    protected function setUp(): void
    {
        $this->jwt = new JwtService();
        $this->secret = JwtService::generateSecret();
    }

    // â”€â”€ Encoding â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testEncodeReturnsTokenAndExpiry(): void
    {
        $result = $this->jwt->encode('test-uuid', 1, $this->secret);

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('expires_in', $result);
        $this->assertIsString($result['token']);
        $this->assertSame(900, $result['expires_in']); // default 15 min
    }

    public function testEncodeRespectsCustomTtl(): void
    {
        $result = $this->jwt->encode('test-uuid', 1, $this->secret, ['sms:submit'], 60);

        $this->assertSame(60, $result['expires_in']);
        $this->assertGreaterThan(time(), $result['expires_at']);
    }

    // â”€â”€ Decoding â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testDecodeValidToken(): void
    {
        $encoded = $this->jwt->encode('device-abc', 42, $this->secret);
        $result = $this->jwt->decode($encoded['token'], $this->secret);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
        $this->assertSame('device:device-abc', $result['payload']->sub);
        $this->assertSame(42, $result['payload']->brand_id);
    }

    public function testDecodeWithWrongSecretFails(): void
    {
        $encoded = $this->jwt->encode('device-abc', 1, $this->secret);
        $wrongSecret = JwtService::generateSecret();

        $result = $this->jwt->decode($encoded['token'], $wrongSecret);

        $this->assertFalse($result['valid']);
        $this->assertSame('INVALID_SIGNATURE', $result['error']);
    }

    public function testDecodeExpiredTokenReturnsExpiredError(): void
    {
        // Create a token with 0-second TTL (already expired)
        $encoded = $this->jwt->encode('device-abc', 1, $this->secret, [], -1);
        $result = $this->jwt->decode($encoded['token'], $this->secret);

        $this->assertFalse($result['valid']);
        $this->assertSame('TOKEN_EXPIRED', $result['error']);
    }

    public function testDecodeMalformedTokenFails(): void
    {
        $result = $this->jwt->decode('not.a.jwt', $this->secret);

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    public function testDecodeEmptyTokenFails(): void
    {
        $result = $this->jwt->decode('', $this->secret);

        $this->assertFalse($result['valid']);
    }

    // â”€â”€ Device UUID Extraction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testExtractDeviceUuidFromValidSub(): void
    {
        $uuid = $this->jwt->extractDeviceUuid('device:abc-123-def');
        $this->assertSame('abc-123-def', $uuid);
    }

    public function testExtractDeviceUuidReturnsNullForInvalidSub(): void
    {
        $this->assertNull($this->jwt->extractDeviceUuid('user:abc'));
        $this->assertNull($this->jwt->extractDeviceUuid('device:'));
        $this->assertNull($this->jwt->extractDeviceUuid(''));
    }

    // â”€â”€ Secret Generation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testGenerateSecretReturns64HexChars(): void
    {
        $secret = JwtService::generateSecret();
        $this->assertSame(64, strlen($secret));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
    }

    public function testGenerateSecretIsUnique(): void
    {
        $a = JwtService::generateSecret();
        $b = JwtService::generateSecret();
        $this->assertNotSame($a, $b);
    }

    // â”€â”€ Scopes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testTokenContainsScopes(): void
    {
        $scopes = ['sms:submit', 'dashboard:read'];
        $encoded = $this->jwt->encode('dev-1', 1, $this->secret, $scopes);
        $decoded = $this->jwt->decode($encoded['token'], $this->secret);

        $this->assertTrue($decoded['valid']);
        $this->assertSame($scopes, $decoded['payload']->scopes);
    }
}

