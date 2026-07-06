<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Controller\Admin\PluginController;
use OwnPay\Event\EventManager;
use OwnPay\Plugin\PluginInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PluginController::validateRequiredFields(), a private static method
 * tested via ReflectionMethod (mirrors the existing pattern in
 * tests/Unit/HookOutputSanitizerTest.php for testing a private method without needing
 * a fully-constructed controller instance - the method is static and touches no
 * instance state, so ReflectionMethod::invoke(null, ...) works directly).
 */
final class PluginControllerRequiredFieldValidationTest extends TestCase
{
    private function validate(PluginInterface $instance, array $settings, array $existingValues): array
    {
        $method = new \ReflectionMethod(PluginController::class, 'validateRequiredFields');
        /** @var array<int, string> $result */
        $result = $method->invoke(null, $instance, $settings, $existingValues);
        return $result;
    }

    private function fakePlugin(array $fields): PluginInterface
    {
        return new class ($fields) implements PluginInterface {
            public function __construct(private array $fieldsData)
            {
            }

            public static function metadata(): array
            {
                return ['name' => 'Fake', 'slug' => 'fake', 'version' => '1.0.0', 'description' => '', 'author' => '', 'type' => 'gateway'];
            }

            public function capabilities(): array
            {
                return [];
            }

            public function register(EventManager $events, Container $container): void
            {
            }

            public function boot(Container $container): void
            {
            }

            public function deactivate(Container $container): void
            {
            }

            public function uninstall(Container $container): void
            {
            }

            public function fields(): array
            {
                return $this->fieldsData;
            }
        };
    }

    public function testFlagsMissingNonPasswordRequiredField(): void
    {
        $plugin = $this->fakePlugin([
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
        ]);

        $missing = $this->validate($plugin, ['api_key' => ''], []);

        $this->assertSame(['API Key'], $missing);
    }

    public function testDoesNotFlagRequiredPasswordFieldBlankWhenAlreadyStored(): void
    {
        $plugin = $this->fakePlugin([
            ['name' => 'secret', 'label' => 'Secret', 'type' => 'password', 'required' => true],
        ]);

        $missing = $this->validate($plugin, ['secret' => ''], ['secret' => 'already-configured-value']);

        $this->assertSame([], $missing);
    }

    public function testFlagsRequiredPasswordFieldBlankWithNoExistingValue(): void
    {
        $plugin = $this->fakePlugin([
            ['name' => 'secret', 'label' => 'Secret', 'type' => 'password', 'required' => true],
        ]);

        $missing = $this->validate($plugin, ['secret' => ''], []);

        $this->assertSame(['Secret'], $missing);
    }

    public function testDoesNotFlagNonRequiredBlankField(): void
    {
        $plugin = $this->fakePlugin([
            ['name' => 'optional_note', 'label' => 'Optional Note', 'type' => 'text', 'required' => false],
        ]);

        $missing = $this->validate($plugin, ['optional_note' => ''], []);

        $this->assertSame([], $missing);
    }

    public function testFallsBackToFieldNameWhenNoLabelSet(): void
    {
        $plugin = $this->fakePlugin([
            ['name' => 'api_key', 'type' => 'text', 'required' => true],
        ]);

        $missing = $this->validate($plugin, ['api_key' => ''], []);

        $this->assertSame(['api_key'], $missing);
    }

    public function testAllRequiredFieldsPresentReturnsEmptyArray(): void
    {
        $plugin = $this->fakePlugin([
            ['name' => 'public_key', 'label' => 'Public Key', 'type' => 'text', 'required' => true],
            ['name' => 'private_key', 'label' => 'Private Key', 'type' => 'password', 'required' => true],
        ]);

        $missing = $this->validate($plugin, ['public_key' => 'pk_123', 'private_key' => 'sk_456'], []);

        $this->assertSame([], $missing);
    }
}
