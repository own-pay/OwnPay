<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Kernel;

/**
 * Regression coverage for GitHub issue #565: the checkout form must keep rendering
 * every active payment method even when ALL card gateways are disabled.
 *
 * The bug was a drift between the two checkout partials: _gateway-tabs.twig gated the
 * tab bar on which categories had active gateways while _gateway-grid.twig always kept
 * the "Cards" pane as the visible default. With cards disabled and e.g. MFS enabled the
 * tab bar disappeared (only one category) and the grid pointed at the empty Cards pane;
 * the active MFS methods sat in a hidden pane with no tab to reveal them, so the checkout
 * showed no payment methods at all until at least one card was enabled.
 *
 * The fix single-sources the category flags and the server-rendered default pane in
 * checkout.twig and hands them to both partials. These tests lock the full matrix in so
 * the two partials can never disagree again.
 */
class CheckoutPaymentMethodVisibilityTest extends TestCase
{
    private \Twig\Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();

        $prevTz = date_default_timezone_get();
        $kernel = new Kernel();
        $ref = new \ReflectionMethod($kernel, 'boot');
        $ref->setAccessible(true);
        $ref->invoke($kernel);
        date_default_timezone_set($prevTz);

        $cRef = new \ReflectionProperty($kernel, 'container');
        $cRef->setAccessible(true);
        $container = $cRef->getValue($kernel);

        $this->twig = $container->get(\Twig\Environment::class);
    }

    private function renderCheckout(array $gateways): string
    {
        $brand = [
            'name' => 'CZPay',
            'color' => '#0D9488',
            'logo' => '/assets/img/logo.svg',
            'favicon' => '',
            'support_email' => 'support@czbd.app',
            'show_faq' => false,
        ];

        return $this->twig->render('checkout/checkout.twig', [
            'brand' => $brand,
            'gateways' => $gateways,
            'txn' => ['trx_id' => 'test1234', 'amount' => '100.00', 'currency' => 'BDT', 'currency_symbol' => '৳', 'ref' => 'test1234'],
            'items' => [],
            'faqs' => [],
            'show_faq' => false,
            'config' => ['timeoutEnabled' => false],
            'checkout_hash' => 'hash',
            'manual_gateways' => '{}',
            'csrf_token' => 'csrf',
            'csp_nonce' => 'nonce123'
        ]);
    }

    public function testMfsOnlyCardsDisabledStillShowsMfsMethods(): void
    {
        $html = $this->renderCheckout([
            'global' => [],
            'mfs' => [
                ['slug' => 'bkash-api', 'name' => 'bKash API', 'mode' => 'api', 'color' => '#E2136E']
            ],
            'bank' => [],
            'express' => []
        ]);

        // No card gateway: the Cards pane must not be the visible default.
        $this->assertMatchesRegularExpression('/<div id="t-cards" class="ck-tc ck-hidden"/', $html);
        // The MFS pane must be the visible default and carry the active MFS method.
        $this->assertMatchesRegularExpression('/<div id="t-mfs" class="ck-tc ">/', $html);
        $this->assertStringContainsString('data-slug="bkash-api"', $html);
        // Nothing to switch between: tab bar hidden.
        $this->assertStringNotContainsString('class="ck-tabs ck-fi"', $html);
    }

    public function testBankOnlyCardsDisabledStillShowsBankMethods(): void
    {
        $html = $this->renderCheckout([
            'global' => [],
            'mfs' => [],
            'bank' => [
                ['slug' => 'wire-bank', 'name' => 'Wire Bank', 'mode' => 'manual', 'color' => '#ECEEF5']
            ],
            'express' => []
        ]);

        $this->assertMatchesRegularExpression('/<div id="t-cards" class="ck-tc ck-hidden"/', $html);
        $this->assertMatchesRegularExpression('/<div id="t-bank" class="ck-tc ">/', $html);
        $this->assertStringContainsString('data-slug="wire-bank"', $html);
        $this->assertStringNotContainsString('class="ck-tabs ck-fi"', $html);
    }

    public function testMfsAndBankCardsDisabledBothNonCardMethodsRemainReachable(): void
    {
        $html = $this->renderCheckout([
            'global' => [],
            'mfs' => [
                ['slug' => 'bkash-api', 'name' => 'bKash API', 'mode' => 'api', 'color' => '#E2136E']
            ],
            'bank' => [
                ['slug' => 'wire-bank', 'name' => 'Wire Bank', 'mode' => 'manual', 'color' => '#ECEEF5']
            ],
            'express' => []
        ]);

        // Two non-card categories: the tab bar renders, but the Cards tab must NOT.
        $this->assertStringContainsString('class="ck-tabs ck-fi"', $html);
        $this->assertStringNotContainsString('data-t="cards"', $html);
        $this->assertStringContainsString('data-t="mfs" data-action="go-tab" data-tab-name="mfs"', $html);
        $this->assertStringContainsString('data-t="bank" data-action="go-tab" data-tab-name="bank"', $html);

        // MFS pane is the visible default with its methods.
        $this->assertMatchesRegularExpression('/<div id="t-mfs" class="ck-tc ">/', $html);
        $this->assertStringContainsString('data-slug="bkash-api"', $html);
        // Banks stay reachable behind their own tab (present in the DOM, not the visible pane).
        $this->assertMatchesRegularExpression('/<div id="t-bank" class="ck-tc ck-hidden"/', $html);
        $this->assertStringContainsString('data-slug="wire-bank"', $html);
        // Cards pane hidden, not the default when cards are disabled.
        $this->assertMatchesRegularExpression('/<div id="t-cards" class="ck-tc ck-hidden"/', $html);
    }

    public function testCardsOnlyShowsCardsPaneWithoutTabBar(): void
    {
        $html = $this->renderCheckout([
            'global' => [
                ['slug' => 'stripe', 'name' => 'Stripe', 'mode' => 'api', 'color' => '#635BFF']
            ],
            'mfs' => [],
            'bank' => [],
            'express' => []
        ]);

        $this->assertMatchesRegularExpression('/<div id="t-cards" class="ck-tc ">/', $html);
        $this->assertStringContainsString('data-slug="stripe"', $html);
        $this->assertStringNotContainsString('class="ck-tabs ck-fi"', $html);
    }

    public function testCardsAndMfsTabsShownWithCardsAsDefaultPane(): void
    {
        $html = $this->renderCheckout([
            'global' => [
                ['slug' => 'stripe', 'name' => 'Stripe', 'mode' => 'api', 'color' => '#635BFF']
            ],
            'mfs' => [
                ['slug' => 'bkash-api', 'name' => 'bKash API', 'mode' => 'api', 'color' => '#E2136E']
            ],
            'bank' => [],
            'express' => []
        ]);

        $this->assertStringContainsString('class="ck-tabs ck-fi"', $html);
        $this->assertMatchesRegularExpression('/<div id="t-cards" class="ck-tc ">/', $html);
        $this->assertMatchesRegularExpression('/<div id="t-mfs" class="ck-tc ck-hidden"/', $html);
        $this->assertStringContainsString('data-t="mfs" data-action="go-tab" data-tab-name="mfs"', $html);
    }

    public function testNoGatewaysShowsEmptyStateMessage(): void
    {
        $html = $this->renderCheckout([
            'global' => [],
            'mfs' => [],
            'bank' => [],
            'express' => []
        ]);

        $this->assertStringContainsString('No payment methods currently available.', $html);
        $this->assertStringNotContainsString('class="ck-tabs ck-fi"', $html);
    }

    public function testExpressOnlyStillRendersExpressSection(): void
    {
        $html = $this->renderCheckout([
            'global' => [],
            'mfs' => [],
            'bank' => [],
            'express' => [
                ['slug' => 'apple-pay', 'name' => 'Apple Pay', 'mode' => 'api', 'color' => '#000000']
            ]
        ]);

        $this->assertStringContainsString('data-provider="Apple Pay"', $html);
        $this->assertStringNotContainsString('class="ck-tabs ck-fi"', $html);
    }
}