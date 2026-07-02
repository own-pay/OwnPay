<?php

declare(strict_types=1);

namespace Tests\Security;

use OwnPay\Security\Authenticator;
use PHPUnit\Framework\TestCase;

final class AuthenticatorReplayTest extends TestCase
{
    private Authenticator $ga;
    private string $secret;

    protected function setUp(): void
    {
        $this->ga = new Authenticator();
        $this->secret = $this->ga->createSecret(128);
    }

    public function test_first_verify_returns_positive_window(): void
    {
        $window = 12345678;
        $code = $this->ga->getCode($this->secret, $window);

        $matched = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code, lastUsedWindow: 0, discrepancy: 0, currentTimeSlice: $window
        );

        $this->assertSame($window, $matched, 'First verify should return the matched window index');
    }

    public function test_replay_within_window_is_rejected(): void
    {
        $window = 12345678;
        $code = $this->ga->getCode($this->secret, $window);

        $first = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code, lastUsedWindow: 0, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame($window, $first);

        // Same code + same window - must reject
        $replay = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code, lastUsedWindow: $first, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame(-1, $replay, 'Replay of same code/window must be rejected');
    }

    public function test_next_window_code_is_accepted(): void
    {
        $window = 12345678;
        $code1 = $this->ga->getCode($this->secret, $window);
        $code2 = $this->ga->getCode($this->secret, $window + 1);

        $first = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code1, lastUsedWindow: 0, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame($window, $first);

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
        $window = 12345678;
        $oldCode = $this->ga->getCode($this->secret, $window - 1);

        $matched = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $oldCode, lastUsedWindow: 0, discrepancy: 2, currentTimeSlice: $window
        );
        $this->assertSame($window - 1, $matched);
    }

    public function test_default_discrepancy_limit(): void
    {
        $window = 12345678;

        // Default discrepancy accepts 1-step lookback (30s ago)
        $code1 = $this->ga->getCode($this->secret, $window - 1);
        $matched1 = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code1, lastUsedWindow: 0, currentTimeSlice: $window
        );
        $this->assertSame($window - 1, $matched1);

        // Default discrepancy rejects 2-step lookback (60s ago)
        $code2 = $this->ga->getCode($this->secret, $window - 2);
        $matched2 = $this->ga->verifyCodeWithReplayGuard(
            $this->secret, $code2, lastUsedWindow: 0, currentTimeSlice: $window
        );
        $this->assertSame(-1, $matched2);
    }
}
