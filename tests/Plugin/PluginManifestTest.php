<?php

declare(strict_types=1);

namespace Tests\Plugin;

use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PluginManifestTest extends TestCase
{
    private function validManifestData(): array
    {
        return [
            'name'        => 'Test Plugin',
            'slug'        => 'test-plugin',
            'version'     => '1.0.0',
            'type'        => 'plugin',
            'description' => 'A test plugin',
            'author'      => 'Tester',
            'author_url'  => 'https://example.com',
            'license'     => 'MIT',
            'entrypoint'  => 'Plugin.php',
            'namespace'   => 'TestPlugin',
            'min_php'     => '8.2',
            'min_app'     => '0.1.0',
            'capabilities' => [],
            'dependencies' => ['other-plugin'],
            'hooks'        => [
                'actions' => ['system.boot'],
                'filters' => ['invoice.total'],
            ],
            'admin_menu' => [
                ['title' => 'Settings', 'slug' => 'test-settings', 'icon' => 'cog', 'parent' => '', 'permission' => 'manage_plugins'],
            ],
            'cron'       => [
                ['name' => 'cleanup', 'schedule' => '0 0 * * *', 'description' => 'daily cleanup'],
            ],
        ];
    }

    public function testFromArrayPopulatesAllFields(): void
    {
        $m = PluginManifest::fromArray($this->validManifestData());

        $this->assertSame('Test Plugin', $m->name);
        $this->assertSame('test-plugin', $m->slug);
        $this->assertSame('1.0.0', $m->version);
        $this->assertSame('plugin', $m->type);
        $this->assertSame('Plugin.php', $m->entrypoint);
        $this->assertSame('TestPlugin', $m->namespace);
        $this->assertSame(['other-plugin'], $m->dependencies);
        $this->assertSame(['system.boot'], $m->hooks['actions']);
        $this->assertSame(['invoice.total'], $m->hooks['filters']);
        $this->assertCount(1, $m->adminMenu);
        $this->assertCount(1, $m->cron);
    }

    public function testFromArrayUsesSafeDefaultsForMissingFields(): void
    {
        $m = PluginManifest::fromArray(['slug' => 'min-plugin']);
        $this->assertSame('', $m->name);
        $this->assertSame('0.0.0', $m->version);
        $this->assertSame('plugin', $m->type);
        $this->assertSame('Plugin.php', $m->entrypoint);
        $this->assertSame('8.2', $m->minPhp);
        $this->assertSame([], $m->capabilities);
        $this->assertSame([], $m->dependencies);
        $this->assertSame(['actions' => [], 'filters' => []], $m->hooks);
    }

    public function testFromArrayFiltersOutNonStringDependencies(): void
    {
        $m = PluginManifest::fromArray([
            'slug'         => 'p',
            'dependencies' => ['valid', 42, '', null, 'also-valid'],
        ]);
        $this->assertSame(['valid', 'also-valid'], $m->dependencies);
    }

    public function testFromFileParsesValidJsonFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest-');
        file_put_contents($path, json_encode($this->validManifestData()));

        try {
            $m = PluginManifest::fromFile($path);
            $this->assertSame('test-plugin', $m->slug);
            $this->assertSame(dirname($path), $m->sourcePath);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Manifest file not found');
        PluginManifest::fromFile('/nonexistent/manifest.json');
    }

    public function testFromFileThrowsOnInvalidJson(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest-');
        file_put_contents($path, '{not valid json');

        try {
            $this->expectException(\JsonException::class);
            PluginManifest::fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileThrowsWhenJsonDecodesToNonObject(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest-');
        file_put_contents($path, '"just-a-string"');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Manifest must decode to a JSON object');
            PluginManifest::fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testValidateReturnsEmptyForValidManifest(): void
    {
        $m = PluginManifest::fromArray($this->validManifestData());
        $this->assertSame([], $m->validate());
    }

    public function testValidateReportsMissingName(): void
    {
        $data = $this->validManifestData();
        unset($data['name']);
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $this->assertContains('Missing required field: "name"', $errors);
    }

    public function testValidateReportsMissingSlug(): void
    {
        $data = $this->validManifestData();
        unset($data['slug']);
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $this->assertContains('Missing required field: "slug"', $errors);
    }

    public function testValidateReportsInvalidSlugFormat(): void
    {
        $data = $this->validManifestData();
        $data['slug'] = 'Bad_Slug_With_Caps';
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid slug', $errors[0]);
    }

    public function testValidateRejectsLeadingHyphenInSlug(): void
    {
        $data = $this->validManifestData();
        $data['slug'] = '-leading-hyphen';
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $found = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'Invalid slug')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testValidateReportsInvalidType(): void
    {
        $data = $this->validManifestData();
        $data['type'] = 'unknown-type';
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $found = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'Invalid type')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testValidateAcceptsAllValidTypes(): void
    {
        foreach (['plugin', 'gateway', 'theme'] as $type) {
            $data = $this->validManifestData();
            $data['type'] = $type;
            $m = PluginManifest::fromArray($data);
            $this->assertSame([], $m->validate(), "Type {$type} should validate");
        }
    }

    public function testValidateRejectsEntrypointWithPathTraversal(): void
    {
        $data = $this->validManifestData();
        $data['entrypoint'] = '../malicious.php';
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $found = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'plain filename')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testValidateRejectsEntrypointWithSlash(): void
    {
        $data = $this->validManifestData();
        $data['entrypoint'] = 'sub/Plugin.php';
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $found = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'plain filename')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testValidateRejectsCronWithMissingFields(): void
    {
        $data = $this->validManifestData();
        $data['cron'] = [['name' => '', 'schedule' => '', 'description' => 'broken']];
        $m = PluginManifest::fromArray($data);
        $errors = $m->validate();
        $this->assertContains('Cron entry #0: missing "name"', $errors);
        $this->assertContains('Cron entry #0: missing "schedule"', $errors);
    }

    public function testHasCapabilityChecksDeclaredCapabilities(): void
    {
        $data = $this->validManifestData();
        $data['capabilities'] = [Capability::DB_READ->value];
        $m = PluginManifest::fromArray($data);
        $this->assertTrue($m->hasCapability(Capability::DB_READ));
        $this->assertFalse($m->hasCapability(Capability::DB_WRITE));
    }

    public function testGetCapabilitiesReturnsEnumCases(): void
    {
        $data = $this->validManifestData();
        $data['capabilities'] = [Capability::DB_READ->value, 'invalid-cap', Capability::HTTP_OUTBOUND->value];
        $m = PluginManifest::fromArray($data);
        $caps = $m->getCapabilities();
        $this->assertContains(Capability::DB_READ, $caps);
        $this->assertContains(Capability::HTTP_OUTBOUND, $caps);
        $this->assertCount(2, $caps);
    }

    public function testGetFullyQualifiedClassNameUsesNamespaceAndEntrypoint(): void
    {
        $data = $this->validManifestData();
        $data['namespace'] = 'CustomNamespace';
        $data['entrypoint'] = 'Gateway.php';
        $m = PluginManifest::fromArray($data);
        $this->assertSame('CustomNamespace\\Gateway', $m->getFullyQualifiedClassName());
    }

    public function testGetFullyQualifiedClassNameDerivesNamespaceFromSlug(): void
    {
        $data = $this->validManifestData();
        unset($data['namespace']);
        $data['slug']       = 'sms-notifications';
        $data['entrypoint'] = 'Plugin.php';
        $m = PluginManifest::fromArray($data);
        $this->assertSame('OwnPay\\Plugins\\SmsNotifications\\Plugin', $m->getFullyQualifiedClassName());
    }

    public function testToArrayRoundTrip(): void
    {
        $original = $this->validManifestData();
        $m = PluginManifest::fromArray($original);
        $array = $m->toArray();
        $this->assertSame($original['name'], $array['name']);
        $this->assertSame($original['slug'], $array['slug']);
        $this->assertSame($original['hooks']['actions'], $array['hooks']['actions']);
    }
}
