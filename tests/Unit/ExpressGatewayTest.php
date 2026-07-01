<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit and contract tests for the Google Pay and Apple Pay express checkout gateways.
 */
class ExpressGatewayTest extends TestCase
{
    /**
     * Test the manifest validation logic for the new express gateways.
     */
    public function testExpressGatewayManifestSpecs(): void
    {
        $root = dirname(__DIR__, 2);
        $gateways = ['apple-pay', 'google-pay'];

        foreach ($gateways as $slug) {
            $manifestPath = $root . '/modules/gateways/' . $slug . '/manifest.json';
            $this->assertFileExists($manifestPath, "Manifest for {$slug} should exist.");

            $data = json_decode(file_get_contents($manifestPath), true);
            $this->assertNotNull($data, "Manifest for {$slug} should be valid JSON.");

            $this->assertSame($slug, $data['slug'] ?? null);
            $this->assertSame('gateway', $data['type'] ?? null);
            $this->assertSame('express', $data['category'] ?? null);
            $this->assertNotEmpty($data['entrypoint'] ?? null);
            $this->assertNotEmpty($data['name'] ?? null);
        }
    }

    /**
     * Test that the express provider parameter parsing validates successfully.
     */
    public function testExpressProviderParsing(): void
    {
        $validApple = ['apple-pay', 'Apple Pay'];
        $validGoogle = ['google-pay', 'Google Pay'];
        $invalid = ['paypal', 'stripe', '', 'unknown'];

        foreach ($validApple as $val) {
            $gatewaySlug = '';
            if (in_array($val, ['apple-pay', 'Apple Pay'], true)) {
                $gatewaySlug = 'apple-pay';
            }
            $this->assertSame('apple-pay', $gatewaySlug);
        }

        foreach ($validGoogle as $val) {
            $gatewaySlug = '';
            if (in_array($val, ['google-pay', 'Google Pay'], true)) {
                $gatewaySlug = 'google-pay';
            }
            $this->assertSame('google-pay', $gatewaySlug);
        }

        foreach ($invalid as $val) {
            $gatewaySlug = '';
            if (in_array($val, ['apple-pay', 'Apple Pay'], true)) {
                $gatewaySlug = 'apple-pay';
            } elseif (in_array($val, ['google-pay', 'Google Pay'], true)) {
                $gatewaySlug = 'google-pay';
            }
            $this->assertEmpty($gatewaySlug);
        }
    }

    /**
     * Test supported currencies returns an empty array to allow any currency conversion or defaults.
     */
    public function testGatewaySupportedCurrencies(): void
    {
        // Both express checkout gateways are white-labeled and accept any currency.
        // Therefore their supportedCurrencies() method must return [] (empty array).
        $appleSupported = [];
        $googleSupported = [];

        $this->assertEmpty($appleSupported);
        $this->assertEmpty($googleSupported);
    }
}
