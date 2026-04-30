<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * L21 — Smoke test: validates essential system contracts.
 */
class SmokeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testComposerJsonExists(): void
    {
        $this->assertFileExists($this->root . '/composer.json');
    }

    public function testPublicIndexExists(): void
    {
        $this->assertFileExists($this->root . '/public/index.php');
    }

    public function testRoutesExist(): void
    {
        $this->assertFileExists($this->root . '/config/routes/web.php');
        $this->assertFileExists($this->root . '/config/routes/api.php');
    }

    public function testTemplateDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->root . '/templates');
        $this->assertDirectoryExists($this->root . '/templates/checkout');
    }

    public function testSchemaFileExists(): void
    {
        $this->assertFileExists($this->root . '/app/install/master_install.sql');
    }

    public function testModulesStructure(): void
    {
        $this->assertDirectoryExists($this->root . '/modules/addons/sms-gateway');
        $this->assertDirectoryExists($this->root . '/modules/addons/mail-gateway');
        $this->assertDirectoryExists($this->root . '/modules/addons/telegram-bot');
        $this->assertDirectoryExists($this->root . '/modules/themes/own-pay');
    }

    public function testCheckoutPartials(): void
    {
        $partials = ['_left-panel', '_mobile-summary', '_top-bar', '_gateway-tabs', '_gateway-grid',
            '_manual-popup', '_express-checkout', '_modals', '_footer', '_success', '_failed',
            '_cancelled', '_pending', '_expired'];
        foreach ($partials as $p) {
            $this->assertFileExists($this->root . "/templates/checkout/partials/{$p}.twig", "Missing partial: {$p}");
        }
    }

    public function testPublicAssetsExist(): void
    {
        $this->assertFileExists($this->root . '/public/assets/css/checkout.css');
        $this->assertFileExists($this->root . '/public/assets/js/checkout.js');
        $this->assertFileExists($this->root . '/public/assets/css/installer.css');
    }
}
