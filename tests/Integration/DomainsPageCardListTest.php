<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DomainsPageCardListTest extends TestCase
{
    private function renderTemplate(array $context): string
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader, ['cache' => false]);
        $twig->addFunction(new \Twig\TwigFunction('hook', fn (string $name) => ''));
        $twig->addFunction(new \Twig\TwigFunction('locale', fn (): string => 'en'));
        $twig->addFunction(new \Twig\TwigFunction('__', fn (string $key, ...$args) => $key));
        $twig->addFunction(new \Twig\TwigFunction('enqueued_assets', fn (string $type) => '', ['is_safe' => ['html']]));

        return $twig->render('admin/domains/index.twig', array_merge([
            'app_name' => 'OwnPay', 'app_version' => '0.2.0', 'csrf_token' => 'test-token',
            'current_user' => ['name' => 'Test'], 'is_superadmin' => true,
            'flash_success' => null, 'flash_error' => null,
            'active_page' => 'domains', 'server_ip' => '127.0.0.1',
        ], $context));
    }

    public function testRendersOneCardPerDomainWithStatusPill(): void
    {
        $html = $this->renderTemplate([
            'domains' => [[
                'id' => 1, 'domain' => 'pay.acme.com', 'merchant_name' => 'Acme',
                'type' => 'checkout', 'is_primary' => true, 'redirect_url' => null,
                'status' => 'active', 'ssl_status' => 'active', 'dns_verified' => 1,
                'verification_token' => 'op-verify-abc123',
                'status_pill' => ['label' => 'Active', 'class' => 'op-badge-success'],
            ]],
        ]);

        $this->assertStringContainsString('op-domain-card', $html);
        $this->assertStringContainsString('data-domain-id="1"', $html);
        $this->assertStringContainsString('pay.acme.com', $html);
        $this->assertStringContainsString('op-badge-success', $html);
        $this->assertStringContainsString('Active', $html);
    }

    public function testNoStaticDnsGuideSection(): void
    {
        $html = $this->renderTemplate(['domains' => []]);
        $this->assertStringNotContainsString('Custom Domain DNS Configuration Guide', $html);
        $this->assertStringNotContainsString('Automatic Check: Run by background cron', $html);
    }

    public function testEmptyStateWhenNoDomains(): void
    {
        $html = $this->renderTemplate(['domains' => []]);
        $this->assertStringContainsString('No custom domains added yet', $html);
    }
}
