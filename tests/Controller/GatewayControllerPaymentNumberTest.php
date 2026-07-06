<?php

declare(strict_types=1);

namespace Tests\Controller;

use OwnPay\Controller\Admin\GatewayController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression for the checkout-gateway-bugs spec bug 3: the admin manual-gateway form had no
 * dedicated field for "the number customers should send money to" - the checkout JS guessed at
 * it by scanning the generic Input Fields builder for one named/typed "payment_number", which
 * nothing in the admin UI ever actually created. `buildGatewayRecord()` now picks up a dedicated
 * top-level `payment_number` POST field directly.
 */
final class GatewayControllerPaymentNumberTest extends TestCase
{
    private GatewayController $controller;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(GatewayController::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
    }

    private function invokeBuildGatewayRecord(array $data): array
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('buildGatewayRecord');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $data);
    }

    public function testBuildGatewayRecordIncludesSubmittedPaymentNumber(): void
    {
        $record = $this->invokeBuildGatewayRecord([
            'name'           => 'bKash Personal',
            'payment_number' => '01711-XXXXXX',
        ]);

        $this->assertSame('01711-XXXXXX', $record['payment_number']);
    }

    public function testBuildGatewayRecordDefaultsToEmptyStringWhenNotSubmitted(): void
    {
        $record = $this->invokeBuildGatewayRecord(['name' => 'bKash Personal']);

        $this->assertSame('', $record['payment_number']);
    }

    private function invokeAccountFromTemplate(array $template, array $account): array
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('accountFromTemplate');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $template, $account);
    }

    public function testAccountFromTemplateCarriesPaymentNumberForwardWhenAccountHasNone(): void
    {
        $record = $this->invokeAccountFromTemplate(
            ['slug' => 'bkash', 'name' => 'bKash', 'payment_number' => '01711-XXXXXX'],
            []
        );

        $this->assertSame('01711-XXXXXX', $record['payment_number']);
    }

    public function testAccountFromTemplateLetsAccountOverridePaymentNumber(): void
    {
        $record = $this->invokeAccountFromTemplate(
            ['slug' => 'bkash', 'name' => 'bKash', 'payment_number' => '01711-XXXXXX'],
            ['payment_number' => '01999-YYYYYY']
        );

        $this->assertSame('01999-YYYYYY', $record['payment_number']);
    }
}
