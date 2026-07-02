<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Cron\CronJobRunner;
use OwnPay\Event\EventManager;
use OwnPay\Service\System\Logger;

class CronJobRunnerTest extends TestCase
{
    private EventManager $events;
    private Logger $logger;
    private CronJobRunner $runner;

    protected function setUp(): void
    {
        $this->events = new EventManager();
        $this->logger = new Logger('test-cron');
        $this->runner = new CronJobRunner($this->events, $this->logger);
    }

    public function testRegisterAndGetJobs(): void
    {
        $dummyJob = new class {
            public function run(): string {
                return 'success';
            }
        };

        $this->runner->register('TestJob', $dummyJob, 'every_minute');

        $jobs = $this->runner->getJobs();
        $this->assertArrayHasKey('TestJob', $jobs);
        $this->assertSame('every_minute', $jobs['TestJob']['schedule']);
        $this->assertSame($dummyJob, $jobs['TestJob']['job']);
    }

    public function testRunJobSuccess(): void
    {
        $called = false;
        $dummyJob = new class($called) {
            private $calledRef;
            public function __construct(&$called) {
                $this->calledRef = &$called;
            }
            public function run(): string {
                $this->calledRef = true;
                return 'all-good';
            }
        };

        $this->runner->register('GoodJob', $dummyJob, 'hourly');
        $result = $this->runner->runJob('GoodJob');

        $this->assertTrue($called);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('all-good', $result['result']);
        $this->assertGreaterThanOrEqual(0, $result['duration']);

        $lastRun = $this->runner->getLastRunTime('GoodJob');
        $this->assertNotNull($lastRun);
        $this->assertLessThanOrEqual(time(), $lastRun);
    }

    public function testRunJobFailure(): void
    {
        $dummyJob = new class {
            public function run(): void {
                throw new \RuntimeException('Something went wrong');
            }
        };

        $this->runner->register('BadJob', $dummyJob, 'daily');
        $result = $this->runner->runJob('BadJob');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Something went wrong', $result['error']);
        $this->assertGreaterThanOrEqual(0, $result['duration']);
    }

    public function testRunJobThrowsOnUnregistered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cron job not registered: MissingJob');

        $this->runner->runJob('MissingJob');
    }

    public function testCronControllerRouteVerification(): void
    {
        $container = new \OwnPay\Container();
        $container->instance('config.app', [
            'cron_secret' => 'test-secret-123'
        ]);

        $events = new \OwnPay\Event\EventManager();
        $logger = new \OwnPay\Service\System\Logger('test-cron');
        $runner = new \OwnPay\Cron\CronJobRunner($events, $logger);
        $container->instance(\OwnPay\Cron\CronJobRunner::class, $runner);

        $controller = new \OwnPay\Controller\Page\CronController($container);

        $req = new \OwnPay\Http\Request(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/cron/test-secret-123'
            ]
        );
        $req->setRouteParams(['secret' => 'test-secret-123']);

        $response = $controller->run($req);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('OK:', (string)$response->getBody());
    }
}
