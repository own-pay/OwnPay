<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SettingsDomainsLinkTest extends TestCase
{
    private function renderTemplate(array $context): string
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $twig = new Environment($loader, ['cache' => false]);
        $twig->addFunction(new \Twig\TwigFunction('hook', fn (string $name) => ''));
        $twig->addFunction(new \Twig\TwigFunction('locale', fn (): string => 'en'));
        $twig->addFunction(new \Twig\TwigFunction('__', fn (string $key, ...$args) => $key));
        $twig->addFunction(new \Twig\TwigFunction('enqueued_assets', fn (string $type) => '', ['is_safe' => ['html']]));
        $twig->addFilter(new \Twig\TwigFilter('format_bytes', fn ($bytes) => (string) ((float) ($bytes ?? 0)) . ' B'));
        $twig->addFilter(new \Twig\TwigFilter('time_ago', fn ($v) => (string) $v));
        $twig->addFilter(new \Twig\TwigFilter('datetime', fn ($v) => (string) $v));

        return $twig->render('admin/settings/index.twig', array_merge([
            'app_name' => 'OwnPay', 'app_version' => '0.2.0', 'csrf_token' => 'test-token',
            'current_user' => ['name' => 'Test'], 'is_superadmin' => true,
            'flash_success' => null, 'flash_error' => null, 'active_page' => 'settings',
            'is_brand_view' => true, 'default_tab' => 'branding', 'domains' => [],
            'languages' => [], 'default_language' => 'en', 'cron_jobs' => [],
            'cron_secret' => '', 'cron_url' => '',
        ], $context));
    }

    public function testNoDomainsTabPanelOrHiddenForms(): void
    {
        $html = $this->renderTemplate([]);
        $this->assertStringNotContainsString('id="tab-domains"', $html);
        $this->assertStringNotContainsString('id="add-domain-modal"', $html);
        $this->assertStringNotContainsString('id="edit-domain-modal"', $html);
        $this->assertStringNotContainsString('primary-domain-form-', $html);
    }

    public function testHasLinkToDomainsPage(): void
    {
        $html = $this->renderTemplate([]);
        $this->assertStringContainsString('href="/admin/domains"', $html);
    }
}
