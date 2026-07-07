<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DomainControllerTypeWhitelistTest extends TestCase
{
    public function testTypeWhitelistSourceHasNoAdminEntry(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Controller/Admin/DomainController.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("['checkout', 'admin', 'api']", $source);
        $this->assertStringContainsString("['checkout', 'api']", $source);
    }
}
