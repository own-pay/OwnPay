<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DomainsManagePanelTest extends TestCase
{
    private function renderPanel(array $d): string
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader, ['cache' => false]);

        return $twig->render('admin/domains/_manage-panel.twig', [
            'd' => $d, 'csrf_token' => 'test-token', 'server_ip' => '127.0.0.1',
        ]);
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
    }

    public function testDangerZoneHasWarningCopyAndOverrideFields(): void
    {
        $html = $this->renderPanel([
            'id' => 7, 'domain' => 'pay.acme.com', 'type' => 'checkout',
            'redirect_url' => null, 'status' => 'active', 'ssl_status' => 'active',
            'dns_verified' => 1, 'is_primary' => false, 'verification_token' => 'op-verify-xyz',
        ]);

        $this->assertStringContainsString('does not run a real DNS check', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('name="dns_verified"', $html);
        $this->assertStringContainsString('data-domain-remove-form="7"', $html);
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
