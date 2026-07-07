<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Service\System\Logger;

/**
 * Regression test for a real bug found while live-verifying the admin-renderer-abstraction
 * fallback path: config/services.php's Logger::class singleton passed only the logs directory
 * as the constructor's first positional argument, which Logger::__construct(string $channel,
 * ?string $logDir) binds to $channel (not $logDir), silently corrupting every log file path
 * written via the container-resolved Logger and causing file_put_contents() to fail on every
 * write. Direct `new Logger('test', $dir)` construction (used elsewhere in this test suite)
 * never exercised this binding, so no existing test caught it.
 */
final class LoggerServiceBindingTest extends IntegrationTestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $this->logDir = dirname(__DIR__, 2) . '/storage/logs';
    }

    private function removeLogFile(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function testContainerResolvedLoggerWritesToAppChannelFile(): void
    {
        $expectedFile = $this->logDir . '/app-' . date('Y-m-d') . '.log';
        $this->removeLogFile($expectedFile);

        $container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($container);
        $container->instance(Database::class, Database::getInstance());

        $logger = $container->get(Logger::class);
        $this->assertInstanceOf(Logger::class, $logger);

        $marker = 'logger-binding-regression-' . bin2hex(random_bytes(6));
        $logger->warning($marker);

        $this->assertFileExists($expectedFile, 'Container-resolved Logger did not write to the expected app-<date>.log file.');
        $this->assertStringContainsString($marker, (string) file_get_contents($expectedFile));

        $this->removeLogFile($expectedFile);
    }
}
