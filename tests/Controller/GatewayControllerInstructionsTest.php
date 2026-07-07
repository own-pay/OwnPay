<?php

declare(strict_types=1);

namespace Tests\Controller;

use OwnPay\Controller\Admin\GatewayController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression test for GitHub issue #21 bug 2: the manual-gateway edit form re-encodes its
 * "Payment Instructions" JSON on every save because the GET handler renders the raw stored
 * JSON straight into the textarea instead of decoding it to plain text first.
 */
final class GatewayControllerInstructionsTest extends TestCase
{
    private GatewayController $controller;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(GatewayController::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
    }

    private function invokeDecodeGatewayForEdit(array $gateway): array
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('decodeGatewayForEdit');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $gateway);
    }

    public function testEditFormDataDecodesStoredJsonInstructionsToPlainText(): void
    {
        $gateway = [
            'instructions' => json_encode(['steps' => ['Enter the Number: 01888888888']]),
            'input_fields' => '[]',
            'colors'       => '{}',
        ];

        $decoded = $this->invokeDecodeGatewayForEdit($gateway);

        $this->assertSame('Enter the Number: 01888888888', $decoded['instructions']);
    }

    public function testEditFormDataSurvivesMultipleRoundTripsWithoutNesting(): void
    {
        // Simulates what buildInstructionsJson() would store after the decoded text is re-saved.
        $reflection = new ReflectionClass($this->controller);
        $buildJson = $reflection->getMethod('buildInstructionsJson');
        $buildJson->setAccessible(true);

        $stored = json_encode(['steps' => ['Enter the Number: 01888888888']]);

        for ($i = 0; $i < 3; $i++) {
            $decoded = $this->invokeDecodeGatewayForEdit(['instructions' => $stored, 'input_fields' => '[]', 'colors' => '{}']);
            $stored = $buildJson->invoke($this->controller, $decoded['instructions']);
        }

        $this->assertSame(
            '{"steps":["Enter the Number: 01888888888"]}',
            $stored,
            'Repeated edit/save cycles must not compound JSON nesting'
        );
    }
}
