<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Controller\Install\InstallerController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for templates/install/step1.php. Added while removing PHPStan errors
 * ("offset access on mixed" / "htmlspecialchars expects string, mixed given") caused by the
 * template trusting each $requirements row's shape without narrowing - PHPStan can't trace the
 * array-shape PHPDoc on InstallerController::checkRequirements() across the extract()/include
 * boundary used to render raw PHP templates. Locks in that the template still renders correctly
 * for well-formed rows and degrades gracefully (fallback text, no PHP warnings) for malformed ones.
 */
final class InstallerStep1TemplateTest extends TestCase
{
    private function renderStep1(array $requirements): string
    {
        $reflection = new ReflectionClass(InstallerController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $rootDirProp = $reflection->getProperty('rootDir');
        $rootDirProp->setAccessible(true);
        $rootDirProp->setValue($controller, dirname(__DIR__, 2));

        $method = $reflection->getMethod('renderPhpTemplate');
        $method->setAccessible(true);

        set_error_handler(function (int $errno, string $errstr) {
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            return $method->invoke($controller, 'install/step1.php', ['requirements' => $requirements]);
        } finally {
            restore_error_handler();
        }
    }

    public function testRendersWellFormedRequirementsWithoutWarnings(): void
    {
        $html = $this->renderStep1([
            ['name' => 'PHP >= 8.2', 'required' => '8.2', 'current' => '8.3.28', 'ok' => true],
            ['name' => 'PDO Extension', 'required' => 'enabled', 'current' => 'missing', 'ok' => false],
        ]);

        $this->assertStringContainsString('PHP &gt;= 8.2', $html);
        $this->assertStringContainsString('PDO Extension', $html);
        $this->assertStringContainsString('1 requirement failed', $html);
    }

    public function testMalformedRowsFallBackGracefullyWithoutWarnings(): void
    {
        $html = $this->renderStep1([
            ['ok' => true], // missing name/current/required entirely
            'not-an-array-row',
            ['name' => 'Ext', 'required' => 'x', 'current' => 'y', 'ok' => true],
        ]);

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('Not found', $html);
        $this->assertStringContainsString('Ext', $html);
    }

    public function testAllPassingShowsContinueButton(): void
    {
        $html = $this->renderStep1([
            ['name' => 'PHP', 'required' => '8.2', 'current' => '8.3', 'ok' => true],
        ]);

        $this->assertStringContainsString('Continue to Database Setup', $html);
    }
}
