<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginManifestEngineTest extends TestCase
{
    private function make(array $data): PluginManifest
    {
        return PluginManifest::fromArray(array_merge([
            'name' => 'X', 'slug' => 'x', 'type' => 'theme', 'entrypoint' => 'Theme.php',
        ], $data), '/tmp/x');
    }

    public function testEngineDefaultsToEmptyWhenAbsent(): void
    {
        $this->assertSame('', $this->make([])->engine);
    }

    public function testEngineParsedFromManifest(): void
    {
        $this->assertSame('php', $this->make(['engine' => 'php'])->engine);
    }

    public function testInvalidThemeEngineFailsValidation(): void
    {
        $errors = $this->make(['engine' => 'bogus'])->validate();
        $this->assertContains('Invalid engine', $errors);
    }

    public function testValidEnginePassesValidation(): void
    {
        $this->assertNotContains('Invalid engine', $this->make(['engine' => 'php'])->validate());
    }

    public function testToArrayIncludesEngine(): void
    {
        $this->assertSame('php', $this->make(['engine' => 'php'])->toArray()['engine']);
    }
}
