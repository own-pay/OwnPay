<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DomainsManagePanelTest extends TestCase
{
    private function renderPanel(array $d, array $extra = []): string
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader, ['cache' => false]);

        return $twig->render('admin/domains/_manage-panel.twig', array_merge([
            'd' => $d, 'csrf_token' => 'test-token', 'server_ip' => '127.0.0.1',
            'cname_target' => 'pay.ownpay.test', 'server_ip_proxied' => false,
        ], $extra));
    }

    public function testHasThreeScopedTabs(): void
    {
        $html = $this->renderPanel([
            'id' => 7, 'domain' => 'pay.acme.com', 'type' => 'checkout',
            'redirect_url' => null, 'status' => 'active', 'ssl_status' => 'active',
            'dns_verified' => 1, 'is_primary' => false, 'verification_token' => 'op-verify-xyz',
        ]);

        $this->assertStringContainsString('data-domain-manage="7"', $html);
        $this->assertStringContainsString('data-domain-tab="overview"', $html);
        $this->assertStringContainsString('data-domain-tab="dns-setup"', $html);
        $this->assertStringContainsString('data-domain-tab="danger"', $html);
        $this->assertStringContainsString('data-domain-tab-panel="overview"', $html);
        $this->assertStringContainsString('data-domain-tab-panel="dns-setup"', $html);
        $this->assertStringContainsString('data-domain-tab-panel="danger"', $html);
    }

    public function testDnsSetupTabHasTxtRecordAndAccurateCopy(): void
    {
        $html = $this->renderPanel([
            'id' => 7, 'domain' => 'pay.acme.com', 'type' => 'checkout',
            'redirect_url' => null, 'status' => 'pending', 'ssl_status' => 'none',
            'dns_verified' => 0, 'is_primary' => false, 'verification_token' => 'op-verify-xyz',
        ]);

        $this->assertStringContainsString('_ownpay-verify.pay.acme.com', $html);
        $this->assertStringContainsString('ownpay-verify=op-verify-xyz', $html);
        $this->assertStringContainsString('automatically re-checked hourly', $html);
        $this->assertStringContainsString('automatically removed', $html);
        $this->assertStringContainsString('not checked automatically', $html);
        $this->assertStringContainsString('pay.ownpay.test', $html);
        $this->assertStringNotContainsString('testing.ownpay.org', $html);
    }

    public function testDnsSetupTabWarnsAboutCloudflareProxiedIp(): void
    {
        $d = [
            'id' => 7, 'domain' => 'pay.acme.com', 'type' => 'checkout',
            'redirect_url' => null, 'status' => 'pending', 'ssl_status' => 'none',
            'dns_verified' => 0, 'is_primary' => false, 'verification_token' => 'op-verify-xyz',
        ];

        $html = $this->renderPanel($d, ['server_ip' => '104.16.0.1', 'server_ip_proxied' => true]);
        $this->assertStringContainsString('Cloudflare edge address', $html);
        $this->assertStringContainsString('APP_SERVER_IP', $html);

        $plain = $this->renderPanel($d);
        $this->assertStringNotContainsString('Cloudflare edge address', $plain);
    }

    public function testDangerZoneHasOnlyRemoveDomainNoManualOverride(): void
    {
        // Manual status/dns_verified override was removed per explicit user
        // decision after live review - a domain's real state should only ever
        // change via a real DNS/SSL check or removal, never a raw admin toggle.
        $html = $this->renderPanel([
            'id' => 7, 'domain' => 'pay.acme.com', 'type' => 'checkout',
            'redirect_url' => null, 'status' => 'active', 'ssl_status' => 'active',
            'dns_verified' => 1, 'is_primary' => false, 'verification_token' => 'op-verify-xyz',
        ]);

        $dangerStart = strpos($html, 'data-domain-tab-panel="danger"');
        $this->assertIsInt($dangerStart);
        $dangerHtml = substr($html, $dangerStart);

        $this->assertStringContainsString('data-domain-remove-form="7"', $dangerHtml);
        $this->assertStringNotContainsString('data-domain-override-form', $dangerHtml);
        $this->assertStringNotContainsString('name="status"', $dangerHtml);
        $this->assertStringNotContainsString('name="dns_verified"', $dangerHtml);
    }

    public function testOverviewTabHasNoAdminDomainTypeOption(): void
    {
        $html = $this->renderPanel([
            'id' => 7, 'domain' => 'pay.acme.com', 'type' => 'checkout',
            'redirect_url' => null, 'status' => 'active', 'ssl_status' => 'active',
            'dns_verified' => 1, 'is_primary' => false, 'verification_token' => 'op-verify-xyz',
        ]);

        $this->assertStringNotContainsString('value="admin"', $html);
        $this->assertStringNotContainsString('Admin domain', $html);
    }
}
