<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use OwnPay\Security\UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SSRF defense in UrlValidator (F5 + F7 from full_codebase_audit.md).
 */
final class UrlValidatorTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function test_blocks_ssrf_targets(string $url, string $expectedReasonContains): void
    {
        $reason = null;
        $ok = UrlValidator::isSafeOutbound($url, $reason);
        $this->assertFalse($ok, "URL {$url} should be blocked");
        $this->assertNotNull($reason, "Reason should be populated for blocked URL");
        $this->assertStringContainsStringIgnoringCase(
            $expectedReasonContains, $reason,
            "Reason '{$reason}' should mention '{$expectedReasonContains}'"
        );
    }

    public static function blockedUrls(): array
    {
        return [
            // Loopback (IPv4 literal)
            'IPv4 loopback'             => ['http://127.0.0.1/admin', '127.0.0.1'],
            'IPv4 loopback alt'         => ['http://127.255.255.254/', '127'],
            // RFC1918 private
            'private 10/8'              => ['http://10.0.0.5/', '10.0.0.5'],
            'private 172.16/12'         => ['http://172.16.0.1/', '172.16.0.1'],
            'private 192.168/16'        => ['http://192.168.1.1/', '192.168.1.1'],
            // Cloud metadata (AWS / Azure / GCP all 169.254.169.254)
            'AWS metadata'              => ['http://169.254.169.254/latest/meta-data/', '169.254'],
            // IPv6 loopback / link-local
            'IPv6 loopback'             => ['http://[::1]/', '::1'],
            // Non-HTTP schemes
            'file scheme'               => ['file:///etc/passwd', 'scheme'],
            'gopher scheme'             => ['gopher://localhost:6379/_FLUSHALL', 'scheme'],
            'dict scheme'               => ['dict://localhost:11211/stats', 'scheme'],
            'jar scheme'                => ['jar:http://example.com!/', 'scheme'],
            // Userinfo
            'userinfo'                  => ['http://admin:pwd@example.com/', 'userinfo'],
            // Malformed
            'no scheme'                 => ['localhost/admin', 'scheme'],
            'empty'                     => ['', 'missing'],
        ];
    }

    public function test_allows_legitimate_public_urls(): void
    {
        // Use a public IP literal so the test does not depend on DNS network access.
        $reason = null;
        $ok = UrlValidator::isSafeOutbound('http://93.184.216.34/', $reason); // example.com IP
        $this->assertTrue($ok, "Public IPv4 should pass; reason was: {$reason}");

        $ok = UrlValidator::isSafeOutbound('https://93.184.216.34/path?q=1', $reason);
        $this->assertTrue($ok, "Public IPv4 with HTTPS + path should pass; reason was: {$reason}");
    }

    public function test_allows_https_scheme(): void
    {
        $reason = null;
        $ok = UrlValidator::isSafeOutbound('https://8.8.8.8/', $reason);
        $this->assertTrue($ok, "Public IPv4 over HTTPS should pass; reason was: {$reason}");
    }

    public function test_blocks_uppercase_scheme_too(): void
    {
        $reason = null;
        $ok = UrlValidator::isSafeOutbound('FILE:///etc/passwd', $reason);
        $this->assertFalse($ok);
    }
}
