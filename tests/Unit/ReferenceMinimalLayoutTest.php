<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReferenceMinimalLayoutTest extends TestCase
{
    private function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function loadLayoutFunction(): void
    {
        if (!function_exists('OwnPay\Modules\Themes\ReferenceMinimal\render_layout')) {
            require __DIR__ . '/../../modules/themes/reference-minimal/templates/checkout/layout.php';
        }
    }

    public function testFallsBackToBrandNameWhenLogoEmpty(): void
    {
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout(
            'Checkout',
            '<p>content</p>',
            ['name' => 'Acme Store', 'logo' => ''],
            $this->esc(...)
        );
        $this->assertStringContainsString('Acme Store', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testRendersLogoImgWhenLogoSet(): void
    {
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout(
            'Checkout',
            '<p>content</p>',
            ['name' => 'Acme Store', 'logo' => 'https://example.com/logo.png'],
            $this->esc(...)
        );
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('https://example.com/logo.png', $html);
    }

    public function testOmitsStyleBlockWhenCustomCssEmpty(): void
    {
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout(
            'Checkout',
            '<p>content</p>',
            ['name' => 'Acme', 'custom_css' => ''],
            $this->esc(...)
        );
        $this->assertStringNotContainsString('<style id="op-custom-css">', $html);
    }

    public function testIncludesCustomCssStyleBlockWhenSet(): void
    {
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout(
            'Checkout',
            '<p>content</p>',
            ['name' => 'Acme', 'custom_css' => '.foo { color: red; }'],
            $this->esc(...)
        );
        $this->assertStringContainsString('<style id="op-custom-css">', $html);
        $this->assertStringContainsString('.foo { color: red; }', $html);
    }

    public function testStripsStyleCloseTagFromCustomCssToPreventBreakout(): void
    {
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout(
            'Checkout',
            '<p>content</p>',
            ['name' => 'Acme', 'custom_css' => '.foo{color:red}</style><img src=x onerror=alert(1)>'],
            $this->esc(...)
        );
        $this->assertStringNotContainsString('</style><img', $html);
        $this->assertStringContainsString('.foo{color:red}', $html);
    }

    public function testHidesPoweredByWhenDisabled(): void
    {
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout(
            'Checkout',
            '<p>content</p>',
            ['name' => 'Acme', 'show_powered_by' => false],
            $this->esc(...)
        );
        $this->assertStringNotContainsString('Powered by', $html);
    }

    public function testShowsPoweredByByDefaultWhenKeyMissing(): void
    {
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout('Checkout', '<p>x</p>', [], $this->esc(...));
        $this->assertStringContainsString('Powered by', $html);
    }

    public function testWorksWithEmptyBrandArray(): void
    {
        // payment-link-amount.php has no real $brand available - must not crash.
        $this->loadLayoutFunction();
        $html = \OwnPay\Modules\Themes\ReferenceMinimal\render_layout('Enter Amount', '<p>x</p>', [], $this->esc(...));
        $this->assertStringContainsString('OwnPay', $html);
    }
}
