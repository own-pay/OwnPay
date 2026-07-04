<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Modules\Themes\ReferenceMinimal\Theme;
use OwnPay\Plugin\Capability;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/themes/reference-minimal/Theme.php';

final class ReferenceMinimalThemeTest extends TestCase
{
    public function testCapabilitiesDeclaresTheme(): void
    {
        $this->assertSame([Capability::THEME], (new Theme())->capabilities());
    }

    public function testMetadataHasExpectedSlugAndType(): void
    {
        $meta = Theme::metadata();
        $this->assertSame('reference-minimal', $meta['slug']);
        $this->assertSame('theme', $meta['type']);
    }

    public function testFieldsReturnsEmptyArray(): void
    {
        // No plugin-settings customizer for this theme - all customization
        // comes from $brand, already resolved by BrandThemeService. See
        // Global Constraints for why.
        $this->assertSame([], (new Theme())->fields());
    }

    public function testRegisterAddsNoTemplateFilters(): void
    {
        // Plain-PHP themes resolve templates via ActiveTheme::resolveTemplate(),
        // not Twig-style checkout.template filters - register() should be a no-op
        // for template resolution, matching plain-php-demo's existing convention.
        $events = new \OwnPay\Event\EventManager();
        $container = new \OwnPay\Container();
        (new Theme())->register($events, $container);
        $this->assertFalse($events->hasFilter('checkout.template'));
    }
}
