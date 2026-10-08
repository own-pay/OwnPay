<?php

declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Container;
use OwnPay\Controller\Admin\SystemUpdateController;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Repository\UpdateHistoryRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Update\BackupService;
use OwnPay\Update\HealthChecker;
use OwnPay\Update\MaintenanceMode;
use OwnPay\Update\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Records whether the update service was actually invoked, so the test can prove
 * the superadmin gate runs before any code-replacing work starts.
 */
final class SpyUpdateService extends UpdateService
{
    public bool $executed = false;
    /** @var array<int, string> */
    public array $executedVersions = [];

    public function execute(string $version): array
    {
        $this->executed = true;
        $this->executedVersions[] = $version;
        return ['success' => true];
    }
}

/**
 * Installing a system update replaces application code on disk, so it must be
 * restricted to superadmins - not merely to a role holding `system.update`.
 *
 * @see https://github.com/own-pay/OwnPay/issues/616
 */
final class SystemUpdateInstallAuthorizationTest extends TestCase
{
    private SpyUpdateService $updater;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->updater = new SpyUpdateService(
            $this->createMock(BackupService::class),
            $this->createMock(HealthChecker::class),
            $this->createMock(MaintenanceMode::class),
            $this->createMock(UpdateHistoryRepository::class),
            new EventManager()
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function controller(): SystemUpdateController
    {
        return new SystemUpdateController(
            new Container(),
            new AdminSession(),
            $this->updater,
            new SettingsRepository($this->createMock(Database::class)),
            new UpdateHistoryRepository($this->createMock(Database::class))
        );
    }

    private function installRequest(bool $json = false): Request
    {
        $server = [
            'REQUEST_URI'    => '/admin/system-update/apply',
            'REQUEST_METHOD' => 'POST',
        ];
        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }

        return new Request([], ['version' => '9.9.9'], $server);
    }

    public function testInstallIsRejectedForNonSuperadminJsonCaller(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['is_superadmin'] = false;

        $response = $this->controller()->install($this->installRequest(true));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->updater->executed, 'UpdateService::execute() must not run for a non-superadmin.');
    }

    public function testNonSuperadminBrowserCallerIsRedirectedAndNotExecuted(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['is_superadmin'] = false;

        $response = $this->controller()->install($this->installRequest());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->updater->executed);
    }

    public function testInstallIsRejectedWhenSuperadminFlagIsAbsent(): void
    {
        $_SESSION['auth_user_id'] = 42;
        unset($_SESSION['is_superadmin']);

        $response = $this->controller()->install($this->installRequest(true));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->updater->executed, 'A missing is_superadmin flag must fail closed.');
    }

    public function testInstallIsRejectedForUnauthenticatedCaller(): void
    {
        $_SESSION = [];

        $response = $this->controller()->install($this->installRequest(true));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->updater->executed);
    }

    public function testSuperadminReachesUpdateService(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['is_superadmin'] = true;

        $this->controller()->install($this->installRequest());

        $this->assertTrue($this->updater->executed);
        $this->assertSame(['9.9.9'], $this->updater->executedVersions);
    }
}
