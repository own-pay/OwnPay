<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DomainsAddWizardTest extends TestCase
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
            'active_page' => 'domains', 'server_ip' => '127.0.0.1', 'domains' => [],
        ], $context));
    }

    public function testWizardHasThreeSteps(): void
    {
        $html = $this->renderTemplate([]);
        $this->assertStringContainsString('data-wizard-step="1"', $html);
        $this->assertStringContainsString('data-wizard-step="2"', $html);
        $this->assertStringContainsString('data-wizard-step="3"', $html);
        $this->assertStringContainsString('op-wizard-progress', $html);
    }

    public function testStep1HasNoAdminDomainTypeOption(): void
    {
        $html = $this->renderTemplate([]);
        $wizardStart = strpos($html, 'id="add-domain-modal"');
        $this->assertIsInt($wizardStart);
        // The Add Domain wizard modal is the last modal-like block in this template
        // (the old standalone Edit Domain modal was removed - inline editing in the
        // Manage panel's Overview tab replaced it, per spec §5) - slice to end of file.
        $wizardHtml = substr($html, $wizardStart);

        $this->assertStringNotContainsString('value="admin"', $wizardHtml);
        $this->assertStringNotContainsString('Admin domain', $wizardHtml);
    }

    public function testStep1FormHasNoAjaxAttribute(): void
    {
        $html = $this->renderTemplate([]);
        $this->assertMatchesRegularExpression('/<form[^>]*action="\/admin\/domains"[^>]*data-no-ajax/', $html);
    }

    public function testNoAdminDomainTypeOptionAnywhereOnThePage(): void
    {
        // The standalone Edit Domain modal (the only other place `admin` used to
        // appear) was removed as dead markup - inline editing in the Manage panel's
        // Overview tab (built in an earlier task) replaced it. Nothing on this page
        // should offer `admin` as a selectable domain type anymore.
        $html = $this->renderTemplate([]);
        $this->assertStringNotContainsString('value="admin"', $html);
        $this->assertStringNotContainsString('Admin domain', $html);
        $this->assertStringNotContainsString('id="edit-domain-modal"', $html);
    }
}
