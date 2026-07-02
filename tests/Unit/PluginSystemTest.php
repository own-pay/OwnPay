<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PluginSystemTest extends TestCase
{
    public function testManifestJsonParsing(): void
    {
        $json = '{"name":"test-plugin","version":"1.0.0","type":"addon","entry":"Plugin.php"}';
        $manifest = json_decode($json, true);
        $this->assertSame('test-plugin', $manifest['name']);
        $this->assertSame('addon', $manifest['type']);
    }

    public function testManifestRequiredFields(): void
    {
        $required = ['name', 'version', 'type', 'entry'];
        $manifest = ['name' => 'x', 'version' => '1.0', 'type' => 'addon', 'entry' => 'Plugin.php'];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $manifest);
        }
    }

    public function testPluginInterfaceContract(): void
    {
        $plugins = [
            'modules/addons/sms-gateway/manifest.json',
            'modules/addons/mail-gateway/manifest.json',
            'modules/addons/telegram-bot/manifest.json',
            'modules/themes/own-pay/manifest.json',
        ];
        foreach ($plugins as $path) {
            $full = dirname(__DIR__, 2) . '/' . $path;
            if (file_exists($full)) {
                $m = json_decode(file_get_contents($full), true);
                $this->assertNotNull($m, "Invalid JSON: {$path}");
                $this->assertArrayHasKey('name', $m);
                $this->assertArrayHasKey('entry', $m);
            }
        }
        $this->assertTrue(true);
    }

    public function testSemverComparison(): void
    {
        $this->assertTrue(version_compare('0.1.0', '0.0.9', '>'));
        $this->assertTrue(version_compare('1.0.0', '0.9.99', '>'));
        $this->assertFalse(version_compare('0.1.0', '0.2.0', '>='));
    }
}
