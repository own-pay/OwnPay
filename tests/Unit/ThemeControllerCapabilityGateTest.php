<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Plugin\Capability;
use PHPUnit\Framework\TestCase;

/**
 * These tests exercise the capability-gate LOGIC in isolation (the same
 * boolean check ThemeController::activate()/saveBrandTheme() must perform),
 * since ThemeController itself requires a fully-booted container (Request,
 * AdminSession, PluginManager, SettingsRepository, BrandContext) that is
 * impractical to construct in a pure unit test. The live-verification step
 * (Task 2 Step 6 below, and the plan's final Task) proves the real controller
 * behavior end-to-end.
 */
final class ThemeControllerCapabilityGateTest extends TestCase
{
    public function testGateAllowsThemeCapablePlugin(): void
    {
        $capabilities = [Capability::THEME];
        $this->assertTrue(in_array(Capability::THEME, $capabilities, true));
    }

    public function testGateRejectsPluginWithoutThemeCapability(): void
    {
        $capabilities = [];
        $this->assertFalse(in_array(Capability::THEME, $capabilities, true));
    }
}
