<?php

declare(strict_types=1);

namespace Tests\Security;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\LoginAttemptRepository;
use OwnPay\Repository\MerchantUserRepository;
use OwnPay\Security\Authenticator;
use OwnPay\Service\System\EnvironmentService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the lockout escalation contract: attempts submitted while an account
 * is already locked must count toward the window, and the exponential window
 * must stay clamped no matter how many failures accumulate.
 */
final class AccountLockoutEscalationTest extends TestCase
{
    /**
     * @var array<string, string|null> Original $_ENV values keyed by name; null marks a key that was absent.
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        EnvironmentService::clearCache();
        // Prevent settings persisted by a previous test (or a previous run)
        // from leaking into this one via the shared database.
        EnvironmentService::delete('MAX_LOGIN_ATTEMPTS');
        EnvironmentService::delete('LOCKOUT_DURATION');
        EnvironmentService::clearCache();
        $this->originalEnv = [
            'MAX_LOGIN_ATTEMPTS' => $_ENV['MAX_LOGIN_ATTEMPTS'] ?? null,
            'LOCKOUT_DURATION' => $_ENV['LOCKOUT_DURATION'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        // Remove any settings this test persisted so they cannot leak into
        // other tests or subsequent runs sharing the same database.
        EnvironmentService::delete('MAX_LOGIN_ATTEMPTS');
        EnvironmentService::delete('LOCKOUT_DURATION');
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        EnvironmentService::clearCache();
        parent::tearDown();
    }

    /**
     * Builds a repository whose aggregate query always returns the canned row.
     *
     * @param array{fails: int, since_last: int} $row
     */
    private function repositoryReturning(array $row): LoginAttemptRepository
    {
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturn($row);
        return new LoginAttemptRepository($db);
    }

    public function testLockoutWindowCountsDownWhileNoFurtherAttemptsArrive(): void
    {
        $repo = $this->repositoryReturning(['fails' => 5, 'since_last' => 120]);

        $this->assertSame(180, $repo->lockoutSecondsRemaining('victim@example.test', '203.0.113.10'));
    }

    public function testEachFailureBatchDoublesTheLockoutWindowUpToTheCap(): void
    {
        $expected = [
            5  => 300,
            10 => 600,
            15 => 1200,
            20 => 1800,
            25 => 1800,
        ];

        foreach ($expected as $fails => $window) {
            $repo = $this->repositoryReturning(['fails' => $fails, 'since_last' => 0]);
            $this->assertSame(
                $window,
                $repo->lockoutSecondsRemaining('victim@example.test', '203.0.113.10'),
                "failures={$fails} should leave {$window}s on the clock"
            );
        }
    }

    public function testSustainedFailuresNeverOverflowTheWindowIntoNoLockout(): void
    {
        // Pre-fix, 2 ** (tier - 1) overflowed the int cast at this scale and the
        // negative result slipped under the cap check, returning 0 - no lockout.
        $repo = $this->repositoryReturning(['fails' => 100000, 'since_last' => 0]);

        $this->assertSame(1800, $repo->lockoutSecondsRemaining('victim@example.test', '203.0.113.10'));
        $this->assertSame(1800, $repo->lockoutSecondsRemainingByEmail('victim@example.test', 300, 15));
    }

    public function testAttemptsSubmittedWhileLockedAreRecordedAndEscalateTheReportedWindow(): void
    {
        // (email, IP) counter: tier-2 window (600s) almost spent when the
        // rejected attempt arrives. The per-email counter stays below its 3x
        // threshold so the (email, IP) side alone drives the lockout here.
        $attempt = $this->attemptWhileLocked(['fails' => 10, 'since_last' => 500]);

        $this->assertFalse($attempt['result']['success']);
        $inserted = $attempt['inserted'];
        $this->assertIsArray($inserted, 'an attempt made while locked must still be recorded');
        $this->assertSame('victim@example.test', $inserted['email']);
        $this->assertSame('203.0.113.10', $inserted['ip_address']);
        $this->assertSame(0, $inserted['success']);
        // The rejection reports the re-armed window (600s from the attempt just
        // recorded) rather than the near-expired remainder that preceded it.
        $this->assertStringContainsString('10 minute', (string) ($attempt['result']['error'] ?? ''));
    }

    public function testTenthFailureWhileLockedEscalatesTheWindowFromFiveToTenMinutes(): void
    {
        // Nine failures sit at the top of the tier-1 window (5 minutes), one
        // short of the batch boundary, so the rejected attempt is the tenth
        // failure: the re-counted window must double to tier 2 (10 minutes).
        $attempt = $this->attemptWhileLocked(['fails' => 9, 'since_last' => 0]);

        $this->assertFalse($attempt['result']['success']);
        $this->assertStringContainsString(
            '10 minute',
            (string) ($attempt['result']['error'] ?? ''),
            'the tenth failure must cross the batch boundary and double the reported window'
        );
    }

    /**
     * Helper to run an authentication attempt against a canned IP failure counter.
     *
     * @param array{fails: int, since_last: int} $ipCounter
     * @param int|null $maxAttempts Optional constructor override.
     * @param int|null $lockoutDuration Optional constructor override.
     * @return array{success: bool, user?: array<string, mixed>, error?: string, requires_2fa?: bool}
     */
    private function attemptWithCounter(array $ipCounter, ?int $maxAttempts = null, ?int $lockoutDuration = null): array
    {
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql) use (&$ipCounter): array {
                if (str_contains($sql, 'ip_address = :ip')) {
                    return $ipCounter;
                }
                return ['fails' => 1, 'since_last' => 500];
            }
        );
        $db->method('insert')->willReturnCallback(
            static function () use (&$ipCounter): string {
                $ipCounter['fails'] += 1;
                $ipCounter['since_last'] = 0;
                return '1';
            }
        );

        $auth = new Authenticator(
            new MerchantUserRepository($db),
            new LoginAttemptRepository($db),
            new EventManager(),
            null,
            $maxAttempts,
            $lockoutDuration
        );

        return $auth->attempt('victim@example.test', 'wrong-password', '203.0.113.10', 'PHPUnit');
    }

    public function testLockoutConfigHonorsEnvironmentService(): void
    {
        EnvironmentService::set('MAX_LOGIN_ATTEMPTS', '2');
        EnvironmentService::set('LOCKOUT_DURATION', '600');

        $result = $this->attemptWithCounter(['fails' => 2, 'since_last' => 0]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('10 minute', (string) ($result['error'] ?? ''));
    }

    public function testConstructorInjectedLockoutConfigOverridesEnvironmentService(): void
    {
        EnvironmentService::set('MAX_LOGIN_ATTEMPTS', '10');
        EnvironmentService::set('LOCKOUT_DURATION', '1800');

        $result = $this->attemptWithCounter(['fails' => 2, 'since_last' => 0], 2, 120);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2 minute', (string) ($result['error'] ?? ''));
    }

    public function testLockoutConfigSafelyDefaultsWhenConfigIsInvalidOrNonPositive(): void
    {
        EnvironmentService::set('MAX_LOGIN_ATTEMPTS', '0');
        EnvironmentService::set('LOCKOUT_DURATION', '-50');

        $result = $this->attemptWithCounter(['fails' => 5, 'since_last' => 0]);

        $this->assertFalse($result['success']);
        // Default base window is 300s (5 minutes)
        $this->assertStringContainsString('5 minute', (string) ($result['error'] ?? ''));
    }

    public function testLockoutConfigSafelyDefaultsWhenConfigIsNonNumericOrFractional(): void
    {
        EnvironmentService::set('MAX_LOGIN_ATTEMPTS', '1.5');
        EnvironmentService::set('LOCKOUT_DURATION', 'not-a-number');

        $result = $this->attemptWithCounter(['fails' => 5, 'since_last' => 0]);

        $this->assertFalse($result['success']);
        // Fractional numeric strings and non-numeric strings fall back to defaults (5 attempts, 300s / 5 min)
        $this->assertStringContainsString('5 minute', (string) ($result['error'] ?? ''));
    }

    /**
     * Runs a login attempt while the account is already locked, against canned
     * counters. Recording the rejected attempt bumps the (email, IP) failure
     * count and re-arms its timestamp, mirroring the insert-then-recount the
     * real lockout path performs. The per-email counter stays below its 3x
     * threshold so the (email, IP) side alone drives the lockout.
     *
     * @param array{fails: int, since_last: int} $ipCounter
     * @return array{result: array<string, mixed>, inserted: array<string, mixed>|null}
     */
    private function attemptWhileLocked(array $ipCounter): array
    {
        EnvironmentService::delete('MAX_LOGIN_ATTEMPTS');
        EnvironmentService::delete('LOCKOUT_DURATION');
        EnvironmentService::clearCache();
        $_ENV['MAX_LOGIN_ATTEMPTS'] = 5;
        $_ENV['LOCKOUT_DURATION'] = 300;

        $inserted = null;
        $db = $this->createMock(Database::class);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql) use (&$ipCounter): array {
                if (str_contains($sql, 'ip_address = :ip')) {
                    return $ipCounter;
                }
                return ['fails' => 4, 'since_last' => 500];
            }
        );
        $db->method('insert')->willReturnCallback(
            static function (string $sql, array $params) use (&$ipCounter, &$inserted): string {
                $inserted = $params;
                $ipCounter['fails'] += 1;
                $ipCounter['since_last'] = 0;
                return '1';
            }
        );

        $auth = new Authenticator(
            new MerchantUserRepository($db),
            new LoginAttemptRepository($db),
            new EventManager()
        );

        return [
            'result' => $auth->attempt('victim@example.test', 'wrong-password', '203.0.113.10', 'PHPUnit'),
            'inserted' => $inserted,
        ];
    }
}
