<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use OwnPay\Security\Authenticator;
use PHPUnit\Framework\TestCase;

/**
 * F6 — TOTP replay prevention via verifyCodeWithReplayGuard().
 */
final class AuthenticatorReplayTest extends TestCase
{
    private Authenticator $ga;
    private string $secret;

    protected function setUp(): void
    {
        $this->ga = new Authenticator();
        // 16-byte (128-bit) base32 secret
        $this->secret = $this->ga->createSecret(128);
    }

    public function test_first_verify_returns_positive_window(): void
    {
        $window = 12345678;
        $code   = $this->ga->getCode($this->secret, $window);

        $matched = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code, lastUsedWindow: 0, discrepancy: 0, currentTimeSlice: $window
        );

        $this->assertSame($window, $matched, 'First verify should return the matched window index');
    }

    public function test_replay_within_window_is_rejected(): void
    {
        $window = 12345678;
        $code   = $this->ga->getCode($this->secret, $window);

        // Simulate first successful use
        $first = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code, lastUsedWindow: 0, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame($window, $first);

        // Replay: same code, same window — must reject (caller persists $first)
        $replay = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code, lastUsedWindow: $first, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame(-1, $replay, 'Replay of same code/window must be rejected');
    }

    public function test_next_window_code_is_accepted(): void
    {
        $window  = 12345678;
        $code1   = $this->ga->getCode($this->secret, $window);
        $code2   = $this->ga->getCode($this->secret, $window + 1);

        // Consume window
        $first = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code1, lastUsedWindow: 0, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame($window, $first);

        // 30 sec later — new code from new window should be accepted
        $next = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code2, lastUsedWindow: $first, discrepancy: 2, currentTimeSlice: $window + 1
        );
        $this->assertSame($window + 1, $next);
    }

    public function test_invalid_code_returns_minus_one(): void
    {
        $matched = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, '000000', lastUsedWindow: 0, discrepancy: 0, currentTimeSlice: 12345678
        );
        $this->assertSame(-1, $matched);
    }

    public function test_short_code_returns_minus_one(): void
    {
        $matched = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, '123', lastUsedWindow: 0
        );
        $this->assertSame(-1, $matched);
    }

    public function test_window_lookback_within_discrepancy_is_accepted(): void
    {
        // Code from 30s ago should still verify within ±2 discrepancy
        $window = 12345678;
        $oldCode = $this->ga->getCode($this->secret, $window - 1);

        $matched = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $oldCode, lastUsedWindow: 0, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame($window - 1, $matched);
    }
}
