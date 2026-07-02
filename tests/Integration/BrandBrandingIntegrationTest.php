<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Core\Database;
use OwnPay\Repository\MerchantRepository;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\Brand\BrandThemeService;

final class BrandBrandingIntegrationTest extends IntegrationTestCase
{
    private Database $db;
    private MerchantRepository $merchantRepo;
    private BrandThemeService $brandThemeService;
    private int $testMerchantId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance();
        $this->merchantRepo = new MerchantRepository($this->db);
        $settingsRepo = new SettingsRepository($this->db);
        $this->brandThemeService = new BrandThemeService($this->db, $settingsRepo);
    }

    protected function tearDown(): void
    {
        if ($this->testMerchantId > 0 && static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_merchants WHERE id = :id", ['id' => $this->testMerchantId]);
        }
        parent::tearDown();
    }

    public function testBrandWiseCustomizationPersistence(): void
    {
        $merchantId = (int) $this->merchantRepo->createMerchant([
            'name'             => 'Integ Custom Brand',
            'slug'             => 'integ-custom-brand',
            'email'            => 'integ@custombrand.com',
            'phone'            => '01700000000',
            'timezone'         => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'status'           => 'active',
        ]);
        $this->assertGreaterThan(0, $merchantId);
        $this->testMerchantId = $merchantId;

        $customLogo = '/assets/uploads/brands/brand_logo_' . $merchantId . '_12345.png';
        $customFavicon = '/assets/uploads/brands/brand_favicon_' . $merchantId . '_12345.png';
        $themeSettings = [
            'logo'            => $customLogo,
            'favicon'         => $customFavicon,
            'primary_color'   => '#FF0000',
            'accent_color'    => '#00FF00',
            'support_email'   => 'support@custombrand.com',
            'footer_text'     => 'Integ Brand Secured',
            'custom_css'      => '.body { background: #000; }',
            'custom_js'       => 'console.log("Integ brand JS loaded");',
            'show_powered_by' => 0,
        ];

        $this->merchantRepo->updateBrand($merchantId, [
            'name'             => 'Integ Custom Brand',
            'email'            => 'integ@custombrand.com',
            'phone'            => '01700000000',
            'timezone'         => 'Asia/Dhaka',
            'default_currency' => 'BDT',
            'status'           => 'active',
            'logo_path'        => $customLogo,
            'settings'         => json_encode($themeSettings),
        ]);

        $fetched = $this->merchantRepo->find($merchantId);
        $this->assertNotNull($fetched);
        $this->assertSame($customLogo, $fetched['logo_path']);
        $this->assertJson($fetched['settings']);

        $settingsDecoded = json_decode($fetched['settings'], true);
        $this->assertSame($customFavicon, $settingsDecoded['favicon']);
        $this->assertSame('#FF0000', $settingsDecoded['primary_color']);
        $this->assertSame(0, $settingsDecoded['show_powered_by']);

        $resolvedTheme = $this->brandThemeService->getBrandTheme($merchantId);
        $this->assertSame('Integ Custom Brand', $resolvedTheme['name']);
        $this->assertSame($customLogo, $resolvedTheme['logo']);
        $this->assertSame($customFavicon, $resolvedTheme['favicon']);
        $this->assertSame('#FF0000', $resolvedTheme['color']);
        $this->assertSame('#00FF00', $resolvedTheme['accent_color']);
        $this->assertSame('support@custombrand.com', $resolvedTheme['support_email']);
        $this->assertSame('.body { background: #000; }', $resolvedTheme['custom_css']);
        $this->assertSame('console.log("Integ brand JS loaded");', $resolvedTheme['custom_js']);
        $this->assertSame('Integ Brand Secured', $resolvedTheme['footer_text']);
        $this->assertFalse($resolvedTheme['show_powered_by']);
    }
}
