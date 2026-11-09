<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\DomainRepository;
use OwnPay\Service\Domain\DnsVerifier;
use OwnPay\Service\Domain\DomainService;
use OwnPay\Service\System\HttpClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DNS hint helpers on DomainService: the server-IP chain
 * (APP_SERVER_IP override, then SERVER_ADDR, then the public-IP echo service)
 * and the Cloudflare edge-IP detection that powers the "IP may be a proxy"
 * warning on the custom-domains page.
 */
#[AllowMockObjectsWithoutExpectations]
final class DomainServiceServerIpTest extends TestCase
{
    private function service(): DomainService
    {
        $db = $this->createMock(Database::class);
        return new DomainService(new DomainRepository($db), new DnsVerifier(), EventManager::getInstance());
    }

    private function clearEnv(): void
    {
        unset($_ENV['APP_DOMAIN'], $_ENV['APP_URL'], $_ENV['APP_SERVER_IP'], $_SERVER['SERVER_ADDR']);
        putenv('APP_DOMAIN');
        putenv('APP_URL');
        putenv('APP_SERVER_IP');
    }

    private function stubPublicIpService(string $body): void
    {
        HttpClient::$mockResponses = [
            'https://icanhazip.com' => ['status' => 200, 'body' => $body, 'headers' => []],
        ];
    }

    protected function setUp(): void
    {
        $this->clearEnv();
        // Default stub so no fallback path ever performs a real network request.
        $this->stubPublicIpService('203.0.113.9');
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        HttpClient::$mockResponses = null;
        EventManager::resetInstance();
        parent::tearDown();
    }

    public function testServerIpUsesExplicitOverride(): void
    {
        $_ENV['APP_DOMAIN'] = 'pay.ownpay.org';
        $_ENV['APP_SERVER_IP'] = '203.0.113.10';

        $this->assertSame('203.0.113.10', $this->service()->serverIp());
    }

    public function testServerIpPrefersExplicitOverrideOverServerAddr(): void
    {
        $_ENV['APP_SERVER_IP'] = '203.0.113.10';
        $_SERVER['SERVER_ADDR'] = '203.0.113.99';

        $this->assertSame('203.0.113.10', $this->service()->serverIp());
    }

    public function testServerIpIgnoresInvalidOverride(): void
    {
        $_ENV['APP_DOMAIN'] = '127.0.0.1';
        $_ENV['APP_SERVER_IP'] = 'not-an-ip';

        $this->assertSame('203.0.113.9', $this->service()->serverIp());
    }

    public function testServerIpUsesPublicServerAddr(): void
    {
        $_SERVER['SERVER_ADDR'] = '203.0.113.77';

        $this->assertSame('203.0.113.77', $this->service()->serverIp());
    }

    public function testServerIpSkipsPrivateServerAddrAndUsesService(): void
    {
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $this->stubPublicIpService('203.0.113.80');

        $this->assertSame('203.0.113.80', $this->service()->serverIp());
    }

    public function testServerIpSkipsRfc1918ServerAddrAndUsesService(): void
    {
        $_SERVER['SERVER_ADDR'] = '10.20.30.40';
        $this->stubPublicIpService('203.0.113.80');

        $this->assertSame('203.0.113.80', $this->service()->serverIp());
    }

    public function testServerIpUsesServiceWhenServerAddrMissing(): void
    {
        $this->stubPublicIpService('203.0.113.81');

        $this->assertSame('203.0.113.81', $this->service()->serverIp());
    }

    public function testServerIpIgnoresNonIpServiceBody(): void
    {
        $this->stubPublicIpService('Cannot determine your IP address');

        $this->assertSame('127.0.0.1', $this->service()->serverIp());
    }

    public function testServerIpFallsBackToLoopbackWhenUnconfigured(): void
    {
        $this->stubPublicIpService('not-an-ip');

        $this->assertSame('127.0.0.1', $this->service()->serverIp());
    }

    public function testCnameTargetReadsConfiguredAppDomain(): void
    {
        $_ENV['APP_DOMAIN'] = 'pay.ownpay.org';

        $this->assertSame('pay.ownpay.org', $this->service()->cnameTarget());
    }

    public function testCnameTargetFallsBackToAppUrlHost(): void
    {
        $_ENV['APP_URL'] = 'https://pay.ownpay.org/admin';

        $this->assertSame('pay.ownpay.org', $this->service()->cnameTarget());
    }

    public function testIsCloudflareIpRecognizesEdgeRanges(): void
    {
        $this->assertTrue(DomainService::isCloudflareIp('104.16.0.1'));
        $this->assertTrue(DomainService::isCloudflareIp('172.64.99.99'));
        $this->assertTrue(DomainService::isCloudflareIp('198.41.128.255'));
        $this->assertTrue(DomainService::isCloudflareIp('103.22.200.0'));
        $this->assertTrue(DomainService::isCloudflareIp('190.93.245.7'));
        $this->assertTrue(DomainService::isCloudflareIp('190.93.255.255'));
    }

    public function testIsCloudflareIpRejectsOtherAddresses(): void
    {
        $this->assertFalse(DomainService::isCloudflareIp('8.8.8.8'));
        $this->assertFalse(DomainService::isCloudflareIp('127.0.0.1'));
        $this->assertFalse(DomainService::isCloudflareIp('203.0.113.10'));
        $this->assertFalse(DomainService::isCloudflareIp('obviously-not-an-ip'));
    }
}