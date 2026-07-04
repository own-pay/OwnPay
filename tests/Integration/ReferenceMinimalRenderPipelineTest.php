<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\View\Theme\ActiveTheme;
use OwnPay\View\Theme\PlainPhpThemeRenderer;
use OwnPay\View\Theme\ThemeRendererRegistry;
use PHPUnit\Framework\TestCase;

final class ReferenceMinimalRenderPipelineTest extends TestCase
{
    private function themeDir(): string
    {
        return dirname(__DIR__, 2) . '/modules/themes/reference-minimal';
    }

    private function registry(): ThemeRendererRegistry
    {
        return new ThemeRendererRegistry(['php' => new PlainPhpThemeRenderer()]);
    }

    public function testCheckoutTemplateRendersEndToEnd(): void
    {
        $theme = new ActiveTheme('reference-minimal', 'php', $this->themeDir(), false);
        $html = $this->registry()->get($theme->engine)->render(
            $theme->resolveTemplate('checkout/checkout.twig'),
            [
                'txn' => ['trx_id' => 'OP-TEST123', 'amount' => '49.00', 'currency' => 'USD'],
                'brand' => ['name' => 'Acme Store', 'accent_color' => '#059669', 'show_powered_by' => true],
                'gateways' => ['mfs' => [['slug' => 'bkash-api', 'name' => 'bKash']], 'bank' => [], 'global' => [], 'express' => []],
                'checkout_hash' => 'test-hmac-signature-abc123',
            ]
        );
        $this->assertStringContainsString('Acme Store', $html);
        $this->assertStringContainsString('49.00', $html);
        $this->assertStringContainsString('bKash', $html);
        $this->assertStringContainsString(
            '<input type="hidden" name="checkout_hash" value="test-hmac-signature-abc123">',
            $html
        );
    }

    public function testCheckoutStatusTemplateRendersEndToEnd(): void
    {
        $theme = new ActiveTheme('reference-minimal', 'php', $this->themeDir(), false);
        $html = $this->registry()->get($theme->engine)->render(
            $theme->resolveTemplate('checkout/checkout-status.twig'),
            ['status' => 'success', 'status_label' => 'Payment Successful', 'brand' => ['name' => 'Acme Store']]
        );
        $this->assertStringContainsString('Payment Successful', $html);
    }

    public function testPaymentLinkAmountTemplateRendersEndToEnd(): void
    {
        $theme = new ActiveTheme('reference-minimal', 'php', $this->themeDir(), false);
        $html = $this->registry()->get($theme->engine)->render(
            $theme->resolveTemplate('checkout/payment-link-amount.twig'),
            ['link' => ['slug' => 'my-link', 'currency' => 'USD', 'min_amount' => '10', 'max_amount' => '500'], 'csrf_token' => 'tok123', 'error' => null]
        );
        $this->assertStringContainsString('my-link', $html);
        $this->assertStringContainsString('tok123', $html);
        $this->assertStringContainsString('Min 10', $html);
    }

    public function testPaymentLinkAmountSuppressesMinHintForZeroDecimalString(): void
    {
        // Regression: '0.00' !== '0' as a string, but is numerically zero -
        // must not render a pointless "Min 0.00" hint (final-review finding #3).
        $theme = new ActiveTheme('reference-minimal', 'php', $this->themeDir(), false);
        $html = $this->registry()->get($theme->engine)->render(
            $theme->resolveTemplate('checkout/payment-link-amount.twig'),
            ['link' => ['slug' => 'my-link', 'currency' => 'USD', 'min_amount' => '0.00', 'max_amount' => ''], 'csrf_token' => 'tok123', 'error' => null]
        );
        $this->assertStringNotContainsString('Min', $html);
    }
}
