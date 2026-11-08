<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SettingsSmtpRowsTest extends TestCase
{
    private function renderTemplate(array $context = []): string
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
            'is_brand_view' => false, 'default_tab' => 'email', 'domains' => [],
            'languages' => [], 'default_language' => 'en', 'cron_jobs' => [],
            'cron_secret' => '', 'cron_url' => '', 'settings' => [],
        ], $context));
    }

    public function testSmtpFieldsRenderIndividuallyAcrossSeparateRows(): void
    {
        $html = $this->renderTemplate([]);

        $labels = ['SMTP Host', 'SMTP Port', 'SMTP Username', 'SMTP Password', 'SMTP Encryption'];
        foreach ($labels as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // Each op-form-row block must contain at most one of the SMTP field labels.
        preg_match_all('/<div class="op-form-row">(.*?)<\/div>/s', $html, $matches);
        $rows = $matches[1] ?? [];
        $smtpRows = 0;
        foreach ($rows as $row) {
            $count = 0;
            foreach ($labels as $label) {
                if (strpos($row, $label) !== false) {
                    $count++;
                }
            }
            if ($count > 0) {
                $smtpRows++;
                $this->assertSame(1, $count, 'An op-form-row contains more than one SMTP field.');
            }
        }
        $this->assertGreaterThanOrEqual(count($labels), $smtpRows);
    }
}
